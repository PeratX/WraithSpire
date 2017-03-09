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
use PeratX\SimpleFramework\Module\ModuleInfo;
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

	public function downloadDependency(string $moduleName, string $name, string $version): bool{
		$vendor = "";
		if(strstr($name, "/")){
			$rName = explode("/", $name, 2);
			$name = $rName[1];
			$vendor = $rName[0];
		}
		if($vendor == ""){
			Logger::info(TextFormat::RED . $moduleName . " requires dependency $name does not have a vendor, please contact the author of the module or manually resolve its dependency.");
			return false;
		}
		if(($module = $this->framework->getModule($name)) instanceof Module){
			if($module->getInfo()->getLoadMethod() == ModuleInfo::LOAD_METHOD_SOURCE){
				Logger::info(TextFormat::RED . "Please manually remove the source folder of " . $module->getInfo()->getName() . " then the dependency resolver can download the specifying module.");
				return false;
			}
			rename($module->getFile(), $module->getFile() . ".old");
		}
		if(isset($this->database[$vendor][$name][$version])){
			$link = $this->database[$vendor][$name][$version];
			$fileName = explode("/", $link);
			$fileName = end($fileName);
			self::downloadFile($this->getFramework()->getModulePath() . $fileName, $link);
			return $this->getFramework()->tryloadModule($this->getFramework()->getModulePath() . $fileName);
		}
		return false;
	}

	public static function downloadFile(string $file, string $url){
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36"]);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 86400);//1 Day
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
		curl_setopt($ch, CURLOPT_BUFFERSIZE, 20971520);//20M
		$ret = curl_exec($ch);
		curl_close($ch);

		if($ret != false){
			file_put_contents($file, $ret, FILE_BINARY);
		}
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
			if(($dependencyModule = $this->framework->getModule($name)) instanceof Module){
				$targetVersion = explode(".", $dependencyModule->getInfo()->getVersion());
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
				Logger::error("Module " . '"' . $module->getInfo()->getName() . '"' . " requires dependency module " . '"' . $name . '"' . " version " . $dependency["version"] . ". Resolving dependency...");
				if(!$this->downloadDependency($module->getInfo()->getName(), $dependency["name"], $dependency["version"])){
					return false;
				}
			}
		}
		return true;
	}
}