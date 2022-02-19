<?php namespace Model\Output;

use Model\Core\Module_Config;

class Config extends Module_Config
{
	/**
	 * @throws \Model\Core\Exception
	 */
	protected function assetsList()
	{
		$this->addAsset('config', 'config.php', function () {
			return '<?php
$config = ' . var_export([
					'minify-css' => false,
					'minify-js' => false,
				], true) . ";\n";
		});

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
		$allDone = (
			(bool)file_put_contents(INCLUDE_PATH . 'model' . DIRECTORY_SEPARATOR . 'Output' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache.php', "{}")
			and $this->delTree(INCLUDE_PATH . 'model' . DIRECTORY_SEPARATOR . 'Output' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache')
			and $this->delTree(INCLUDE_PATH . 'model' . DIRECTORY_SEPARATOR . 'Output' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'minified')
		);

		if (!$allDone)
			return false;

		mkdir(INCLUDE_PATH . 'model' . DIRECTORY_SEPARATOR . 'Output' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'minified', 0777, true);

		return true;
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

		try {
			if (!is_callable('exec') or stripos(ini_get('disable_functions'), 'exec') !== false)
				throw new \Exception('exec function disabled');

			$response = exec('rm -rf ' . $dir, $response_rows, $result_code);
			if ($response === false)
				throw new \Exception('exec function failed');
			if ($result_code !== 0)
				throw new \Exception('exec function returned non-zero code');

			return true;
		} catch (\Exception $e) {
			$files = array_diff(scandir($dir), ['.', '..']);
			foreach ($files as $file) {
				(is_dir("$dir/$file")) ? $this->delTree("$dir/$file") : unlink("$dir/$file");
			}

			return rmdir($dir);
		}
	}

	public function getConfigData(): ?array
	{
		return [];
	}
}
