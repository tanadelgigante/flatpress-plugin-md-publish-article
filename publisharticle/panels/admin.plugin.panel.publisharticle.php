<?php
/**
 * Publish Article – Publication panel
 *
 * Registered under the "plugin" admin panel.
 *
 * Provides a web upload form for:
 *   - Markdown file (.md)
 *   - One or more images
 *   - Publication date
 *
 * Plus a live preview and bulk import from folder.
 */

if (class_exists('AdminPanelAction')) {

	require_once plugin_getdir('publisharticle')
		. 'ArticleImporter.php';

	class admin_plugin_publisharticle extends AdminPanelAction {

		var $langres = 'plugin:publisharticle';

		function setup() {
			$this->smarty->assign(
				'admin_resource',
				'plugin:publisharticle/admin.plugin.publisharticle'
			);
		}

		/**
		 * Display the upload / publish form.
		 */
		function main() {

			$this->smarty->assign('msgs', array());

			// Default publication date
			$this->smarty->assign(
				'pub_date',
				date('Y-m-d\TH:i')
			);

			// Pending imports from folder
			$this->_assignPending();

			// Recent import log
			$this->_assignRecent();
		}

		/**
		 * Handle form submissions.
		 */
		function onsubmit($data = null) {

			$this->smarty->assign('msgs', array());

			/**
			 * Single-file publish / draft via upload.
			 */
			if (
				isset($_POST['publisharticle-publish']) ||
				isset($_POST['publisharticle-draft'])
			) {
				$this->_handleUpload();
			}

			/**
			 * Bulk import from folder.
			 */
			if (isset($_POST['publisharticle-import-now'])) {
				$this->_handleBulkImport();
			}

			// Refresh data
			$this->_assignPending();
			$this->_assignRecent();
			$this->smarty->assign(
				'pub_date',
				date('Y-m-d\TH:i')
			);

			return 2;
		}

		// ── Private helpers ───────────────────────────────────

		/**
		 * Handle uploaded Markdown + images, publish or draft.
		 */
		private function _handleUpload() {

			// ── Validate MD file upload ──
			if (
				!isset($_FILES['md_file']) ||
				$_FILES['md_file']['error'] !== UPLOAD_ERR_OK
			) {
				$this->smarty->assign('success', -1);
				return;
			}

			$tmpMd = $_FILES['md_file']['tmp_name'];
			$origName = $_FILES['md_file']['name'];

			$ext = strtolower(
				pathinfo($origName, PATHINFO_EXTENSION)
			);

			if (!in_array($ext, array('md', 'markdown', 'mdown', 'txt'))) {
				$this->smarty->assign('success', -1);
				return;
			}

			$mdContent = file_get_contents($tmpMd);
			if ($mdContent === false) {
				$this->smarty->assign('success', -1);
				return;
			}

			// ── Status ──
			$isDraft = isset($_POST['publisharticle-draft']);
			$status = $isDraft ? 'draft' : 'publish';

			// ── Date override ──
			$pubDate = '';
			if (
				isset($_POST['publish_now']) &&
				$_POST['publish_now'] === 'on'
			) {
				// Use now
			} elseif (
				!empty($_POST['pub_date'])
			) {
				$pubDate = $_POST['pub_date'];
			}

			// ── Handle image uploads ──
			$importedImages = array();

			if (
				isset($_FILES['images']) &&
				!empty($_FILES['images']['name'][0])
			) {

				$imgUploader = new ImageUploader();
				$count = count($_FILES['images']['name']);

				for ($i = 0; $i < $count; $i++) {

					if (
						$_FILES['images']['error'][$i] !== UPLOAD_ERR_OK
					) {
						continue;
					}

					$rel = $imgUploader->upload(
						array(
							'name'     => $_FILES['images']['name'][$i],
							'type'     => $_FILES['images']['type'][$i],
							'tmp_name' => $_FILES['images']['tmp_name'][$i],
							'error'    => $_FILES['images']['error'][$i],
							'size'     => $_FILES['images']['size'][$i],
						),
						$baseName
					);

					if ($rel !== false) {
						$importedImages[] = $rel;
					}
				}
			}

			// ── Build frontmatter + content ──
			$overrides = array(
				'status' => $status,
			);

			if ($pubDate !== '') {
				$dt = str_replace('T', ' ', $pubDate) . ':00';
				$overrides['pubdate'] = $dt;
			}

			$mdContent = $this->_injectOverrides(
				$mdContent,
				$overrides
			);

			// Prepend image references to body if any
			if (!empty($importedImages)) {
				$imgTags = '';
				foreach ($importedImages as $img) {
					$imgTags .= '![image](' . $img . ")\n";
				}
				$mdContent .= "\n" . $imgTags;
			}

			// ── Process article ──
			$options = plugin_getoptions('publisharticle');
			if (!is_array($options)) {
				$options = array();
			}

			$baseName = pathinfo(
				$origName, PATHINFO_FILENAME
			);

			$processor = new ArticleProcessor();

			$result = $processor->process(
				$mdContent,
				array(),
				$baseName
			);

			if ($result === false) {
				$this->smarty->assign('success', -1);
				return;
			}

			$this->smarty->assign('success', 1);
		}

		/**
		 * Bulk-import all pending files from the import folder.
		 */
		private function _handleBulkImport() {

			$options = plugin_getoptions('publisharticle');
			if (!is_array($options)) {
				$options = array();
			}

			$importDir = !empty($options['import_folder'])
				? $options['import_folder']
				: '';

			if ($importDir === '' || !is_dir($importDir)) {
				$this->smarty->assign('success', -1);
				return;
			}

			$importer = new ArticleImporter(
				null, $importDir, $options
			);

			$results = $importer->importAll();

			$ok   = 0;
			$fail = 0;

			if (is_array($results)) {
				foreach ($results as $r) {
					if (!empty($r['success'])) {
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
		 * Inject overrides into the Markdown frontmatter.
		 */
		private function _injectOverrides(
			$content,
			$overrides
		) {
			$inject = array();

			if (!empty($overrides['status'])) {
				$inject[] = 'status: ' . $overrides['status'];
			}

			if (!empty($overrides['pubdate'])) {
				$inject[] = 'date: ' . $overrides['pubdate'];
			}

			if (empty($inject)) {
				return $content;
			}

			if (
				preg_match(
					'/^(---\s*\n.*?\n---\s*\n?)/s',
					$content,
					$m
				)
			) {
				$frontmatter = $m[1];
				foreach ($inject as $line) {
					list($key) = explode(':', $line, 2);
					if (
						!preg_match(
							'/^' . preg_quote($key, '/') . '\s*:/mi',
							$frontmatter
						)
					) {
						$frontmatter = preg_replace(
							'/\n---\s*$/s',
							"\n" . $line . "\n---",
							$frontmatter
						);
					}
				}
				return $frontmatter
					. substr($content, strlen($m[1]));
			}

			return "---\n"
				. implode("\n", $inject)
				. "\n---\n\n"
				. $content;
		}

		/**
		 * Assign pending file count for the folder.
		 */
		private function _assignPending() {

			$options = plugin_getoptions('publisharticle');
			if (!is_array($options)) {
				$options = array();
			}

			$importDir = !empty($options['import_folder'])
				? $options['import_folder']
				: '';

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
					is_array($pending)
						? count($pending)
						: 0
				);

			} else {

				$this->smarty->assign('pending_count', 0);
			}
		}

		/**
		 * Assign recent import log entries.
		 */
		private function _assignRecent() {

			$options = plugin_getoptions('publisharticle');
			if (!is_array($options)) {
				$options = array();
			}

			$this->smarty->assign(
				'recent_imports',
				$this->_getRecentImports($options)
			);
		}

		/**
		 * Scan done/failed subdirs for recently imported files.
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

			$dirs = array(
				$importDir . '/' . $doneSubdir,
				$importDir . '/' . $failedSubdir,
			);

			foreach ($dirs as $dir) {

				if (!is_dir($dir)) {
					continue;
				}

				$files = glob($dir . '/*');

				if (!is_array($files)) {
					continue;
				}

				usort(
					$files,
					function ($a, $b) {
						return filemtime($b)
							- filemtime($a);
					}
				);

				$files = array_slice($files, 0, 20);

				foreach ($files as $f) {

					if (!is_file($f)) {
						continue;
					}

					$base = basename($f);
					$success = (
						strpos($dir, $doneSubdir) !== false
					);

					$note = '';
					$noteFile = $f . '.note';
					if (is_file($noteFile)) {
						$note = file_get_contents($noteFile);
					}

					$imports[] = array(
						'file'    => $base,
						'success' => $success,
						'error'   => $note,
						'time'    => date(
							'Y-m-d H:i',
							filemtime($f)
						),
					);
				}
			}

			return $imports;
		}
	}

	admin_addpanelaction(
		'plugin',
		'publisharticle',
		true
	);
}
