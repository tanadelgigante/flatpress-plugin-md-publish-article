<?php
/**
 * Publish Article – Configuration panel (Plugin menu)
 *
 * Registers under "plugin" so it appears in the Plugins section of FlatPress admin.
 */

if (class_exists('AdminPanelAction')) {

	class admin_publisharticle_config extends AdminPanelAction {
		var $langres = 'plugin:publisharticle';

		function setup() {
			$this->smarty->assign('admin_resource', 'plugin:publisharticle/admin.plugin.panel.publisharticlecfg');
		}

		function main() {
			$this->smarty->assign('msgs', array());

			$defaults = array(
				'import_folder'    => '',
				'import_frequency' => 'every_page_load',
				'default_category' => '',
				'default_status'   => 'publish',
				'done_subdir'      => 'done',
				'failed_subdir'    => 'failed',
			);

			$options = plugin_getoptions('publisharticle');
			$options = is_array($options) ? array_merge($defaults, $options) : $defaults;

			foreach ($options as $key => $value) {
				$this->smarty->assign($key, $value);
			}

			// Frequency helpers for the template
			$freq = $options['import_frequency'];
			$is_cron = ($freq !== 'manual' && $freq !== 'every_page_load');
			$this->smarty->assign('is_manual', $freq === 'manual');
			$this->smarty->assign('is_every_page_load', $freq === 'every_page_load');
			$this->smarty->assign('is_cron', $is_cron);
			$this->smarty->assign('cron_schedule', $is_cron ? $freq : '0 * * * *');
		}

		function onsubmit($data = null) {
			$this->smarty->assign('msgs', array());

			if (isset($_POST['publisharticle-config-submit'])) {
				$options = plugin_getoptions('publisharticle');
				if (!is_array($options)) {
					$options = array();
				}

				$options['import_folder']    = isset($_POST['import_folder']) ? trim($_POST['import_folder']) : '';
				$options['default_category'] = isset($_POST['default_category']) ? trim($_POST['default_category']) : '';
				$options['default_status']   = isset($_POST['default_status']) ? $_POST['default_status'] : 'publish';
				$options['done_subdir']      = isset($_POST['done_subdir']) ? trim($_POST['done_subdir']) : 'done';
				$options['failed_subdir']    = isset($_POST['failed_subdir']) ? trim($_POST['failed_subdir']) : 'failed';

				// Frequency
				$freq = isset($_POST['import_frequency']) ? $_POST['import_frequency'] : 'every_page_load';
				if ($freq === 'custom') {
					$cron = isset($_POST['cron_schedule']) ? trim($_POST['cron_schedule']) : '';
					if (!publisharticle_valid_cron($cron)) {
						$this->smarty->assign('success', -1);
						return 2;
					}
					$freq = $cron;
				}
				$options['import_frequency'] = $freq;

				// Save via FlatPress API
				foreach ($options as $key => $value) {
					plugin_addoption('publisharticle', $key, $value);
				}
				plugin_saveoptions('publisharticle');
				$this->smarty->assign('success', 1);

				// Re-assign values so the form reflects saved state
				foreach ($options as $key => $value) {
					$this->smarty->assign($key, $value);
				}
				$freq2 = $options['import_frequency'];
				$is_cron2 = ($freq2 !== 'manual' && $freq2 !== 'every_page_load');
				$this->smarty->assign('is_manual', $freq2 === 'manual');
				$this->smarty->assign('is_every_page_load', $freq2 === 'every_page_load');
				$this->smarty->assign('is_cron', $is_cron2);
				$this->smarty->assign('cron_schedule', $is_cron2 ? $freq2 : '0 * * * *');

				return 2;
			}
		}
	}

	admin_addpanelaction('plugin', 'publisharticlecfg', true);
}
