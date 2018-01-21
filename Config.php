<?php namespace Model\Output;

use Model\Core\Module_Config;

class Config extends Module_Config
{
	/**
	 * @throws \Model\Core\Exception
	 */
	protected function assetsList()
	{
		$this->addAsset('data', 'cache' . DIRECTORY_SEPARATOR . 'cache.php', function () {
			return '<?php
$this->cache = [];
';
		});
	}

	/**
	 * Creates (or wipes) the cache metadata file
	 *
	 * @return bool
	 */
	function makeCache(): bool
	{
		return (bool)file_put_contents(INCLUDE_PATH . 'model' . DIRECTORY_SEPARATOR . 'Output' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache.php', '<?php
$this->cache = [];
');
	}
}
