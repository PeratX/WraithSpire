<?php

/**
 * WraithSpire
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author iTXTech
 * @link https://itxtech.org
 */

namespace PeratX\WraithSpire;

use iTXTech\SimpleFramework\Console\Logger;
use iTXTech\SimpleFramework\Console\TextFormat;
use iTXTech\SimpleFramework\Framework;
use iTXTech\SimpleFramework\Module\Module;
use iTXTech\SimpleFramework\Module\ModuleDependencyResolver;
use iTXTech\SimpleFramework\Module\ModuleInfo;
use iTXTech\SimpleFramework\Util\Config;
use iTXTech\SimpleFramework\Util\Util;

class WraithSpire extends Module implements ModuleDependencyResolver{

	private $resolved = false;

	private $database;

	/** @var Config */
	private $config;

	public function preLoad(): bool{
		if($this->getInfo()->getAPILevel() > Framework::API_LEVEL){
			throw new \Exception("Module requires API Level: " . $this->getInfo()->getAPILevel() . " Current API Level: " . Framework::API_LEVEL);
		}
		$this->getFramework()->registerModuleDependencyResolver($this);

		@mkdir($this->getDataFolder());

		$this->config = new Config($this->getDataFolder() . "config.json", Config::JSON, [
			"remote-database" => "https://raw.githubusercontent.com/PeratX/WraithSpireDatabase/master/",
			"modules" => []
		]);
		$this->database = $this->config->get("remote-database");

		return true;
	}

	public function load(){
	}

	public function unload(){
	}

	public function doTick(int $currentTick){
		if(!$this->resolved){
			$modules = $this->config->get("modules", []);
			if($modules != []){
				Logger::info(TextFormat::AQUA . "Resolving modules required by WraithSpire configuration.");
				$this->resolveDependencies($modules, $this->getName());
			}
			$this->resolved = true;
		}
	}

	private function getModuleData(string $vendor, string $name, string $version){
		$link = $this->database . "$vendor/$name/$version.json";
		$i = 1;
		while(($result = Util::getURL($link)) === false and $i <= 3){
			Logger::alert("Obtaining module data for $vendor/$name version $version failed, retrying $i...");
			$i++;
		}
		if($result == false){
			Logger::alert("Obtaining module data for $vendor/$name version $version failed, please check your network connection.");
			return false;
		}
		if(strstr($result, "404: Not Found")){
			Logger::alert("Not found module data for $vendor/$name version $version .");
			return false;
		}
		return json_decode($result, true);
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
			Logger::info(TextFormat::AQUA . "You must restart this program after resolved dependencies.");
		}
		if(($data = $this->getModuleData($vendor, $name, $version)) !== false){
			if($data["api"] > Framework::API_LEVEL){
				Logger::info("$vendor/$name version $version requires API Level: " . $data["api"] . " Current API Level: " . Framework::API_LEVEL . ". Module may not work properly.");
			}
			$fileName = explode("/", $data["link"]);
			$fileName = end($fileName);
			Logger::info(TextFormat::AQUA . "Downloading module $vendor/$name v$version ...");
			self::downloadFile($this->getFramework()->getModulePath() . $fileName, $data["link"]);
			Logger::info(TextFormat::GREEN . "Module $vendor/$name v$version downloaded. Loading...");
			return $this->getFramework()->tryloadModule($this->getFramework()->getModulePath() . $fileName);
		}
		return false;
	}

	private function resolveDependencies(array $dependencies, string $moduleName): bool{
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
				Logger::info(TextFormat::GOLD . "Resolving dependency " . $name .  " version " . $dependency["version"] . " @ " . $moduleName);
				if(!$this->downloadDependency($moduleName, $dependency["name"], $dependency["version"])){
					return false;
				}
			}
		}
		return true;
	}

	public function resolveDependency(Module $module): bool{
		return $this->resolveDependencies($module->getInfo()->getDependency(), $module->getInfo()->getName());
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
}