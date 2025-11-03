<?php namespace Model\Output;

use Model\Assets\Assets;
use Model\Cache\Cache;
use Model\Core\Autoloader;
use Model\Core\Module;
use Model\Events\Events;
use Model\ORM\Element;

class Output extends Module
{
	private array $cache;
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
		if (!Cache::isTagAware())
			throw new \Exception('Output cache requires a tag aware adapter');

		if (class_exists('\\Model\\Db\\Db')) {
			Events::subscribeTo(\Model\Db\Events\SelectQuery::class, function (\Model\Db\Events\SelectQuery $event) {
				foreach ($this->renderingsMetaData as $template => $metadata) {
					$linkedTables = $this->model->_Db->getLinkedTables($event->table);
					foreach ($linkedTables as $table) {
						if (!in_array($table, $metadata['tables']))
							$this->renderingsMetaData[$template]['tables'][] = $table;

						if (class_exists('\\Model\\Multilang\\Ml') and isset(\Model\Multilang\Ml::getTables(\Model\Db\Db::getConnection())[$table]))
							$this->renderingsMetaData[$template]['language-bound'] = true;
					}
				}
			});

			Events::subscribeTo(\Model\Db\Events\ChangedTable::class, function (\Model\Db\Events\ChangedTable $event) {
				$this->changedTable($event->table);
			});
		}

		if (class_exists('\\Model\\Multilang\\Ml')) {
			Events::subscribeTo(\Model\Router\Events\UrlGenerate::class, function (\Model\Router\Events\UrlGenerate $event) {
				foreach ($this->renderingsMetaData as $template => $metadata)
					$this->renderingsMetaData[$template]['language-bound'] = true;
			});

			Events::subscribeTo(\Model\Multilang\Events\ChangedDictionary::class, function (\Model\Multilang\Events\ChangedDictionary $event) {
				$this->changedDictionary();
			});
		}
	}

	/**
	 * Renders the full page: header, template and footer
	 * If in debug mode shows the debug data
	 *
	 * @param array $options
	 */
	public function render(array $options): void
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

		if (DEBUG_MODE and $this->options['showDebugInfo'] and ($this->options['showLayout'] or isset($_COOKIE['ZK_SHOW_AJAX'])))
			$this->showDebugData();
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
			$cache = $this->getMainCache();

			$cacheKey = $file['path'];
			if ($options['request-bound']) {
				$requestKey = $this->getRequestKey();
				$cacheKey .= '.' . $requestKey;
			}
			if (DEBUG_MODE)
				$cacheKey .= '.DEBUG';
			$cacheKey .= '.' . md5(json_encode($options['inject']));

			if (isset($cache[$file['path']]) and $file['modified'] !== $cache[$file['path']]['modified'])
				$this->removeFileFromCache($file['path']);

			$fullcacheKey = $this->getFullCacheKey($cacheKey, $cache[$file['path']] ?? null);
			$cacheAdapter = Cache::getCacheAdapter();
			$html = $cacheAdapter->get('model.legacy.output.' . $fullcacheKey, function (\Symfony\Contracts\Cache\ItemInterface $item) use ($cacheKey, $file, $options) {
				$this->removeFileFromCache($file['path']);

				$item->expiresAfter(3600 * 24);
				$item->tag(sha1($file['path']));

				$templateData = $this->makeTemplateHtml($file['path'], $options['element'], $options['inject']);
				$this->editCacheData($file['path'], [...$templateData['data'], 'modified' => $file['modified']]);
				return $templateData['html'];
			});
		} else {
			$html = $this->makeTemplateHtml($file['path'], $options['element'], $options['inject'])['html'];
		}

		if (str_contains($html, '[:')) {
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

						if (str_starts_with($t, 't:')) { // Template
							$template = substr($t, 2);
							$sub_html = $this->renderTemplate($template, [
									'cache' => $options['cache'],
									'request-bound' => $options['request-bound'],
									'return' => true,
									'element' => $options['element'],
									'inject' => $injected_vars,
							]);
						} elseif (str_starts_with($t, 'td:')) { // Dynamic (non-cached) template
							$template = substr($t, 3);
							$sub_html = $this->renderTemplate($template, [
									'cache' => false,
									'request-bound' => $options['request-bound'],
									'return' => true,
									'element' => $options['element'],
									'inject' => $injected_vars,
							]);
						} elseif (str_starts_with($t, 'tr:')) { // Request bound template
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
	 * @param string|null $module
	 * @return array|bool
	 */
	public function findTemplateFile(string $t, ?string $module = null)
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
	 * @return array
	 */
	private function getMainCache(): array
	{
		if (!isset($this->cache)) {
			$cacheAdapter = Cache::getCacheAdapter();
			$this->cache = $cacheAdapter->get('model.legacy.output.main', function (\Symfony\Contracts\Cache\ItemInterface $item) {
				$item->expiresAfter(3600 * 24);
				return [];
			});
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
	 * Returns the full hash with eventual language binding for a given template
	 *
	 * @param string $path
	 * @param array|null $fileData
	 * @return string
	 */
	private function getFullCacheKey(string $path, ?array $fileData = null): string
	{
		if ($fileData and $fileData['language-bound'] and class_exists('\\Model\\Multilang\\Ml'))
			$path .= '-' . \Model\Multilang\Ml::getLang();

		return sha1($path);
	}

	/**
	 * Edits stored cache metadata for a template
	 *
	 * @param string $file
	 * @param array $data
	 */
	private function editCacheData(string $file, array $data): void
	{
		$cache = $this->getMainCache();
		if (isset($cache[$file]))
			$cache[$file] = array_merge($cache[$file], $data);
		else
			$cache[$file] = $data;
		$this->saveMainCache($cache);
	}

	/**
	 * Removes a file from the cache (e.g. if the template was modified)
	 *
	 * @param string $file
	 */
	public function removeFileFromCache(string $file): void
	{
		$cache = $this->getMainCache();
		if (isset($cache[$file]))
			unset($cache[$file]);

		$this->saveMainCache($cache);

		$cacheAdapter = Cache::getCacheAdapter();
		$cacheAdapter->invalidateTags([sha1($file)]);
	}

	/**
	 * @param array $data
	 * @return void
	 */
	private function saveMainCache(array $data): void
	{
		$this->cache = $data;
		$cacheAdapter = Cache::getCacheAdapter();
		$item = $cacheAdapter->getItem('model.legacy.output.main');
		$item->set($data);
		$cacheAdapter->save($item);
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
	 * Output a datum in JSON format and ends the execution of the script
	 * It will automatically wrap data (if not in CLI) with the debug data and/or bindings (to use in conjunctions with the JS functions)
	 *
	 * @param mixed $arr
	 * @param bool $wrapData
	 * @param bool $pretty
	 * @throws \Model\Core\Exception
	 */
	public function sendJSON(mixed $arr, ?bool $wrapData = null, ?bool $pretty = null): never
	{
		if ($wrapData === null)
			$wrapData = $this->model->isCLI() ? false : true;
		if ($pretty === null)
			$pretty = $this->model->isCLI() ? true : false;

		if (!$wrapData) {
			echo json_encode($arr, $pretty ? JSON_PRETTY_PRINT : 0);
			if ($pretty)
				echo PHP_EOL;
			die();
		}

		$arr = [
				'ZKBINDINGS' => [],
				'ZKDATA' => $arr,
		];

		if (DEBUG_MODE and isset($_COOKIE['ZK_SHOW_JSON']))
			$arr['ZKDEBUG'] = $this->model->getDebugData();

		echo json_encode($arr, $pretty ? JSON_PRETTY_PRINT : 0);
		if ($pretty)
			echo PHP_EOL;

		die();
	}

	/**
	 * @param string $name
	 * @param mixed $var
	 */
	public function inject(string $name, mixed $var): void
	{
		if (!preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name))
			$this->model->error('Injected variable "' . entities($name) . '" is not a valid name for a variable.');

		$this->injectedGlobal[$name] = $var;
	}

	/**
	 * @param string|null $name
	 * @return mixed
	 */
	public function injected(?string $name = null): mixed
	{
		if ($name === null)
			return $this->injectedGlobal;
		else
			return $this->injectedGlobal[$name] ?? null;
	}

	/**
	 * Triggered whenever a table is changed; removes all the affected cache files
	 *
	 * @param string $table
	 */
	private function changedTable(string $table): void
	{
		$cache = $this->getMainCache();

		foreach ($cache as $file => $data) {
			if (in_array($table, $data['tables']))
				$this->removeFileFromCache($file);
		}
	}

	/**
	 * Triggered whenever a word in the dictionary is changed; removes all the affected cache files
	 */
	private function changedDictionary(): void
	{
		$cache = $this->getMainCache();

		foreach ($cache as $file => $data) {
			if ($data['language-bound'])
				$this->removeFileFromCache($file);
		}
	}

	/**
	 * Generates a simple html string with the messages and/or errors to be shown
	 *
	 * @return string
	 */
	private function getMessagesHtml(): string
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

		$bootstrapVersion = Assets::isEnabled('bootstrap');

		$html = '';
		foreach ($messages as $message) {
			if ($bootstrapVersion) {
				$closeBtn = '';
				switch ($bootstrapVersion) {
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
	 * Returns the html for the "head" or "foot" section of the page
	 *
	 * @param string $type
	 * @param bool $useCache
	 * @return string
	 */
	private function renderBasicSection(string $type, bool $useCache): string
	{
		ob_start();

		switch ($type) {
			case 'head':
				if (!$this->model->isLoaded('Seo'))
					echo '<title>' . APP_NAME . '</title>
';
				$fakeHeadFileName = 'head-section';

				if ($useCache) {
					$cache = $this->getMainCache();

					$cacheKey = $fakeHeadFileName . '.' . $this->getRequestKey();
					if (DEBUG_MODE)
						$cacheKey .= '.DEBUG';

					$fullcacheKey = $this->getFullCacheKey($cacheKey, $cache[$fakeHeadFileName] ?? null);
					$cacheAdapter = Cache::getCacheAdapter();
					echo $cacheAdapter->get('model.legacy.output.' . $fullcacheKey, function (\Symfony\Contracts\Cache\ItemInterface $item) use ($cacheKey, $fakeHeadFileName) {
						$this->removeFileFromCache($fakeHeadFileName);

						$item->expiresAfter(3600 * 24);
						$item->tag(sha1($fakeHeadFileName));

						$templateData = $this->makeTemplateHtml($fakeHeadFileName, $this->model->element);
						$this->editCacheData($fakeHeadFileName, [...$templateData['data'], 'modified' => null]);
						return $templateData['html'];
					});
				} else {
					echo $this->makeTemplateHtml($fakeHeadFileName, $this->model->element)['html'];
				}
				?>
				<script>
					/* Backward compatibility */
					var base_path = '<?=PATH?>';
					var absolute_path = '<?=PATH?>';
					var absolute_url = <?=json_encode($this->model->getRequest())?>;

					var PATHBASE = '<?=PATH?>';
					var PATH = '<?=PATH?>';
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

		$tags = [
				'position' => $type,
		];

		if ($this->model->getRouter()->activeRoute)
			$tags['provider'] = $this->model->getRouter()->activeRoute['tags']['provider'] ?? null;

		Assets::render($tags);

		return ob_get_clean();
	}

	/**
	 * Prints the debug data
	 */
	private function showDebugData(): void
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
			<b>Loading ID:</b> <?= $debug['loading_id'] ?>
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
	 * @param string|null $controller
	 * @param null|string $id
	 * @param array $tags
	 * @param array $opt
	 * @return string|null
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
