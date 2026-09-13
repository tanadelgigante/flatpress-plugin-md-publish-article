<?php

if (class_exists('AdminPanelAction')) {
	require_once plugin_getdir('publisharticle') . 'ArticleImporter.php';

	class admin_plugin_publisharticle extends AdminPanelAction {
		var $langres = 'plugin:publisharticle';

		function setup() {
			$this->smarty->assign('admin_resource', 'plugin:publisharticle/admin.plugin.publisharticle');
		}

		function main() {
			$options = plugin_getoptions('publisharticle');

			// Default values
			$defaults = array(
				'import_folder'    => '',
				'import_frequency' => 'every_page_load', // 'manual' | 'every_page_load' | cron expression
				'default_category' => '',
				'default_status'   => 'publish',
				'done_subdir'      => 'done',
				'failed_subdir'    => 'failed',
			);

			$options = is_array($options) ? array_merge($defaults, $options) : $defaults;

			foreach ($options as $key => $value) {
				$this->smarty->assign($key, $value);
			}

			// Show import folder status
			if (!empty($options['import_folder'])) {
				$importer = new ArticleImporter(null, $options['import_folder'], $options);
				$protection = $importer->checkProtection();
				$this->smarty->assign('import_folder_status', $protection);
				$this->smarty->assign('pending_count', count($importer->scan()));
			}

			// Helper for the template: pre-selected frequency option
			$freq = $options['import_frequency'];
			$this->smarty->assign('is_manual', $freq === 'manual');
			$this->smarty->assign('is_every_page_load', $freq === 'every_page_load');
			$this->smarty->assign('is_cron', $freq !== 'manual' && $freq !== 'every_page_load');
		}

		function onsubmit($data = null) {
			if (isset($_POST['publisharticle-submit'])) {
				$options = plugin_getoptions('publisharticle');
				if (!is_array($options)) {
					$options = array();
				}

				$options['import_folder']    = isset($_POST['import_folder']) ? trim((string) $_POST['import_folder']) : '';
				$options['default_category'] = isset($_POST['default_category']) ? trim((string) $_POST['default_category']) : '';
				$options['default_status']   = isset($_POST['default_status']) ? (string) $_POST['default_status'] : 'publish';
				$options['done_subdir']      = isset($_POST['done_subdir']) ? trim((string) $_POST['done_subdir']) : 'done';
				$options['failed_subdir']    = isset($_POST['failed_subdir']) ? trim((string) $_POST['failed_subdir']) : 'failed';

				// Frequency: 'manual', 'every_page_load', or a custom cron expression
				$freq = isset($_POST['import_frequency']) ? (string) $_POST['import_frequency'] : 'every_page_load';
				if ($freq === 'custom') {
					$freq = isset($_POST['cron_schedule']) ? trim((string) $_POST['cron_schedule']) : '0 * * * *';
				}
				$options['import_frequency'] = $freq;

				// Persist using the FlatPress options API
				foreach ($options as $key => $value) {
					plugin_addoption('publisharticle', $key, $value);
				}
				plugin_saveoptions('publisharticle');
				$this->smarty->assign('success', 1);
			} else {
				$this->smarty->assign('success', -1);
			}

			return 2;
		}
	}

	admin_addpanelaction('plugin', 'publisharticle', true);
}
