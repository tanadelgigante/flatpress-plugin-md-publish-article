<?php
/**
 * Publish Article – Configuration panel
 *
 * Registered under the "plugin" admin panel.
 *
 * ---------------------------------------------------------------------------
 * MAINTENANCE NOTES (WORKPLAN Phase 5 / R18):
 *
 * FlatPress API used:
 *   - AdminPanelAction base class + admin_addpanelaction() registration
 *   - plugin_getoptions(), plugin_addoption(), plugin_saveoptions()
 *   - publisharticle_valid_cron() for the custom cron expression
 *
 * Options handled here (all saved through the FlatPress plugin API):
 *   - import_folder, import_frequency (+ custom cron), default_category,
 *     default_status, done_subdir, failed_subdir
 *   - log_level (Task 5.2): 'debug' | 'info' | 'warn', default 'info'.
 *     The value is normalized via PublishArticleLogger::normalizeLevel()
 *     when the logger class is available, otherwise it falls back to 'info'.
 * ---------------------------------------------------------------------------
 */

if (class_exists('AdminPanelAction')) {

	require_once plugin_getdir('publisharticle')
		. 'PublishArticleLogger.php';

	/**
	 * FlatPress action class.
	 *
	 * The class name MUST follow:
	 *
	 *   AdminPanel_plugin_pubartcfg
	 *
	 * because FlatPress builds the action class name from:
	 *
	 *   get_class($this) . '_' . $action
	 */
	class admin_plugin_pubartcfg extends AdminPanelAction {

		var $langres = 'plugin:publisharticle';

		/**
		 * Configure Smarty resource.
		 */
		function setup() {
			$this->smarty->assign(
				'admin_resource',
				'plugin:publisharticle/admin.plugin.panel.pubartcfg'
			);
		}

		/**
		 * Display configuration page.
		 */
		function main() {

			$this->smarty->assign('msgs', array());

			$defaults = array(
				'import_folder'     => '',
				'import_frequency'  => 'every_page_load',
				'default_category'  => '',
				'default_status'    => 'publish',
				'done_subdir'       => 'done',
				'failed_subdir'     => 'failed',
				'log_level'         => 'info',
			);

			$options = plugin_getoptions('publisharticle');

			if (!is_array($options)) {
				$options = array();
			}

			$options = array_merge($defaults, $options);

			// Make all options available to Smarty.
			foreach ($options as $key => $value) {
				$this->smarty->assign($key, $value);
			}

			// Frequency helpers for the template.
			$freq = $options['import_frequency'];

			$is_cron = (
				$freq !== 'manual' &&
				$freq !== 'every_page_load'
			);

			$this->smarty->assign(
				'is_manual',
				$freq === 'manual'
			);

			$this->smarty->assign(
				'is_every_page_load',
				$freq === 'every_page_load'
			);

			$this->smarty->assign(
				'is_cron',
				$is_cron
			);

			$this->smarty->assign(
				'cron_schedule',
				$is_cron ? $freq : '0 * * * *'
			);
		}

		/**
		 * Handle configuration form submission.
		 *
		 * @param mixed $data
		 * @return int|null
		 */
		function onsubmit($data = null) {

			$this->smarty->assign('msgs', array());

			if (!isset($_POST['publisharticle-config-submit'])) {
				return;
			}

			$options = plugin_getoptions('publisharticle');

			if (!is_array($options)) {
				$options = array();
			}

			// Import folder.
			$options['import_folder'] = isset($_POST['import_folder'])
				? trim($_POST['import_folder'])
				: '';

			// Default category.
			$options['default_category'] = isset($_POST['default_category'])
				? trim($_POST['default_category'])
				: '';

			// Default status.
			$options['default_status'] = isset($_POST['default_status'])
				? $_POST['default_status']
				: 'publish';

			// Done directory.
			$options['done_subdir'] = isset($_POST['done_subdir'])
				? trim($_POST['done_subdir'])
				: 'done';

			// Failed directory.
			$options['failed_subdir'] = isset($_POST['failed_subdir'])
				? trim($_POST['failed_subdir'])
				: 'failed';

			// Import frequency.
			$freq = isset($_POST['import_frequency'])
				? $_POST['import_frequency']
				: 'every_page_load';

			/**
			 * Custom cron expression.
			 */
			if ($freq === 'custom') {

				$cron = isset($_POST['cron_schedule'])
					? trim($_POST['cron_schedule'])
					: '';

				if (!publisharticle_valid_cron($cron)) {

					$this->smarty->assign(
						'success',
						-1
					);

					return 2;
				}

				$freq = $cron;
			}

			$options['import_frequency'] = $freq;

			// Log level (Task 5.2). Normalized so a tampered/hand-edited
			// request cannot inject an invalid threshold.
			if (class_exists('PublishArticleLogger')) {
				$options['log_level'] = PublishArticleLogger::normalizeLevel(
					isset($_POST['log_level']) ? $_POST['log_level'] : 'info'
				);
			} else {
				$options['log_level'] = 'info';
			}

			// Save all options using the FlatPress plugin API.
			foreach ($options as $key => $value) {
				plugin_addoption(
					'publisharticle',
					$key,
					$value
				);
			}

			plugin_saveoptions('publisharticle');

			$this->smarty->assign(
				'success',
				1
			);

			// Reassign values so the form reflects saved state.
			foreach ($options as $key => $value) {
				$this->smarty->assign(
					$key,
					$value
				);
			}

			$freq2 = $options['import_frequency'];

			$is_cron2 = (
				$freq2 !== 'manual' &&
				$freq2 !== 'every_page_load'
			);

			$this->smarty->assign(
				'is_manual',
				$freq2 === 'manual'
			);

			$this->smarty->assign(
				'is_every_page_load',
				$freq2 === 'every_page_load'
			);

			$this->smarty->assign(
				'is_cron',
				$is_cron2
			);

			$this->smarty->assign(
				'cron_schedule',
				$is_cron2 ? $freq2 : '0 * * * *'
			);

			return 2;
		}
	}

	/**
	 * Register the action in the plugin admin panel.
	 */
	admin_addpanelaction(
		'plugin',
		'pubartcfg',
		true
	);
}