<?php
/**
 * Based on the great plugin 'BetaTester' made by shoghicp
 */
namespace GenisysPro\BetaTester;

use pocketmine\network\protocol\Info;
use pocketmine\plugin\PluginBase;

class BetaTester extends PluginBase{

	const CURRENT_PROTOCOL = 105;
	const TARGET_PROTOCOL = 102;

	const CURRENT_MINECRAFT_VERSION_NETWORK = "1.0";

	public function onEnable(){
		$this->saveDefaultConfig();

		$port = (int) $this->getConfig()->get("port");
		if($port === $this->getServer()->getPort()){
			$this->getLogger()->error("A different port must be set in config.yml");
			return;
		}

		if(Info::CURRENT_PROTOCOL !== self::TARGET_PROTOCOL){
			$this->getLogger()->error("Current protocol is different than the target protocol!");
			return;
		}

		$this->getLogger()->info("MC:PE server ".$this->getDescription()->getVersion()." is now starting on port $port");
		$interface = new NewInterface($this->getServer(), $port);
		$this->getServer()->getNetwork()->registerInterface($interface);
	}
}