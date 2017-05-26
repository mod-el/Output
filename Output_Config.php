<?php
namespace Model;

class Output_Config extends Module_Config {
	/**
	 * Creates (or wipes) the cache metadata file
	 *
	 * @return bool
	 */
	function makeCache(){
		$basePath = INCLUDE_PATH.'model'.DIRECTORY_SEPARATOR.'Output'.DIRECTORY_SEPARATOR.'data';
		if(!is_dir($basePath))
			mkdir($basePath);
		if(!is_dir($basePath.DIRECTORY_SEPARATOR.'cache'))
			mkdir($basePath.DIRECTORY_SEPARATOR.'cache');

		file_put_contents($basePath.DIRECTORY_SEPARATOR.'cache.php', '<?php
$this->cache = [];
');
		return true;
	}
}