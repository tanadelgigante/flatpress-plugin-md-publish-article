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

		private $processor = null;

		private function _getProcessor() {
			if ($this->processor === null) {
				$this->processor = new ArticleProcessor();
			}
			return $this->processor;
		}

		/**
		 * Display the upload / publish form.
		 */
		function main() {

			$this->smarty->assign('msgs', array());



			$this->_assignPending();
			$this->_assignRecent();
		}

		/**
		 * Handle form submissions.
		 */
		function onsubmit($data = null) {

			$this->smarty->assign('msgs', array());

			if (
				isset($_POST['publisharticle-publish']) ||
				isset($_POST['publisharticle-draft'])
			) {
				$this->_handleUpload();
			}

			if (isset($_POST['publisharticle-import-now'])) {
				$this->_handleBulkImport();
			}

			$this->_assignPending();
			$this->_assignRecent();

		}

		// ── Private helpers ───────────────────────────────────

		/**
		 * Handle uploaded Markdown + images, publish or draft.
		 *
		 * Images are saved with their ORIGINAL name (sanitized)
		 * so that Markdown references like (plugin.png) work.
		 * We also rewrite any reference in the Markdown body
		 * to add the images/ prefix when needed.
		 */
		private function _handleUpload() {

			// ── Validate MD file ──

			if (
				!isset($_FILES['md_file']) ||
				$_FILES['md_file']['error'] !== UPLOAD_ERR_OK
			) {
				$this->smarty->assign('success', -1);
				return;
			}

			$tmpMd    = $_FILES['md_file']['tmp_name'];
			$origName = $_FILES['md_file']['name'];
			$ext      = strtolower(
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

			// ── Check if future scheduled and not publish_now ──
			$parser = new ArticleParser();
			$parsed = $parser->parseMarkdown($mdContent);
			$composer = new ArticleComposer();
			$scheduleTs = $composer->extractScheduleDate($parsed['properties'], time());
			$publishNow = isset($_POST['publish_now']) && $_POST['publish_now'] === 'on';

			if (!$publishNow && $scheduleTs !== null) {
				$options = plugin_getoptions('publisharticle');
				$importDir = !empty($options['import_folder']) ? $options['import_folder'] : (defined('CONTENT_DIR') ? CONTENT_DIR . 'import-in/' : 'fp-content/content/import-in/');
				
				if (!is_dir($importDir)) {
					mkdir($importDir, 0755, true);
				}

				$destMd = rtrim($importDir, '/\\') . '/' . $origName;
				move_uploaded_file($tmpMd, $destMd);

				if (
					isset($_FILES['images']) &&
					!empty($_FILES['images']['name'][0])
				) {
					$count = count($_FILES['images']['name']);
					for ($i = 0; $i < $count; $i++) {
						if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
							$imgName = basename($_FILES['images']['name'][$i]);
							$safeImgName = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $imgName);
							move_uploaded_file($_FILES['images']['tmp_name'][$i], rtrim($importDir, '/\\') . '/' . $safeImgName);
						}
					}
				}

				$this->smarty->assign('success', 1);
				return;
			}

			// ── Status & date ──

			$isDraft = isset($_POST['publisharticle-draft']);
			$status  = $isDraft ? 'draft' : 'publish';


			// ── Upload images (original name, sanitized) ──

			$imageMap = array();

			if (
				isset($_FILES['images']) &&
				!empty($_FILES['images']['name'][0])
			) {
				$imgDir = defined('IMAGES_DIR') ? IMAGES_DIR : 'fp-content/images';

				if (!is_dir($imgDir)) {
					mkdir($imgDir, 0755, true);
				}

				$count = count($_FILES['images']['name']);

				for ($i = 0; $i < $count; $i++) {

					if (
						$_FILES['images']['error'][$i]
						!== UPLOAD_ERR_OK
					) {
						continue;
					}

					$rawName  = basename(
						$_FILES['images']['name'][$i]
					);
					$safeName = preg_replace(
						'/[^a-zA-Z0-9_.\-]/',
						'_',
						$rawName
					);
					$dest = $imgDir . '/' . $safeName;

					if (file_exists($dest)) {
						$safeName = 'img_'
							. date('Ymd_His')
							. '_' . $safeName;
						$dest = $imgDir . '/' . $safeName;
					}

					if (
						move_uploaded_file(
							$_FILES['images']['tmp_name'][$i],
							$dest
						)
					) {
						$imageMap[$rawName] = 'images/' . $safeName;
					}
				}
			}

			// ── Fix Markdown image references ──
			// (plugin.png) → (images/plugin.png)
			// (plugin.png width=500) → (images/plugin.png width=500)

			foreach ($imageMap as $orig => $rel) {

				$escaped = preg_quote($orig, '/');

				$mdContent = preg_replace(
					'/\(' . $escaped . '(\s+[^)]*)?\)/i',
					'(' . $rel . '$1)',
					$mdContent
				);
			}

			// ── Inject status / date into frontmatter ──

			$overrides = array('status' => $status);



			$mdContent = $this->_injectOverrides(
				$mdContent,
				$overrides
			);

			// ── Process ──

			$baseName = pathinfo($origName, PATHINFO_FILENAME);
			$processor = $this->_getProcessor();
			$result    = $processor->process(
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
		 * Bulk-import from the configured import folder.
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
		private function _injectOverrides($content, $overrides) {

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

			$re = '/^(---\s*\n.*?\n---\s*\n?)/s';

			if (preg_match($re, $content, $m)) {

				$frontmatter = $m[1];

				foreach ($inject as $line) {
					list($key) = explode(':', $line, 2);
					$pat = '/^'
						. preg_quote($key, '/')
						. '\s*:/mi';

					if (!preg_match($pat, $frontmatter)) {
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
		 * Assign pending file count.
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
					is_array($pending) ? count($pending) : 0
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
		 * Scan done/failed subdirs for recent imports.
		 */
		private function _getRecentImports($options) {

			$imports = array();

			$importDir = isset($options['import_folder'])
				? $options['import_folder']
				: '';

			if ($importDir === '' || !is_dir($importDir)) {
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

				usort($files, function ($a, $b) {
					return filemtime($b) - filemtime($a);
				});

				$files = array_slice($files, 0, 20);

				foreach ($files as $f) {

					if (!is_file($f)) {
						continue;
					}

					$base    = basename($f);
					$success = (strpos($dir, $doneSubdir) !== false);

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

	admin_addpanelaction('plugin', 'publisharticle', true);
}
