<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

namespace GenisysPro\BetaTester;

use GenisysPro\BetaTester\protocol\LoginPacket;
use pocketmine\network\AdvancedSourceInterface;
use pocketmine\event\player\PlayerCreationEvent;
use pocketmine\network\protocol\BatchPacket;
use pocketmine\network\protocol\DataPacket;
use pocketmine\network\protocol\Info as ProtocolInfo;
use pocketmine\Player;
use pocketmine\Server;
use raklib\protocol\EncapsulatedPacket;
use raklib\RakLib;
use raklib\server\RakLibServer;
use raklib\server\ServerHandler;
use raklib\server\ServerInstance;
use pocketmine\network\Network;
use pocketmine\network\CachedEncapsulatedPacket;
use pocketmine\utils\Binary;
use pocketmine\utils\MainLogger;

class NewInterface implements ServerInstance, AdvancedSourceInterface{

	/** @var Server */
	private $server;

	/** @var Network */
	private $network;

	/** @var RakLibServer */
	private $rakLib;

	/** @var Player[] */
	private $players = [];

	/** @var string[] */
	private $identifiers;

	/** @var int[] */
	private $identifiersACK = [];

	/** @var ServerHandler */
	private $interface;

	public function __construct(Server $server, $port = 19133){

		$this->server = $server;
		$this->identifiers = [];

		$this->rakLib = new RakLibServer($this->server->getLogger(), $this->server->getLoader(), $port, $this->server->getIp() === "" ? "0.0.0.0" : $this->server->getIp());
		$this->interface = new ServerHandler($this->rakLib, $this);
	}

	public function setNetwork(Network $network){
		$this->network = $network;
	}

	public function process(){
		$work = false;
		if($this->interface->handlePacket()){
			$work = true;
			while($this->interface->handlePacket()){
			}
		}

		if($this->rakLib->isTerminated()){
			$this->network->unregisterInterface($this);

			throw new \Exception("RakLib Thread crashed");
		}

		return $work;
	}

	public function closeSession($identifier, $reason){
		if(isset($this->players[$identifier])){
			$player = $this->players[$identifier];
			unset($this->identifiers[spl_object_hash($player)]);
			unset($this->players[$identifier]);
			unset($this->identifiersACK[$identifier]);
			$player->close($player->getLeaveMessage(), $reason);
		}
	}

	public function close(Player $player, $reason = "unknown reason"){
		if(isset($this->identifiers[$h = spl_object_hash($player)])){
			unset($this->players[$this->identifiers[$h]]);
			unset($this->identifiersACK[$this->identifiers[$h]]);
			$this->interface->closeSession($this->identifiers[$h], $reason);
			unset($this->identifiers[$h]);
		}
	}

	public function shutdown(){
		$this->interface->shutdown();
	}

	public function emergencyShutdown(){
		$this->interface->emergencyShutdown();
	}

	public function openSession($identifier, $address, $port, $clientID){
		$ev = new PlayerCreationEvent($this, Player::class, Player::class, null, $address, $port);
		$this->server->getPluginManager()->callEvent($ev);
		$class = $ev->getPlayerClass();

		$player = new $class($this, $ev->getClientId(), $ev->getAddress(), $ev->getPort());
		$this->players[$identifier] = $player;
		$this->identifiersACK[$identifier] = 0;
		$this->identifiers[spl_object_hash($player)] = $identifier;
		$this->server->addPlayer($identifier, $player);
	}

	public function processBatch(BatchPacket $packet, Player $p) {
		$str = zlib_decode($packet->payload, 1024 * 1024 * 64); //Max 64MB
		$len = strlen($str);
		$offset = 0;
		try {
			while ($offset < $len) {
				$pkLen = Binary::readInt(substr($str, $offset, 4));
				$offset += 4;

				$buf = substr($str, $offset, $pkLen);
				$offset += $pkLen;

				if (($pk = $this->network->getPacket(ord($buf{0}))) !== null) {
					if ($pk::NETWORK_ID === ProtocolInfo::BATCH_PACKET) {
						throw new \InvalidStateException("Invalid BatchPacket inside BatchPacket");
					}

					if($pk::NETWORK_ID == ProtocolInfo::LOGIN_PACKET) $pk = new LoginPacket();

					$pk->setBuffer($buf, 1);

					$pk->decode();
					if($pk::NETWORK_ID == ProtocolInfo::LOGIN_PACKET) $pk->protocol1 = BetaTester::TARGET_PROTOCOL;
					$p->handleDataPacket($pk);

					if ($pk->getOffset() <= 0) {
						return;
					}
				}
			}
		} catch (\Throwable $e) {
			if (\pocketmine\DEBUG > 1) {
				$logger = $this->server->getLogger();
				if ($logger instanceof MainLogger) {
					$logger->debug("BatchPacket " . " 0x" . bin2hex($packet->payload));
					$logger->logException($e);
				}
			}
		}
	}

	public function handleEncapsulated($identifier, EncapsulatedPacket $packet, $flags){
		if(isset($this->players[$identifier])){
			try{
				if($packet->buffer !== ""){
					$pk = $this->getPacket($packet->buffer);
					if($pk !== null){
						$pk->decode();
						if($pk instanceof BatchPacket) {
							/** @var BatchPacket $pk */
							$this->processBatch($pk, $this->players[$identifier]);
							return;
						}
						$this->players[$identifier]->handleDataPacket($pk);
					}
				}
			}catch(\Throwable $e){
				if(\pocketmine\DEBUG > 1 and isset($pk)){
					$logger = $this->server->getLogger();
					$logger->debug("Packet " . get_class($pk) . " 0x" . bin2hex($packet->buffer));
					$logger->logException($e);
				}

				if(isset($this->players[$identifier])){
					$this->interface->blockAddress($this->players[$identifier]->getAddress(), 5);
				}
			}
		}
	}

	public function blockAddress($address, $timeout = 300){
		$this->interface->blockAddress($address, $timeout);
	}

	public function handleRaw($address, $port, $payload){
		$this->server->handlePacket($address, $port, $payload);
	}

	public function sendRawPacket($address, $port, $payload){
		$this->interface->sendRaw($address, $port, $payload);
	}

	public function notifyACK($identifier, $identifierACK){

	}

	public function setName($name){
		$info = $this->server->getQueryInformation();

		$this->interface->sendOption("name",
			"MCPE;" . addcslashes($name, ";") . ";" .
			BetaTester::CURRENT_PROTOCOL . ";" .
			BetaTester::CURRENT_MINECRAFT_VERSION_NETWORK . ";" .
			$info->getPlayerCount() . ";" .
			$info->getMaxPlayerCount()
		);
	}

	public function setPortCheck($name){
		$this->interface->sendOption("portChecking", (bool) $name);
	}

	public function handleOption($name, $value){
		if($name === "bandwidth"){
			$v = unserialize($value);
			$this->network->addStatistics($v["up"], $v["down"]);
		}
	}

	public function putPacket(Player $player, DataPacket $packet, $needACK = false, $immediate = false){
		if(isset($this->identifiers[$h = spl_object_hash($player)])){
			$identifier = $this->identifiers[$h];
			$pk = null;
			if(!$packet->isEncoded){
				$packet->encode();
			}elseif(!$needACK){
				if(!isset($packet->__encapsulatedPacket)){
					$packet->__encapsulatedPacket = new CachedEncapsulatedPacket;
					$packet->__encapsulatedPacket->identifierACK = null;
					$packet->__encapsulatedPacket->buffer = $packet->buffer;
					$packet->__encapsulatedPacket->reliability = 3;
					$packet->__encapsulatedPacket->orderChannel = 0;
				}
				$pk = $packet->__encapsulatedPacket;
			}

			if(!$immediate and !$needACK and $packet::NETWORK_ID !== ProtocolInfo::BATCH_PACKET
				and Network::$BATCH_THRESHOLD >= 0
				and strlen($packet->buffer) >= Network::$BATCH_THRESHOLD){
				$this->server->batchPackets([$player], [$packet], true);
				return null;
			}

			if($pk === null){
				$pk = new EncapsulatedPacket();
				$pk->buffer = $packet->buffer;
				$packet->reliability = 3;
				$packet->orderChannel = 0;

				if($needACK === true){
					$pk->identifierACK = $this->identifiersACK[$identifier]++;
				}
			}

			$this->interface->sendEncapsulated($identifier, $pk, ($needACK === true ? RakLib::FLAG_NEED_ACK : 0) | ($immediate === true ? RakLib::PRIORITY_IMMEDIATE : RakLib::PRIORITY_NORMAL));

			return $pk->identifierACK;
		}

		return null;
	}

	private function getPacket($buffer){
		$pid = ord($buffer{0});

		if(($data = $this->network->getPacket($pid)) === null){
			return null;
		}
		$data->setBuffer($buffer, 1);

		return $data;
	}
}
