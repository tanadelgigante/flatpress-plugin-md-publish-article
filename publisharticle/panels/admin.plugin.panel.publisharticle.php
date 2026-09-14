<?php
/**
 * Publish Article – Publication panel (Articoli/Content menu)
 *
 * Shows import folder status, pending files count, and a button
 * to trigger a manual import.
 */

if (class_exists('AdminPanelAction')) {
	require_once plugin_getdir('publisharticle') . 'ArticleImporter.php';

	class admin_plugin_publisharticle extends AdminPanelAction {
		var $langres = 'plugin:publisharticle';

		function setup() {
			$this->smarty->assign('admin_resource', 'plugin:publisharticle/admin.plugin.publisharticle');
		}

		function main() {
			$this->smarty->assign('msgs', array());

			$options = plugin_getoptions('publisharticle');
			if (!is_array($options)) {
				$options = array();
			}

			// Folder info
			if (!empty($options['import_folder'])) {
				$this->smarty->assign('import_folder_path', $options['import_folder']);
				$importer = new ArticleImporter(null, $options['import_folder'], $options);
				$protection = $importer->checkProtection();
				$this->smarty->assign('import_folder_status', $protection);
				$this->smarty->assign('pending_count', count($importer->scan()));
			} else {
				$this->smarty->assign('pending_count', 0);
			}

			// Recent import log (from last 10 entries in done/failed subdirs)
			$this->smarty->assign('recent_imports', $this->_getRecentImports($options));
		}

		function onsubmit($data = null) {
			$this->smarty->assign('msgs', array());

			if (isset($_POST['publisharticle-import-now'])) {
				$options = plugin_getoptions('publisharticle');
				if (!is_array($options)) {
					$options = array();
				}

				$importDir = !empty($options['import_folder']) ? $options['import_folder'] : '';
				if ($importDir === '') {
					$this->smarty->assign('success', -1);
					return 2;
				}

				$importer = new ArticleImporter(null, $importDir, $options);
				$results  = $importer->importAll();

				$ok  = 0;
				$fail = 0;
				if (is_array($results)) {
					foreach ($results as $r) {
						if (isset($r['success']) && $r['success']) {
							$ok++;
						} else {
							$fail++;
						}
					}
				}

				$this->smarty->assign('success', 1);
				$this->smarty->assign('import_ok', $ok);
				$this->smarty->assign('import_fail', $fail);

				// Re-assign folder info for the view
				$this->smarty->assign('import_folder_path', $importDir);
				$protection = $importer->checkProtection();
				$this->smarty->assign('import_folder_status', $protection);
				$this->smarty->assign('pending_count', count($importer->scan()));
				$this->smarty->assign('recent_imports', $this->_getRecentImports($options));

				return 2;
			}
		}

		/**
		 * Scan done/failed subdirs for recently imported files.
		 * @return array
		 */
		private function _getRecentImports($options) {
			$imports = array();
			$importDir = isset($options['import_folder']) ? $options['import_folder'] : '';
			if ($importDir === '' || !is_dir($importDir)) {
				return $imports;
			}

			$doneDir    = rtrim($importDir, '/') . '/' . (isset($options['done_subdir']) ? $options['done_subdir'] : 'done');
			$failedDir  = rtrim($importDir, '/') . '/' . (isset($options['failed_subdir']) ? $options['failed_subdir'] : 'failed');

			// Collect from done/ (success)
			if (is_dir($doneDir)) {
				foreach (glob($doneDir . '/{*.md,*.markdown,*.mdown,*.txt}', GLOB_BRACE) as $f) {
					$imports[] = array(
						'file'    => basename($f),
						'success' => true,
						'error'   => '',
						'time'    => date('Y-m-d H:i:s', filemtime($f)),
					);
				}
			}

			// Collect from failed/ (failure)
			if (is_dir($failedDir)) {
				foreach (glob($failedDir . '/{*.md,*.markdown,*.mdown,*.txt}', GLOB_BRACE) as $f) {
					$imports[] = array(
						'file'    => basename($f),
						'success' => false,
						'error'   => 'import failed',
						'time'    => date('Y-m-d H:i:s', filemtime($f)),
					);
				}
			}

			// Sort by time descending, limit to 20
			usort($imports, function ($a, $b) {
				return strcmp($b['time'], $a['time']);
			});
			return array_slice($imports, 0, 20);
		}
	}

	admin_addpanelaction('content', 'publisharticle', true);
}
