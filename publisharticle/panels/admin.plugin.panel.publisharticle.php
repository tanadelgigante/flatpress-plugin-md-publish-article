<?php
/**
 * Publish Article – Publication panel
 *
 * Registered under the "content" admin panel.
 *
 * Shows:
 *   - import folder
 *   - folder status
 *   - pending files
 *   - recent imports
 *   - manual import button
 */

if (class_exists('AdminPanelAction')) {

	require_once plugin_getdir('publisharticle')
		. 'ArticleImporter.php';

	/**
	 * FlatPress action class.
	 *
	 * The class name MUST follow:
	 *
	 *   AdminPanel_content_publisharticle
	 *
	 * because FlatPress builds the action class name from:
	 *
	 *   get_class($this) . '_' . $action
	 */
	class AdminPanel_content_publisharticle extends AdminPanelAction {

		var $langres = 'plugin:publisharticle';

		/**
		 * Configure Smarty resource.
		 */
		function setup() {
			$this->smarty->assign(
				'admin_resource',
				'plugin:publisharticle/admin.plugin.publisharticle'
			);
		}

		/**
		 * Display publication/import page.
		 */
		function main() {

			$this->smarty->assign(
				'msgs',
				array()
			);

			$options = plugin_getoptions('publisharticle');

			if (!is_array($options)) {
				$options = array();
			}

			/**
			 * Import folder information.
			 */
			if (!empty($options['import_folder'])) {

				$this->smarty->assign(
					'import_folder_path',
					$options['import_folder']
				);

				$importer = new ArticleImporter(
					null,
					$options['import_folder'],
					$options
				);

				$protection = $importer->checkProtection();

				$this->smarty->assign(
					'import_folder_status',
					$protection
				);

				$pending = $importer->scan();

				$this->smarty->assign(
					'pending_count',
					is_array($pending) ? count($pending) : 0
				);

			} else {

				$this->smarty->assign(
					'pending_count',
					0
				);
			}

			/**
			 * Recent import log.
			 *
			 * Reads the done/failed subdirectories.
			 */
			$this->smarty->assign(
				'recent_imports',
				$this->_getRecentImports($options)
			);
		}

		/**
		 * Handle manual import.
		 *
		 * @param mixed $data
		 * @return int|null
		 */
		function onsubmit($data = null) {

			$this->smarty->assign(
				'msgs',
				array()
			);

			if (!isset($_POST['publisharticle-import-now'])) {
				return;
			}

			$options = plugin_getoptions('publisharticle');

			if (!is_array($options)) {
				$options = array();
			}

			$importDir = !empty($options['import_folder'])
				? $options['import_folder']
				: '';

			/**
			 * No import directory configured.
			 */
			if ($importDir === '') {

				$this->smarty->assign(
					'success',
					-1
				);

				return 2;
			}

			/**
			 * Execute import.
			 */
			$importer = new ArticleImporter(
				null,
				$importDir,
				$options
			);

			$results = $importer->importAll();

			$ok   = 0;
			$fail = 0;

			if (is_array($results)) {

				foreach ($results as $result) {

					if (
						isset($result['success']) &&
						$result['success']
					) {
						$ok++;
					} else {
						$fail++;
					}
				}
			}

			/**
			 * Report result.
			 */
			$this->smarty->assign(
				'success',
				1
			);

			$this->smarty->assign(
				'import_ok',
				$ok
			);

			$this->smarty->assign(
				'import_fail',
				$fail
			);

			/**
			 * Refresh folder information.
			 */
			$this->smarty->assign(
				'import_folder_path',
				$importDir
			);

			$protection = $importer->checkProtection();

			$this->smarty->assign(
				'import_folder_status',
				$protection
			);

			$pending = $importer->scan();

			$this->smarty->assign(
				'pending_count',
				is_array($pending) ? count($pending) : 0
			);

			$this->smarty->assign(
				'recent_imports',
				$this->_getRecentImports($options)
			);

			return 2;
		}

		/**
		 * Scan done/failed subdirectories for recently
		 * imported files.
		 *
		 * @param array $options
		 * @return array
		 */
		private function _getRecentImports($options) {

			$imports = array();

			$importDir = isset($options['import_folder'])
				? $options['import_folder']
				: '';

			if (
				$importDir === '' ||
				!is_dir($importDir)
			) {
				return $imports;
			}

			$doneSubdir = isset($options['done_subdir'])
				? $options['done_subdir']
				: 'done';

			$failedSubdir = isset($options['failed_subdir'])
				? $options['failed_subdir']
				: 'failed';

			$doneDir = rtrim(
				$importDir,
				'/'
			) . '/' . $doneSubdir;

			$failedDir = rtrim(
				$importDir,
				'/'
			) . '/' . $failedSubdir;

			/**
			 * Successful imports.
			 */
			if (is_dir($doneDir)) {

				foreach (
					glob(
						$doneDir . '/{*.md,*.markdown,*.mdown,*.txt}',
						GLOB_BRACE
					) as $file
				) {

					$imports[] = array(
						'file' => basename($file),
						'success' => true,
						'error' => '',
						'time' => date(
							'Y-m-d H:i:s',
							filemtime($file)
						),
					);
				}
			}

			/**
			 * Failed imports.
			 */
			if (is_dir($failedDir)) {

				foreach (
					glob(
						$failedDir . '/{*.md,*.markdown,*.mdown,*.txt}',
						GLOB_BRACE
					) as $file
				) {

					$imports[] = array(
						'file' => basename($file),
						'success' => false,
						'error' => 'import failed',
						'time' => date(
							'Y-m-d H:i:s',
							filemtime($file)
						),
					);
				}
			}

			/**
			 * Newest first.
			 */
			usort(
				$imports,
				function ($a, $b) {
					return strcmp(
						$b['time'],
						$a['time']
					);
				}
			);

			/**
			 * Limit output.
			 */
			return array_slice(
				$imports,
				0,
				20
			);
		}
	}

	/**
	 * Register publication action under the
	 * FlatPress "content" panel.
	 */
	admin_addpanelaction(
		'content',
		'publisharticle',
		true
	);
}