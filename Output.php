<?php namespace Model\Output;

use Model\Assets\Assets;
use Model\Core\Autoloader;
use Model\Core\Module;
use Model\ORM\Element;

class Output extends Module
{
	private bool|array $cache = false;
	private bool $editedCache = false;
	private array $renderingsMetaData = [];
	protected array $options = [
		'header' => [],
		'footer' => [],
		'bindHeaderToRequest' => false,
		'bindFooterToRequest' => false,
		'template-module-layout' => null,
		'template-module' => null,
		'template-folder' => [],
		'template' => false,
		'theme' => null,
		'showLayout' => true,
		'showMessages' => true,
		'showDebugInfo' => true,
		'template-engine' => true,
		'cache' => false,
		'cacheHeader' => true,
		'cacheTemplate' => true,
		'cacheFooter' => true,
		'errors' => [],
		'warnings' => [],
		'messages' => [],
	];
	private array $injectedGlobal = [];
	private bool $messagesShown = false;

	/**
	 * @param mixed $options
	 */
	public function init(array $options)
	{
		$this->model->on('Db_select', function ($data) {
			foreach ($this->renderingsMetaData as $template => $metadata) {
				$linkedTables = $this->model->_Db->getLinkedTables($data['table']);
				foreach ($linkedTables as $table) {
					if (!in_array($table, $metadata['tables']))
						$this->renderingsMetaData[$template]['tables'][] = $table;

					if ($this->model->isLoaded('Multilang') and array_key_exists($table, $this->model->_Multilang->tables))
						$this->renderingsMetaData[$template]['language-bound'] = true;
				}
			}
		});

		$this->model->on('Router_gettingUrl', function ($data) {
			if ($this->model->isLoaded('Multilang')) {
				foreach ($this->renderingsMetaData as $template => $metadata)
					$this->renderingsMetaData[$template]['language-bound'] = true;
			}
		});

		$this->model->on('Db_changedTable', function ($data) {
			$this->changedTable($data['table']);
		});

		$this->model->on('Multilang_changedDictionary', function ($data) {
			$this->changedDictionary();
		});
	}

	/**
	 * Renders the full page: header, template and footer
	 * If in debug mode shows the debug data
	 *
	 * @param array $options
	 */
	public function render(array $options)
	{
		$this->options = array_merge($this->options, $options);

		if ($this->options['showLayout']) {
			if (!is_array($this->options['header']))
				$this->options['header'] = [$this->options['header']];
			foreach ($this->options['header'] as $t) {
				$this->renderTemplate($t, [
					'module' => $this->options['template-module-layout'] ?: $this->options['template-module'],
					'cache' => $this->options['cacheHeader'],
					'request-bound' => $this->options['bindHeaderToRequest'],
				]);
			}
		}

		if ($this->options['template'] !== false and $this->options['template'] !== null) {
			$this->renderTemplate($this->options['template'], [
				'module' => $this->options['template-module'],
				'cache' => $this->options['cacheTemplate'],
				'show-messages' => $this->options['showMessages'],
				'request-bound' => true,
				'element' => $this->model->element,
			]);
		} else { // If there is no template, I still need to show eventual messages
			if ($this->options['showMessages'] and !$this->messagesShown)
				echo $this->getMessagesHtml();
		}

		if ($this->options['showLayout']) {
			if (!is_array($this->options['footer']))
				$this->options['footer'] = [$this->options['footer']];
			foreach ($this->options['footer'] as $t) {
				$this->renderTemplate($t, [
					'module' => $this->options['template-module-layout'] ?: $this->options['template-module'],
					'cache' => $this->options['cacheFooter'],
					'request-bound' => $this->options['bindFooterToRequest'],
				]);
			}
		}

		if (DEBUG_MODE and $this->options['showDebugInfo'] and ($this->options['showLayout'] or isset($_COOKIE['ZK_SHOW_AJAX']))) {
			$this->showDebugData();
		}
	}

	/**
	 * Renders a specific template, using the cache if requested
	 *
	 * @param string $t
	 * @param array $options
	 * @return mixed
	 * @throws \Model\Core\Exception
	 */
	public function renderTemplate(string $t, array $options = [])
	{
		$options = array_merge([
			'cache' => true,
			'request-bound' => false,
			'show-messages' => false,
			'element' => null,
			'module' => null,
			'return' => false,
			'inject' => [],
		], $options);

		if (!$this->options['cache']) // Main switch for the cache
			$options['cache'] = false;

		$file = $this->findTemplateFile($t, $options['module']);
		if (!$file) {
			if (DEBUG_MODE) {
				if ($options['return'])
					return '<b>Template \'' . entities($t) . '\' not found.</b>';
				else
					echo '<b>Template \'' . entities($t) . '\' not found.</b>';
			}
			return false;
		}

		if ($options['cache']) {
			$cache = $this->getCacheData();

			$cacheKey = $file['path'];
			if ($options['request-bound']) {
				$requestKey = $this->getRequestKey();
				$cacheKey .= '.' . $requestKey;
			}
			if (DEBUG_MODE)
				$cacheKey .= '.DEBUG';
			$cacheKey .= '.' . md5(json_encode($options['inject']));

			if (isset($cache[$file['path']]) and $file['modified'] === $cache[$file['path']]['modified'] and $this->cacheFileExists($cacheKey, $cache[$file['path']])) {
				$html = $this->getHtmlFromCache($cacheKey, $cache[$file['path']]);
			} else {
				if (isset($cache[$file['path']]) and $file['modified'] !== $cache[$file['path']]['modified'])
					$this->removeFileFromCache($file['path']);

				$templateData = $this->makeTemplateHtml($file['path'], $options['element'], $options['inject']);
				$html = $templateData['html'];
				$this->saveFileInCache($file, $cacheKey, $templateData['html'], $templateData['data']);
			}
		} else {
			$templateData = $this->makeTemplateHtml($file['path'], $options['element'], $options['inject']);
			$html = $templateData['html'];
		}

		if (strpos($html, '[:') !== false) {
			preg_match_all('/\[:([^\]]+?)\]/', $html, $tokens);
			foreach ($tokens[1] as $token) {
				$sub_html = false;
				if ($token === 'messages') { // Messages
					$sub_html = $this->getMessagesHtml();
				} elseif ($token === 'head' or $token === 'foot') { // Head and Foot
					$sub_html = $this->renderBasicSection($token, $options['cache']);
				} elseif ($this->options['template-engine']) {
					if (isset($this->options[$token])) { // Option
						$sub_html = $this->options[$token];
					} elseif (str_starts_with($token, 'el:') and $options['element'] !== null) { // Element data
						$dato = substr($token, 3);
						$sub_html = $options['element'][$dato];
					} elseif (str_starts_with($token, 'i:')) { // Element data
						$varname = substr($token, 2);
						$sub_html = (string)$this->injected($varname);
					} else {
						$t = explode('|', $token);
						$injected_vars = $t[1] ?? null;
						$t = $t[0];

						if ($injected_vars) {
							$injected_vars_raw = explode(',', $injected_vars);
							$injected_vars = [];
							foreach ($injected_vars_raw as $var) {
								$var = explode('=', $var);
								$injected_vars[$var[0]] = $var[1];
							}
						} else {
							$injected_vars = [];
						}

						if (strpos($t, 't:') === 0) { // Template
							$template = substr($t, 2);
							$sub_html = $this->renderTemplate($template, [
								'cache' => $options['cache'],
								'request-bound' => $options['request-bound'],
								'return' => true,
								'element' => $options['element'],
								'inject' => $injected_vars,
							]);
						} elseif (strpos($t, 'td:') === 0) { // Dynamic (non-cached) template
							$template = substr($t, 3);
							$sub_html = $this->renderTemplate($template, [
								'cache' => false,
								'request-bound' => $options['request-bound'],
								'return' => true,
								'element' => $options['element'],
								'inject' => $injected_vars,
							]);
						} elseif (strpos($t, 'tr:') === 0) { // Request bound template
							$template = substr($t, 3);
							$sub_html = $this->renderTemplate($template, [
								'cache' => $options['cache'],
								'request-bound' => true,
								'return' => true,
								'element' => $options['element'],
								'inject' => $injected_vars,
							]);
						}
					}
				}

				if ($sub_html !== false)
					$html = str_replace('[:' . $token . ']', $sub_html, $html);
			}
		}
		$html = str_replace('[\\:', '[:', $html);

		if ($options['show-messages'] and !$this->messagesShown)
			echo $this->getMessagesHtml();

		if ($options['return'])
			return $html;
		else
			echo $html;
	}

	/**
	 * Seeks for the location of a template
	 *
	 * @param string $t
	 * @param string $module
	 * @return array|bool
	 */
	public function findTemplateFile(string $t, string $module = null)
	{
		$files = [
			$t,
		];

		foreach ($this->options['template-folder'] as $folder) {
			foreach ($files as $f) {
				$files[] = $folder . DIRECTORY_SEPARATOR . $f;
			}
		}

		if ($this->options['theme']) {
			$new_files = [];
			foreach ($files as $f) {
				$new_files[] = $f;
				$new_files[] = $this->options['theme'] . DIRECTORY_SEPARATOR . $f;
			}
			$files = $new_files;
		}

		$files = array_reverse($files);

		foreach ($files as $f) {
			if ($f and stripos($f, INCLUDE_PATH) === 0 and file_exists($f))
				$file = $f;
			else
				$file = Autoloader::searchFile('template', $f, $module);

			if ($file) {
				return [
					'path' => $file,
					'modified' => filemtime($file),
				];
			}
		}

		return false;
	}

	/**
	 * Returns the full cache metadata for this module
	 *
	 * @return array|bool
	 */
	private function getCacheData()
	{
		if ($this->cache === false) {
			$this->cache = [];
			$cacheFile = INCLUDE_PATH . 'model' . DIRECTORY_SEPARATOR . 'Output' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache.php';
			if (file_exists($cacheFile)) {
				$this->cache = json_decode(file_get_contents($cacheFile), true);
				if (!$this->cache) { // Malformed file
					file_put_contents($cacheFile, "{}");
					$this->cache = [];
				}
			}
		}
		return $this->cache;
	}

	/**
	 * Parses a template file and returns its output, keeping track of which table were used (and if a language-linked action was done)
	 *
	 * @param string $template
	 * @param Element|null $element
	 * @param array $injected
	 * @return array
	 */
	private function makeTemplateHtml(string $template, ?Element $element = null, array $injected = []): array
	{
		if (!isset($this->renderingsMetaData[$template])) {
			$this->renderingsMetaData[$template] = [
				'tables' => [],
				'language-bound' => false,
			];

			if ($element) {
				$linkedTables = $this->model->_Db->getLinkedTables($element->getTable());
				foreach ($linkedTables as $table) {
					if (!in_array($table, $this->renderingsMetaData[$template]['tables']))
						$this->renderingsMetaData[$template]['tables'][] = $table;
				}
			}
		}

		if ($template === 'head-section') {
			ob_start();
			$modules = $this->model->allModules();
			foreach ($modules as $m) {
				if (is_object($m))
					$m->headings();
			}
			$html = ob_get_clean();
		} else {
			$injected = array_merge($this->injectedGlobal, $injected);

			$html = (function ($template) use ($injected) {
				foreach ($injected as $injName => $injObj)
					${$injName} = $injObj;

				ob_start();
				include($template);
				return ob_get_clean();
			})($template);
		}

		$ret = [
			'html' => $html,
			'data' => $this->renderingsMetaData[$template],
		];
		unset($this->renderingsMetaData[$template]);

		return $ret;
	}

	/**
	 * Returns a cached template
	 *
	 * @param string $path
	 * @param array $fileData
	 * @return bool|string
	 */
	private function getHtmlFromCache(string $path, array $fileData)
	{
		$cachePath = $this->getFileCachePath($path, $fileData);
		$html = file_get_contents($cachePath);
		return $html;
	}

	/**
	 * Returns a boolean indicating if a template is cached or not
	 *
	 * @param string $path
	 * @param array $fileData
	 * @return bool
	 */
	private function cacheFileExists(string $path, array $fileData)
	{
		$cachePath = $this->getFileCachePath($path, $fileData);
		return file_exists($cachePath);
	}

	/**
	 * Saves a cache file; the full html (generated in the other methods) is given as input
	 *
	 * @param array $file
	 * @param string $cacheKey
	 * @param string $html
	 * @param array $fileData
	 */
	private function saveFileInCache(array $file, string $cacheKey, string $html, array $fileData)
	{
		$cachePath = $this->getFileCachePath($cacheKey, $fileData);
		$cachePathInfo = pathinfo($cachePath);
		if (!is_dir($cachePathInfo['dirname']))
			mkdir($cachePathInfo['dirname'], 0777, true);

		file_put_contents($cachePath, $html);

		$fileData['modified'] = $file['modified'];
		$this->editCacheData($file['path'], $fileData);
	}

	/**
	 * Returns the path for the cache file of a template
	 *
	 * @param string $path
	 * @param array $fileData
	 * @return string
	 */
	private function getFileCachePath(string $path, array $fileData): string
	{
		if ($fileData['language-bound'] and $this->model->isLoaded('Multilang')) {
			$path .= '-' . $this->model->_Multilang->lang;
		}
		return INCLUDE_PATH . 'model' . DIRECTORY_SEPARATOR . 'Output' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . $path . '.html';
	}

	/**
	 * Edits stored cache metadata for a template
	 *
	 * @param string $file
	 * @param mixed $data
	 */
	private function editCacheData(string $file, $data)
	{
		if (isset($this->cache[$file])) {
			$this->cache[$file] = array_merge($this->cache[$file], $data);
		} else {
			$this->cache[$file] = $data;
		}

		$this->editedCache = true;
	}

	/**
	 * Removes a file from the cache (e.g. if the template was modified)
	 *
	 * @param string $file
	 */
	public function removeFileFromCache(string $file)
	{
		$this->getCacheData();

		if (isset($this->cache[$file]))
			unset($this->cache[$file]);

		$cacheFiles = glob(INCLUDE_PATH . 'model' . DIRECTORY_SEPARATOR . 'Output' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache' . $file . '*');
		foreach ($cacheFiles as $cacheFile)
			unlink($cacheFile);

		$this->editedCache = true;
	}

	/**
	 * In some cases, a cache file is bound to the request ( = the cache file is bound both to the template and the current request), this method generates a key to allow that
	 *
	 * @return string
	 */
	private function getRequestKey(): string
	{
		$request = $this->model->getRequest();
		$inputs = $this->model->getInput();

		$useless = ['zkrand', 'zkbindings', 'c_id'];
		foreach ($useless as $u) {
			if (isset($inputs[$u]))
				unset($inputs[$u]);
		}

		return sha1(implode('/', $request) . '?' . json_encode($inputs));
	}

	/**
	 * At the end of each execution, if the cache metadata were modified, this will save them on disk
	 */
	public function terminate()
	{
		if ($this->editedCache) {
			file_put_contents(INCLUDE_PATH . 'model' . DIRECTORY_SEPARATOR . 'Output' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache.php', json_encode($this->cache));

		}
	}

	/**
	 * Output a datum in JSON format and ends the execution of the script
	 * It will automatically wrap data (if not in CLI) with the debug data and/or bindings (to use in conjunctions with the JS functions)
	 *
	 * @param mixed $arr
	 * @param bool $wrapData
	 * @param bool $pretty
	 * @throws \Model\Core\Exception
	 */
	public function sendJSON($arr, bool $wrapData = null, bool $pretty = null)
	{
		if ($wrapData === null) {
			$wrapData = $this->model->isCLI() ? false : true;
		}
		if ($pretty === null) {
			$pretty = $this->model->isCLI() ? true : false;
		}

		if (!$wrapData) {
			echo json_encode($arr, $pretty ? JSON_PRETTY_PRINT : 0);
			if ($pretty)
				echo PHP_EOL;
			die();
		}

		$arr = array(
			'ZKBINDINGS' => [],
			'ZKDATA' => $arr
		);

		if (DEBUG_MODE and isset($_COOKIE['ZK_SHOW_JSON'])) {
			$arr['ZKDEBUG'] = $this->model->getDebugData();
		}

		echo json_encode($arr, $pretty ? JSON_PRETTY_PRINT : 0);
		if ($pretty)
			echo PHP_EOL;
		die();
	}

	/**
	 * @param string $name
	 * @param mixed $var
	 */
	public function inject(string $name, $var)
	{
		if (!preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name))
			$this->model->error('Injected variable "' . entities($name) . '" is not a valid name for a variable.');

		$this->injectedGlobal[$name] = $var;
	}

	/**
	 * @param string|null $name
	 * @return mixed|null
	 */
	public function injected(?string $name = null)
	{
		if ($name === null) {
			return $this->injectedGlobal;
		} else {
			return $this->injectedGlobal[$name] ?? null;
		}
	}

	/**
	 * Triggered whenever a table is changed; removes all the affected cache files
	 *
	 * @param string $table
	 */
	private function changedTable(string $table)
	{
		$cache = $this->getCacheData();

		foreach ($cache as $file => $data) {
			if (in_array($table, $data['tables']))
				$this->removeFileFromCache($file);
		}
	}

	/**
	 * Triggered whenever a word in the dictionary is changed; removes all the affected cache files
	 */
	private function changedDictionary()
	{
		$cache = $this->getCacheData();

		foreach ($cache as $file => $data) {
			if ($data['language-bound']) {
				$this->removeFileFromCache($file);
			}
		}
	}

	/**
	 * Generates a simple html string with the messages and/or errors to be shown
	 *
	 * @return string
	 */
	private function getMessagesHtml()
	{
		$this->messagesShown = true;

		$html = '';
		if (!empty($this->options['errors']))
			$html .= $this->getMessageSetHtml($this->options['errors'], 'danger');
		if (!empty($this->options['warnings']))
			$html .= $this->getMessageSetHtml($this->options['warnings'], 'warning');
		if (!empty($this->options['messages']))
			$html .= $this->getMessageSetHtml($this->options['messages'], 'success');
		return $html;
	}

	private function getMessageSetHtml(array $messages, string $type): string
	{
		$classes = [
			'danger' => 'red-message',
			'warning' => 'orange-message',
			'success' => 'green-message',
		];
		if (!isset($classes[$type]))
			$this->model->error('Unrecognized message type in output module');

		$html = '';
		foreach ($messages as $message) {
			if ($this->model->isLoaded('Bootstrap')) {
				$closeBtn = '';
				switch ($this->model->_Bootstrap->getVersion()) {
					case 4:
						$closeBtn = '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>';
						break;
					case 5:
						$closeBtn = '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
						break;
				}

				$html .= '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">
				  ' . $message . '
				  ' . $closeBtn . '
				</div>';
			} else {
				$html .= '<div class="' . $classes[$type] . '">' . $message . '</div>';
			}
		}
		return $html;
	}

	/**
	 * Adds a JavaScript file to the output
	 *
	 * @param string $js
	 * @param array $options
	 * @deprecated
	 */
	public function addJS(string $js, array $options = [])
	{
		$options = array_merge([
			'with' => [],
			'but' => [],
			'custom' => true,
			'head' => true,
			'cacheable' => true,
			'defer' => false,
			'async' => false,
		], $options);

		$options['type'] = 'js';

		$options['withTags'] = $options['with'];
		unset($options['with']);

		$options['exceptTags'] = $options['but'];
		unset($options['but']);

		Assets::add($js, $options);
	}

	/**
	 * Removes a JavaScript file to the output
	 *
	 * @param string $name
	 * @deprecated
	 */
	public function removeJS(string $name)
	{
		Assets::remove($name);
	}

	/**
	 * Adds a CSS file to the output
	 *
	 * @param string $css
	 * @param array $options
	 * @deprecated
	 */
	public function addCSS(string $css, array $options = [])
	{
		$options = array_merge([
			'with' => [],
			'but' => [],
			'custom' => true,
			'cacheable' => true,
			'defer' => false,
		], $options);

		$options['type'] = 'css';

		$options['withTags'] = $options['with'];
		unset($options['with']);

		$options['exceptTags'] = $options['but'];
		unset($options['but']);

		Assets::add($css, $options);
	}

	/**
	 * Removes a CSS file to the output
	 *
	 * @param string $name
	 * @deprecated
	 */
	public function removeCSS(string $name)
	{
		Assets::remove($name);
	}

	/**
	 * Echoes or returns the html for the "head" or "foot" section of the page
	 *
	 * @param string $type
	 * @param bool $useCache
	 * @return string
	 * @throws \Model\Core\Exception
	 */
	private function renderBasicSection(string $type, bool $useCache)
	{
		ob_start();

		switch ($type) {
			case 'head':
				if (!$this->model->isLoaded('Seo'))
					echo '<title>' . APP_NAME . '</title>
';
				$fakeHeadFileName = 'head-section';

				if ($useCache) {
					$cache = $this->getCacheData();

					$cacheKey = $fakeHeadFileName . '.' . $this->getRequestKey();
					if (DEBUG_MODE)
						$cacheKey .= '.DEBUG';

					if (isset($cache[$fakeHeadFileName]) and $this->cacheFileExists($cacheKey, $cache[$fakeHeadFileName])) {
						echo $this->getHtmlFromCache($cacheKey, $cache[$fakeHeadFileName]);
					} else {
						if (isset($cache[$fakeHeadFileName]))
							$this->removeFileFromCache($fakeHeadFileName);

						$templateData = $this->makeTemplateHtml($fakeHeadFileName, $this->model->element);
						echo $templateData['html'];
						$this->saveFileInCache(['path' => $fakeHeadFileName, 'modified' => null], $cacheKey, $templateData['html'], $templateData['data']);
					}
				} else {
					echo $this->makeTemplateHtml($fakeHeadFileName, $this->model->element)['html'];
				}
				?>
				<script type="text/javascript">
					/* Backward compatibility */
					var base_path = '<?=PATH?>';
					var absolute_path = '<?=$this->model->prefix()?>';
					var absolute_url = <?=json_encode($this->model->getRequest())?>;

					var PATHBASE = '<?=PATH?>';
					var PATH = '<?=$this->model->prefix()?>';
					var REQUEST = <?=json_encode($this->model->getRequest())?>;
				</script>
				<?php
				break;
			case 'foot':
				break;
			default:
				ob_clean();
				throw new \Exception('Unknown basic section type.');
		}

		Assets::render([
			'position-' . $type,
			'module-' . $this->model->leadingModule,
		]);

		return ob_get_clean();
	}

	/**
	 * Prints the debug data
	 */
	private function showDebugData()
	{
		$debug = $this->model->getDebugData();
		?>
		<div data-zkdebug="<?= $this->options['showLayout'] ? 'main' : 'ajax' ?>" data-url="<?= $debug['request'] ?>"
		     style="display: none">
			<b>Prefix:</b> <?= $debug['prefix'] ?>
			<br/>
			<b>Request:</b> <?= $debug['request'] ?>
			<br/>
			<b>Execution time:</b> <?= $debug['execution_time'] ?>
			<br/>
			<b>Controller:</b> <?= $debug['controller'] ?>
			<br/>
			<?php if (isset($debug['pageId'])) { ?>
				<b>Page Id:</b> <?= $debug['pageId'] ?>
				<br/><?php } ?>
			<?php if (isset($debug['elementType'], $debug['elementId'])) { ?>
				<b>Element:</b> <?= $debug['elementType'] . ' #' . $debug['elementId'] ?>
				<br/><?php } ?>
			<b>Modules:</b> <?= implode(', ', $debug['modules']) ?>
			<br/>
			<b>Template:</b> <?= $this->options['template'] ?: 'none' ?>
			<br/>
			<b>Loading ID:</b> <?= $debug['zk_loading_id'] ?>
			<br/>
			<?php
			if (isset($debug['n_query'])) {
				?>
				<b>Executed queries:</b> <?= $debug['n_query'] ?>
				<br/>
				<b>Prepared queries:</b> <?= $debug['n_prepared'] ?>
				<br/>
				<b>Queries per table:</b>
				<br/>
				<?php
				zkdump($debug['query_per_table']);
			}
			?>
		</div>
		<?php
	}

	/**
	 * Shortcut for $this->model->getUrl
	 *
	 * @param string|bool $controller
	 * @param null|string $id
	 * @param array $tags
	 * @param array $opt
	 * @return bool|string
	 */
	public function getUrl(?string $controller = null, ?string $id = null, array $tags = [], array $opt = []): ?string
	{
		return $this->model->getUrl($controller, $id, $tags, $opt);
	}

	/**
	 * Retrieves a word from the dictionary
	 *
	 * @param string $k
	 * @param bool $escape
	 * @param string|null $lang
	 * @return string
	 */
	protected function word(string $k, bool $escape = true, ?string $lang = null): string
	{
		foreach ($this->renderingsMetaData as $template => $metadata)
			$this->renderingsMetaData[$template]['language-bound'] = true;

		if (!class_exists('\\Model\\Multilang\\Dictionary'))
			throw new \Exception('Package model/multilang not found');

		$word = \Model\Multilang\Dictionary::get($k, $lang);
		if ($escape)
			$word = entities($word);
		return $word;
	}
}
