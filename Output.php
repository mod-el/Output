<?php namespace Model\Output;

use Model\Core\Autoloader;
use Model\Core\Module;

class Output extends Module {
	/** @var bool|array */
	private $cache = false;
	/** @var bool */
	private $editedCache = false;
	/** @var array */
	private $tempTableList = [];
	/** @var true */
	private $languageBound = false;
	/** @var array */
	protected $options = [
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
		'cache' => true,
		'cacheHeader' => true,
		'cacheTemplate' => true,
		'cacheFooter' => true,
		'errors' => [],
		'messages' => [],
	];

	/** @var array */
	private $css = [];
	/** @var array */
	private $js = [];
	/** @var array */
	private $cssOptions = [];
	/** @var array */
	private $jsOptions = [];

	/** @var bool */
	private $messagesShown = false;

	/**
	 * @param mixed $options
	 */
	public function init($options){
		$this->methods = [
			'addCSS',
			'addJS',
			'removeCSS',
			'removeJS',
			'sendJSON',
		];

		$this->model->on('Db_select', function($data){
			if(!in_array($data['table'], $this->tempTableList))
				$this->tempTableList[] = $data['table'];

			if($this->model->isLoaded('Multilang')){
			    if(array_key_exists($data['table'], $this->model->_Multilang->tables)){
					$this->languageBound = true;

					$textsTable = $data['table'].$this->model->_Multilang->tables[$data['table']]['suffix'];
					if(!in_array($textsTable, $this->tempTableList))
						$this->tempTableList[] = $textsTable;
                }
            }
		});

		$this->model->on('Db_changedTable', function($data){
			$this->changedTable($data['table']);
		});
	}

	/**
	 * Renders the full page: header, template and footer
	 * If in debug mode shows the debug data
	 *
	 * @param array $options
	 * @throws \Model\Core\Exception
	 */
	public function render(array $options){
		$this->options = array_merge($this->options, $options);

		if($this->options['showLayout']){
			if(!is_array($this->options['header']))
				$this->options['header'] = [$this->options['header']];
			foreach($this->options['header'] as $t){
				$this->renderTemplate($t, [
					'module' => $this->options['template-module-layout'] ?: $this->options['template-module'],
					'cache' => $this->options['cacheHeader'],
					'request-bound' => $this->options['bindHeaderToRequest'],
					'element' => $this->model->element,
				]);
			}
		}

		if($this->options['template']!==false and $this->options['template']!==null){
			$this->renderTemplate($this->options['template'], [
				'module' => $this->options['template-module'],
				'cache' => $this->options['cacheTemplate'],
				'show-messages' => $this->options['showMessages'],
				'request-bound' => true,
				'element' => $this->model->element,
			]);
		}else{ // If there is no template, I still need to show eventual messages
			if($this->options['showMessages'] and !$this->messagesShown)
				echo $this->getMessagesHtml();
        }

		if($this->options['showLayout']){
			if(!is_array($this->options['footer']))
				$this->options['footer'] = [$this->options['footer']];
			foreach($this->options['footer'] as $t){
				$this->renderTemplate($t, [
					'module' => $this->options['template-module-layout'] ?: $this->options['template-module'],
					'cache' => $this->options['cacheFooter'],
					'request-bound' => $this->options['bindFooterToRequest'],
					'element' => $this->model->element,
				]);
			}
		}

		if(DEBUG_MODE and $this->options['showDebugInfo'] and ($this->options['showLayout'] or isset($_COOKIE['ZK_SHOW_AJAX']))){
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
	public function renderTemplate($t, array $options = []){
		$options = array_merge([
			'cache' => true,
			'request-bound' => false,
			'show-messages' => false,
			'element' => null,
			'module' => null,
			'return' => false,
		], $options);

		if(!$this->options['cache']) // Main switch for the cache
		    $options['cache'] = false;

		$file = $this->findTemplateFile($t, $options['module']);
		if(!$file){
			if(DEBUG_MODE){
				if($options['return'])
					return '<b>Template \''.entities($t).'\' not found.</b>';
				else
					echo '<b>Template \''.entities($t).'\' not found.</b>';
			}
			return false;
		}

		if($this->options['template-engine']){
		    if($options['cache']){
				$cache = $this->getCacheData();

				$cacheKey = $file['path'];
				if($options['request-bound']){
					$requestKey = $this->getRequestKey();
					$cacheKey .= '.'.$requestKey;
				}

				if(isset($cache[$file['path']]) and $file['modified']===$cache[$file['path']]['modified'] and $this->cacheFileExists($cacheKey, $cache[$file['path']])){
					$html = $this->getHtmlFromCache($cacheKey, $cache[$file['path']]);
				}else{
					$templateData = $this->makeTemplateHtml($file['path']);
					$html = $templateData['html'];
					$this->saveFileInCache($file, $cacheKey, $templateData['html'], $templateData['data']);
				}
            }else{
				$templateData = $this->makeTemplateHtml($file['path']);
				$html = $templateData['html'];
            }

			if(strpos($html, '[:')!==false){
				preg_match_all('/\[:([^\]]+?)\]/', $html, $tokens);
				foreach($tokens[1] as $t){
					$sub_html = false;
					if($t==='messages'){ // Messages
						$sub_html = $this->getMessagesHtml();
					}elseif($t==='head' or $t==='foot'){ // Head and Foot
						$sub_html = $this->renderBasicSection($t, true);
					}elseif(isset($this->options[$t])){ // Option
						$sub_html = $this->options[$t];
					}elseif(strpos($t, 't:')===0){ // Template
						$template = substr($t, 2);
						$sub_html = $this->renderTemplate($template, ['return' => true, 'module' => $options['module']]);
					}elseif(strpos($t, 'td:')===0){ // Dynamic (non-cached) template
						$template = substr($t, 3);
						$sub_html = $this->renderTemplate($template, ['cache' => false, 'return' => true]);
					}elseif(strpos($t, 'tr:')===0){ // Request bound template
						$template = substr($t, 3);
						$sub_html = $this->renderTemplate($template, ['request-bound' => true, 'return' => true]);
					}elseif(strpos($t, 'el:')===0 and $options['element']!==null){ // Element data
						$dato = substr($t, 3);
						$sub_html = $options['element'][$dato];
					}

					if($sub_html!==false)
						$html = str_replace('[:'.$t.']', $sub_html, $html);
				}
			}
			$html = str_replace('[\\:', '[:', $html);

			if($options['show-messages'] and !$this->messagesShown)
				echo $this->getMessagesHtml();

			if($options['return'])
				return $html;
			else
				echo $html;
		}else{
			ob_start();
			include(INCLUDE_PATH.$file['path']);
			$html = ob_get_clean();

			if($options['show-messages'] and !$this->messagesShown)
				$html = $this->getMessagesHtml().$html;

			if($options['return']){
				return $html;
			}else{
				echo $html;
			}
		}
	}

	/**
     * Seeks for the location of a template
     *
	 * @param string $t
	 * @param string $module
	 * @return array|bool
	 */
	public function findTemplateFile($t, $module = null){
		$files = [
			$t,
		];

		foreach($this->options['template-folder'] as $folder){
			foreach($files as $f){
				$files[] = $folder.DIRECTORY_SEPARATOR.$f;
			}
		}

		if($this->options['theme']){
			$new_files = [];
			foreach($files as $f){
				$new_files[] = $f;
				$new_files[] = $this->options['theme'].DIRECTORY_SEPARATOR.$f;
			}
			$files = $new_files;
		}

		$files = array_reverse($files);

		foreach($files as $f){
		    $file = Autoloader::searchFile('template', $f, $module);
			if($file){
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
	private function getCacheData(){
		if($this->cache===false){
		    if(file_exists(INCLUDE_PATH.'model'.DIRECTORY_SEPARATOR.'Output'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'cache.php')){
				require(INCLUDE_PATH.'model'.DIRECTORY_SEPARATOR.'Output'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'cache.php');
            }
		}
		return $this->cache;
	}

	/**
     * Parses a template file and returns its output, keeping track of which table were used (and if a language-linked action was done)
     *
	 * @param string $t
	 * @return array
	 */
	private function makeTemplateHtml($t){
		$this->tempTableList = [];
		$this->languageBound = false;

		ob_start();
		include($t);
		$html = ob_get_clean();

		return [
			'html' => $html,
			'data' => [
				'tables' => $this->tempTableList,
                'language-bound' => $this->languageBound,
			],
		];
	}

	/**
     * Returns a cached template
     *
	 * @param string $path
	 * @param array $fileData
	 * @return bool|string
	 */
	private function getHtmlFromCache($path, array $fileData){
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
	private function cacheFileExists($path, array $fileData){
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
	private function saveFileInCache(array $file, $cacheKey, $html, array $fileData){
		$cachePath = $this->getFileCachePath($cacheKey, $fileData);
		$cachePathInfo = pathinfo($cachePath);
		if(!is_dir($cachePathInfo['dirname']))
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
	private function getFileCachePath($path, array $fileData){
	    if($fileData['language-bound'] and $this->model->isLoaded('Multilang')){
	        $path .= '-'.$this->model->_Multilang->lang;
        }
		return INCLUDE_PATH.'model'.DIRECTORY_SEPARATOR.'Output'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.$path.'.html';
	}

	/**
     * Edits stored cache metadata for a template
     *
	 * @param string $file
	 * @param mixed $data
	 */
	private function editCacheData($file, $data){
		if(isset($this->cache[$file])){
			$this->cache[$file] = array_merge($this->cache[$file], $data);
		}else{
			$this->cache[$file] = $data;
		}

		$this->editedCache = true;
	}

	/**
     * Removes a file from the cache (e.g. if the template was modified)
     *
	 * @param string $file
	 */
	public function removeFileFromCache($file){
	    $this->getCacheData();

		if(isset($this->cache[$file]))
			unset($this->cache[$file]);

		$this->editedCache = true;
	}

	/**
     * In some cases, a cache file is bound to the request ( = the cache file is bound both to the template and the current request), this method generates a key to allow that
     *
	 * @return string
	 */
	private function getRequestKey(){
		$request = $this->model->getRequest();
		$inputs = $this->model->getInput();

		$useless = ['zkrand', 'zkbindings', 'c_id'];
		foreach($useless as $u){
			if(isset($inputs[$u]))
				unset($inputs[$u]);
		}

		return sha1(implode('/', $request).'?'.json_encode($inputs));
	}

	/**
	 * At the end of each execution, if the cache metadata were modified, this will save them on disk
	 */
	public function terminate(){
		if($this->editedCache){
			file_put_contents(INCLUDE_PATH.'model'.DIRECTORY_SEPARATOR.'Output'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'cache.php', '<?php
$this->cache = '.var_export($this->cache, true).';
');

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
	public function sendJSON($arr, $wrapData=null, $pretty=null){
		if($wrapData===null){
			$wrapData = $this->model->isCLI() ? false : true;
		}
		if($pretty===null){
			$pretty = $this->model->isCLI() ? true : false;
		}

		if (!$wrapData) {
			echo json_encode($arr, $pretty ? JSON_PRETTY_PRINT : 0);
			if($pretty)
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
		if($pretty)
			echo PHP_EOL;
		die();
	}

	/**
     * Triggered whenever a table is changed; removes all the affected cache files
     *
	 * @param string $table
	 */
	private function changedTable($table){
		$cache = $this->getCacheData();

		foreach($cache as $file=>$data){
			if(in_array($table, $data['tables'])){
				$this->removeFileFromCache($file);
			}elseif($table=='zk_dictionary' and $data['language-bound']){
				$this->removeFileFromCache($file);
            }
		}
	}

	/**
     * Generates a simple html string with the messages and/or errors to be shown
     *
	 * @return string
	 */
	private function getMessagesHtml(){
		$this->messagesShown = true;

		$html = '';
		if(!empty($this->options['errors']))
			 $html .= '<div class="red-message">'.implode('<br />', $this->options['errors']).'</div>';
		if(!empty($this->options['messages']))
			$html .= '<div class="green-message">'.implode('<br />', $this->options['messages']).'</div>';
		return $html;
	}

	/**
	 * Adds a JavaScript file to the output
	 *
	 * @param string $js
	 * @param array $options
	 */
	public function addJS($js, array $options = []){
		$options = array_merge([
			'with' => [],
			'but' => [],
			'custom' => true,
            'head' => true,
		], $options);
		if(!is_array($options['with']))
			$options['with'] = [$options['with']];
		if(!is_array($options['but']))
			$options['but'] = [$options['but']];

		if(!in_array($js, $this->js))
			$this->js[] = $js;
		$this->jsOptions[$js] = $options;
	}

	/**
	 * Removes a JavaScript file to the output
	 *
	 * @param string $name
	 */
	public function removeJS($name){
		if(isset($this->jsOptions[$name]))
			unset($this->jsOptions[$name]);
		foreach($this->js as $k=>$n){
			if($n==$name)
				unset($this->js[$k]);
		}
	}

	/**
	 * Removes all JavaScript files set by the user
	 */
	public function wipeJS(){
		foreach($this->jsOptions as $name => $options){
			if($options['custom'])
				$this->removeJS($name);
		}
	}

	/**
	 * Adds a CSS file to the output
	 *
	 * @param string $css
	 * @param array $options
	 */
	public function addCSS($css, array $options = []){
		$options = array_merge([
			'with' => [],
			'but' => [],
			'custom' => true,
            'head' => true,
		], $options);
		if(!is_array($options['with']))
			$options['with'] = [$options['with']];
		if(!is_array($options['but']))
			$options['but'] = [$options['but']];

		if(!in_array($css, $this->css))
			$this->css[] = $css;
		$this->cssOptions[$css] = $options;
	}

	/**
	 * Removes a CSS file to the output
	 *
	 * @param string $name
	 */
	public function removeCSS($name){
		if(isset($this->cssOptions[$name]))
			unset($this->cssOptions[$name]);
		foreach($this->css as $k=>$n){
			if($n==$name)
				unset($this->css[$k]);
		}
	}

	/**
	 * Removes all CSS files set by the user
	 */
	public function wipeCSS(){
		foreach($this->cssOptions as $name => $options){
			if($options['custom'])
				$this->removeCSS($name);
		}
	}

	/**
	 * Echoes or returns the html for the "head" or "foot" section of the page
	 *
	 * @param string $type
	 * @param bool $return
	 * @return string
	 * @throws \Model\Core\Exception
	 */
	private function renderBasicSection($type, $return = true){
		if($return)
			ob_start();

	    switch($type){
            case 'head':
				if(!$this->model->isLoaded('Meta'))
					echo '<title>'.APP_NAME.'</title>
';

				$modules = $this->model->allModules();
				foreach($modules as $m) {
					if(is_object($m))
						$m->headings();
				}

				?>
                <script type="text/javascript">
					var c_id = '<?=isset($_SESSION['csrf']) ? $_SESSION['csrf'] : ''?>';
					var base_path = '<?=PATH?>';
					var absolute_path = '<?=$this->model->prefix()?>';
					var absolute_url = <?=json_encode($this->model->getRequest())?>;
                </script>
				<?php
                break;
            case 'foot':
                break;
            default:
				if($return)
				    ob_clean();

                $this->model->error('Unknown basic section type.');
                break;
        }

		foreach($this->css as $file){
			if(isset($this->cssOptions[$file])){
				if($this->cssOptions[$file]['with'] and !in_array($this->model->leadingModule, $this->cssOptions[$file]['with']))
					continue;
				if(in_array($this->model->leadingModule, $this->cssOptions[$file]['but']))
					continue;
			}
			if((!$this->cssOptions[$file]['head'] and $type==='head') or ($this->cssOptions[$file]['head'] and $type==='foot'))
				continue;
			?><link rel="stylesheet" type="text/css" href="<?=strtolower(substr($file, 0, 4))=='http' ? $file : PATH.$file?>" />
			<?php
		}

		foreach($this->js as $file){
			if(isset($this->jsOptions[$file])){
				if($this->jsOptions[$file]['with'] and !in_array($this->model->leadingModule, $this->jsOptions[$file]['with']))
					continue;
				if(in_array($this->model->leadingModule, $this->jsOptions[$file]['but']))
					continue;
			}
			if((!$this->jsOptions[$file]['head'] and $type==='head') or ($this->jsOptions[$file]['head'] and $type==='foot'))
				continue;
			?><script type="text/javascript" src="<?=strtolower(substr($file, 0, 4))=='http' ? $file : PATH.$file?>"></script>
			<?php
		}

		if($return){
			$html = ob_get_clean();
			return $html;
		}
    }

	/**
	 * Prints the debug data
	 */
	private function showDebugData(){
		$debug = $this->model->getDebugData();
		?>
        <div data-zkdebug="<?=$this->options['showLayout'] ? 'main' : 'ajax'?>" data-url="<?=$debug['request']?>" style="display: none">
            <b>Prefix:</b> <?=$debug['prefix']?><br />
            <b>Request:</b> <?=$debug['request']?><br />
            <b>Execution time:</b> <?=$debug['execution_time']?><br />
            <b>Controller:</b> <?=$debug['controller']?><br />
            <?php if(isset($debug['pageId'])){ ?><b>Page Id:</b> <?=$debug['pageId']?><br /><?php } ?>
            <?php if(isset($debug['elementType'], $debug['elementId'])){ ?><b>Element:</b> <?=$debug['elementType'].' #'.$debug['elementId']?><br /><?php } ?>
            <b>Modules:</b> <?=implode(', ', $debug['modules'])?><br />
            <b>Template:</b> <?=$this->options['template'] ?: 'none'?><br />
            <b>Loading ID:</b> <?=$debug['zk_loading_id']?><br />
            <?php
            if(isset($debug['n_query'])){
                ?>
                <b>Executed queries:</b> <?=$debug['n_query']?><br />
                <b>Prepared queries:</b> <?=$debug['n_prepared']?><br />
                <b>Queries per table:</b><br />
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
	 * @param int|bool $id
	 * @param array $tags
	 * @param array $opt
	 * @return bool|string
	 * @throws \Model\Core\Exception
	 */
	public function getUrl($controller=false, $id=false, array $tags=[], array $opt=[]){
	    return $this->model->getUrl($controller, $id, $tags, $opt);
    }

	/**
     * Retrieves a word from the dictionary
     *
	 * @param string $k
	 * @return string
	 */
	private function word($k){
		$this->languageBound = true;
	    $dic = $this->model->_Multilang->getDictionary();
	    return isset($dic[$k]) ? $dic[$k] : '';
    }
}
