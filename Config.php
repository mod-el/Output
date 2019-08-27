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
		return (
			(bool)file_put_contents(INCLUDE_PATH . 'model' . DIRECTORY_SEPARATOR . 'Output' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache.php', "<?php\n\$this->cache = [];\n")
			and $this->delTree(INCLUDE_PATH . 'model' . DIRECTORY_SEPARATOR . 'Output' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache')
		);
	}

	/**
	 * Removes entire directory
	 *
	 * @param string $dir
	 * @return bool
	 */
	private function delTree(string $dir): bool
	{
		if (!is_dir($dir))
			return true;

		$files = array_diff(scandir($dir), ['.', '..']);
		foreach ($files as $file) {
			(is_dir("$dir/$file")) ? $this->delTree("$dir/$file") : unlink("$dir/$file");
		}
		return rmdir($dir);
	}
}
