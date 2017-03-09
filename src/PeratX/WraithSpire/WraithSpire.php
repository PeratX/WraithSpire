<?php

/**
 * WraithSpire
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PeratX
 */

namespace PeratX\WraithSpire;

use PeratX\SimpleFramework\Console\Logger;
use PeratX\SimpleFramework\Console\TextFormat;
use PeratX\SimpleFramework\Framework;
use PeratX\SimpleFramework\Module\Module;
use PeratX\SimpleFramework\Module\ModuleDependencyResolver;
use PeratX\SimpleFramework\Util\Config;
use PeratX\SimpleFramework\Util\Util;

class WraithSpire extends Module implements ModuleDependencyResolver{
	private $resolved = false;
	private $database;
	/** @var Config */
	private $config;

	public function preLoad(): bool{
		if($this->getInfo()->getAPILevel() > Framework::API_LEVEL){
			throw new \Exception("Plugin requires API Level: " . $this->getInfo()->getAPILevel() . " Current API Level: " . Framework::API_LEVEL);
		}
		$this->getFramework()->registerModuleDependencyResolver($this);

		@mkdir($this->getDataFolder());

		$this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
			"auto-update-database" => true,
		]);

		$this->database = $this->getDataFolder() . "database.yml";
		if(!file_exists($this->database) or
			!file_exists($this->getDataFolder() . "version") or
			($this->config->get("auto-update-database", true) and
				Util::getURL("https://raw.githubusercontent.com/PeratX/WraithSpireDatabase/master/version") != file_get_contents($this->getDataFolder() . "version"))){

			Logger::info(TextFormat::AQUA . "Downloading WraithSpire Module Database file...");
			file_put_contents($this->database, Util::getURL("https://raw.githubusercontent.com/PeratX/WraithSpireDatabase/master/database.yml"));
		}
		$this->database = (new Config($this->database, Config::YAML))->getAll();

		return true;
	}

	public function load(){
	}

	public function unload(){
	}

	public function doTick(int $currentTick){
		if(!$this->resolved){
			$this->resolveDependency($this);
			$this->resolved = true;
		}
	}

	public function downloadDependency(string $name, string $version){

	}

	public function resolveDependency(Module $module){
		$dependencies = $module->getInfo()->getDependency();
		foreach($dependencies as $dependency){
			$name = $dependency["name"];
			if(strstr($name, "/")){
				$name = explode("/", $name, 2);
				$name = end($name);
			}
			$version = explode(".", $dependency["version"]);
			$error = false;
			if(count($version) != 3){
				$error = true;
			}
			if(($module = $this->framework->getModule($name)) instanceof Module){
				$targetVersion = explode(".", $module->getInfo()->getVersion());
				if(count($targetVersion) != 3){
					$error = true;
				}

				if($version[0] != $targetVersion[0]){
					$error = true;
				}elseif($version[1] > $targetVersion[1]){
					$error = true;
				}elseif($version[1] == $targetVersion[1] and $version[2] > $targetVersion[2]){
					$error = true;
				}
			}else{
				$error = true;
			}
			if($error == true){
				Logger::error("Module " . '"' . $this->getInfo()->getName() . '"' . " requires dependency module " . '"' . $name . '"' . " version " . $dependency["version"] . ". Resolving dependency...");
				$this->downloadDependency($dependency["name"], $dependency["version"]);
				return false;
			}
		}
		return true;
	}
}