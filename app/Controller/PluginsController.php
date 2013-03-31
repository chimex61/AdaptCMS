<?php

class PluginsController extends AppController
{
    /**
    * Name of the Controller, 'Plugins'
    */
	public $name = 'Plugins';

	/**
	* API Component is used to connect to the adaptcms.com website
	*/
	public $components = array(
		'Api'
	);

	/**
	* There is no real 'Plugin List' stored in a database, so no model uses by default
	*/
	public $uses = array();


	/**
	* The Index gets all active and inactive plugins along with basic info.
	* A lookup is performed to get info from the AdaptCMS website, if an API ID is provided.
	*
	* @return array of plugin data
	*/
	public function admin_index()
	{
		$active_path = APP . 'Plugin';
		$active_plugins = $this->getPlugins($active_path);

		$inactive_path = APP . 'Old_Plugins';
		$inactive_plugins = $this->getPlugins($inactive_path);

		$plugins = array_merge($inactive_plugins['plugins'], $active_plugins['plugins']);
		$api_lookup = array_merge($inactive_plugins['api_lookup'], $active_plugins['api_lookup']);

		if (!empty($api_lookup)){
			if ($data = $this->Api->pluginsLookup($api_lookup)) {
				foreach($plugins as $key => $plugin) {
					if (!empty($plugin['api_id']) && !empty($data['data'][$plugin['api_id']])) {
						$plugins[$key]['data'] = $data['data'][$plugin['api_id']];
					}
				}
			}
		}

		$this->set(compact('plugins'));
	}

	/**
	* Before POST, just returns the plugins config params and plugin info.
	*
	* After POST, attempts to update settings from form by updating the plugins config file
	* and sets flash message on success or error.
	*
	* @param plugin name
	* @return mixed
	*/
	public function admin_settings($plugin)
	{
		$path = APP . 'Plugin' . DS . $plugin;
		$config_path = $path . DS . 'Config' . DS . 'config.php';

		if (file_exists($config_path))
		{
			$params = Configure::read($plugin);

			if (isset($params['admin_menu']))
			{
				unset($params['admin_menu']);
			}
			if (isset($params['admin_menu_label']))
			{
				unset($params['admin_menu_label']);
			}
		} else {
			$params = array();
		}

		if (!empty($this->request->data))
		{
			$orig_contents = file_get_contents($config_path);
			$contents = $this->request->data['Settings'];		

			$new_contents = str_replace( json_encode($params), json_encode($contents), $orig_contents );

        	$fh = fopen($config_path, 'w') or die("can't open file");

        	if (fwrite($fh, $new_contents))
        	{
        		if ($plugin_json = $this->getPluginJson($path . DS . 'plugin.json'))
        		{
        			if (!empty($plugin_json['install']['model_title']))
        			{
        				$model = $plugin_json['install']['model_title'];

        				$this->loadModel($plugin . '.' . $model);

        				if (method_exists($this->$model, 'onSettingsUpdate'))
        				{
        					$this->$model->onSettingsUpdate($params, $contents);
        				}
        			}
        		}

        		$this->Session->setFlash('The Plugin ' . $plugin . ' settings have been updated.', 'flash_success');
        		$params = $contents;
        	} else {
        		$this->Session->setFlash('The Plugin ' . $plugin . ' settings could not be updated.', 'flash_error');
        	}

        	fclose($fh);
		}

		$this->set(compact('plugin', 'params'));
	}
        
        
        /**
         * A simple function that grabs all permissions (grouped by role) for a plugin for editing on page.
         * Flash message on success/error.
         * 
         * @param type $plugin
         */
        public function admin_permissions($plugin)
        {
            $this->loadModel('Role');
            
            $roles = $this->Role->find('all', array(
                'contain' => array(
                    'Permission' => array(
                        'conditions' => array(
                            'Permission.plugin' => Inflector::underscore($plugin)
                        ),
                        'order' => 'Permission.controller ASC, Permission.action ASC'
                    )
                )
            ));
            
            $this->set(compact('plugin', 'roles'));
            
            if (!empty($this->request->data))
            {
//                die(debug($this->request->data));
                if ($this->Role->Permission->saveMany($this->request->data))
                {
                    $this->Session->setFlash('Plugin permissions have been updated.', 'flash_success');
                }
                else 
                {
                    $this->Session->setFlash('Unable to update plugin permissions.', 'flash_error');
                }
            }
        }

	/**
	* Function hooks into Themes to manage web assets for plugins
	*
	* @param plugin name
	* @return variables to output list of assets
	*/
	public function admin_assets($plugin)
	{
            $this->loadModel('Theme');

            $this->set('assets_list', $this->Theme->assetsList(null, $plugin));
            $this->set('assets_list_path', APP);
            $this->set('webroot_path', $this->webroot);

            $this->set(compact('plugin'));
	}

	/**
	* Convienence method, need to get plugin JSON file contents several times in this controller.
	*
	* @param path of plugin JSON file
	* @return json_decode array of data, false if it can't get file contents
	*/
	private function getPluginJson($path)
	{
		if (file_exists($path) && is_readable($path))
		{
			$handle = fopen($path, "r");
			$file = fread($handle, filesize($path));

			return json_decode($file, true);
		}

		return;
	}

	/**
	* Convienence method
	* Goes through folder of Plugins, setting all data needed on plugin listing.
	*
	* @param path of specified Plugin folder
	* @return of plugin data with api information
	*/
	private function getPlugins($path)
	{
		$exclude = array(
			'DebugKit'
		);
		$plugins = array();
		$api_lookup = array();

		if ($dh = opendir($path)) {
			while (($file = readdir($dh)) !== false) {
                if (!in_array($file, $exclude) && $file != ".." && $file != ".") {
                	$json = $path . DS . $file . DS . 'plugin.json';

		 			if ($plugin = $this->getPluginJson($json))
		 			{
		 				$plugins[$file] = $plugin;

		 				if (!empty($plugins[$file]['api_id'])) {
		 					$api_lookup[] = $plugins[$file]['api_id'];
						}
		 			} else {
		 				$plugins[$file]['title'] = $file;
		 			}

		 			$upgrade = $path . DS . $file . DS . 'Install' . DS . 'upgrade.json';

		 			if (file_exists($upgrade) && is_readable($upgrade))
		 			{
		 				$plugins[$file]['upgrade_status'] = 1;
		 			} else {
		 				$plugins[$file]['upgrade_status'] = 0;
		 			}

		 			if (strstr($path, 'Old')) {
		 				$plugins[$file]['status'] = 0;
		 			} else {
		 				$plugins[$file]['status'] = 1;
		 			}

		 			$config = $path . DS . $file . DS . 'Config' . DS . 'config.php';

		 			if (file_exists($config) && is_readable($config))
		 			{
		 				$plugins[$file]['config'] = 1;
		 			} else {
		 				$plugins[$file]['config'] = 0;
		 			}
                }
            }
		}

		return array(
			'plugins' => $plugins,
			'api_lookup' => $api_lookup
		);
	}
}