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
	class admin_plugin_publisharticle extends AdminPanelAction {

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

			$this->smarty->assign('msgs', array());

			$options = plugin_getoptions('publisharticle');
			if (!is_array($options)) {
				$options = array();
			}

			$importDir = !empty($options['import_folder'])
				? $options['import_folder']
				: '';

			/**
			 * Handle single-file publish or draft.
			 */
			if (
				isset($_POST['publisharticle-publish']) ||
				isset($_POST['publisharticle-draft'])
			) {

				if ($importDir === '' || !is_dir($importDir)) {
					$this->smarty->assign('success', -1);
					return 2;
				}

				$mdFile = isset($_POST['md_file'])
					? trim((string) $_POST['md_file'])
					: '';

				if ($mdFile === '') {
					$this->smarty->assign('success', -1);
					return 2;
				}

				$fullPath = rtrim($importDir, '/')
					. '/' . $mdFile;

				if (!is_file($fullPath)) {
					$this->smarty->assign('success', -1);
					return 2;
				}

				$isDraft = isset(
					$_POST['publisharticle-draft']
				);

				$pubDate = isset($_POST['pub_date'])
					? $_POST['pub_date']
					: '';

				$images = isset($_POST['images'])
					? array_map(
						'trim',
						(array) $_POST['images']
					)
					: array();

				$importer = new ArticleImporter(
					null, $importDir, $options
				);

				$result = $importer->importOne(
					$fullPath,
					array(
						'status' => $isDraft
							? 'draft'
							: 'publish',
						'pubdate' => $pubDate,
						'images' => $images,
					)
				);

				if (
					isset($result['success']) &&
					$result['success']
				) {
					$this->smarty->assign('success', 1);
				} else {
					$this->smarty->assign('success', -1);
				}
			}

			/**
			 * Handle bulk import.
			 */
			if (isset($_POST['publisharticle-import-now'])) {

				if ($importDir === '' || !is_dir($importDir)) {
					$this->smarty->assign('success', -1);
					return 2;
				}

				$importer = new ArticleImporter(
					null, $importDir, $options
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

				$this->smarty->assign('success', 1);
				$this->smarty->assign('import_ok', $ok);
				$this->smarty->assign('import_fail', $fail);
			}

			/**
			 * Re-scan and refresh all folder data
			 * for the template.
			 */
			if ($importDir !== '' && is_dir($importDir)) {

				$this->smarty->assign(
					'import_folder_path',
					$importDir
				);

				$importer = new ArticleImporter(
					null, $importDir, $options
				);

				$this->smarty->assign(
					'import_folder_status',
					$importer->checkProtection()
				);

				$pending = $importer->scan();

				$this->smarty->assign(
					'pending_count',
					is_array($pending) ? count($pending) : 0
				);

				$this->smarty->assign(
					'md_files',
					$this->_scanFiles(
						$importDir,
						array('md', 'markdown', 'mdown', 'txt')
					)
				);

				$this->smarty->assign(
					'image_files',
					$this->_scanFiles(
						$importDir,
						array('jpg', 'jpeg', 'png', 'gif', 'webp')
					)
				);
			}

			$this->smarty->assign(
				'pub_date',
				date('Y-m-d\TH:i')
			);

			$this->smarty->assign(
				'recent_imports',
				$this->_getRecentImports($options)
			);

			return 2;
		}

		/**
		 * Scan a directory for files with given extensions.
		 *
		 * @param string $dir
		 * @param array $extensions
		 * @return array
		 */
		private function _scanFiles($dir, $extensions) {

			$files = array();

			if (!is_dir($dir)) {
				return $files;
			}

			$extPattern = '*.{'
				. implode(',', $extensions)
				. '}';

			foreach (
				glob(
					$dir . '/' . $extPattern,
					GLOB_BRACE
				) as $f
			) {
				$files[] = basename($f);
			}

			sort($files);
			return $files;
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
	 * FlatPress "plugin" panel.
	 */
	admin_addpanelaction(
		'plugin',
		'publisharticle',
		true
	);
}