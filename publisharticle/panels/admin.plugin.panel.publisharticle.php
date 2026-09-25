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
 *
 * ---------------------------------------------------------------------------
 * MAINTENANCE NOTES (WORKPLAN Phase 5 / R18):
 *
 * FlatPress API used:
 *   - AdminPanelAction base class + admin_addpanelaction() registration
 *   - plugin_getdir(), plugin_getoptions(), $this->smarty->assign()
 *   - ArticleImporter/ArticleProcessor for the actual import work
 *
 * Edge cases handled here:
 *   - a single "images" POST field is normalized from scalar to the array
 *     form, otherwise count() would raise a TypeError on PHP >= 8 (legacy
 *     clients that do not post images[] as an array);
 *   - future-dated articles (not "publish now", not a draft) are saved into
 *     the import folder so the cron importer picks them up when due;
 *   - image file names are sanitized with [^a-zA-Z0-9_.\-] -> '_' before use;
 *   - the images dir comes from ImageUploader::getImagesDir(), which prefixes
 *     the absolute ABS_PATH when available (R22 regression fix: IMAGES_DIR is
 *     blog-root-relative on FlatPress 1.5.x / 1.4.x);
 *   - a failed move_uploaded_file() falls back to copy() and, on total
 *     failure, is reported at WARN level (R22: previously silent).
 *
 * Logging (PublishArticleLogger, Task 5.2): filesystem failures while saving
 * scheduled articles/images to the import folder are reported at WARN/ERROR
 * level (the old bare error_log('publisharticle: ...') calls). Everything
 * else stays silent: the panel only surfaces feedback via Smarty messages.
 * ---------------------------------------------------------------------------
 */

if (class_exists('AdminPanelAction')) {

	require_once plugin_getdir('publisharticle')
		. 'ArticleImporter.php';
	require_once plugin_getdir('publisharticle')
		. 'PublishArticleLogger.php';
	require_once plugin_getdir('publisharticle')
		. 'ImageUploader.php';

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

			// ── Normalize the images upload field ──
			// The form posts this field as images[] (array). A client sending a
			// single "images" field makes PHP populate $_FILES['images'] with
			// scalar values, so count($_FILES['images']['name']) would raise a
			// TypeError. Normalize to the array form here; the regular
			// "images[]" case is left untouched.
			if (
				isset($_FILES['images']) &&
				is_array($_FILES['images']) &&
				isset($_FILES['images']['name']) &&
				!is_array($_FILES['images']['name'])
			) {
				$_FILES['images'] = array(
					'name'     => array($_FILES['images']['name']),
					'type'     => array(isset($_FILES['images']['type']) ? $_FILES['images']['type'] : ''),
					'tmp_name' => array($_FILES['images']['tmp_name']),
					'error'    => array($_FILES['images']['error']),
					'size'     => array(isset($_FILES['images']['size']) ? $_FILES['images']['size'] : 0),
				);
			}

			// ── Check if future scheduled and not publish_now ──
			$parser = new ArticleParser();
			$parsed = $parser->parseMarkdown($mdContent);
			$composer = new ArticleComposer();
			$scheduleTs = $composer->extractScheduleDate($parsed['properties'], time());
			$publishNow = isset($_POST['publish_now']) && $_POST['publish_now'] === 'on';
			$isDraft    = isset($_POST['publisharticle-draft']);

			$uploadErrorMessages = array(
				UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize.',
				UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE.',
				UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
				UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
				UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
				UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
				UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
			);

			$uiImageErrors = array();

			/*
			 * AdminPanelAction has no language property. The panel language is
			 * normally exposed to Smarty as $plang, but POST handling happens
			 * before that assignment; load it through FlatPress for the PHP-side
			 * messages below.
			 */
			$lang = lang_load($this->langres);

			if (!$isDraft && !$publishNow && $scheduleTs !== null) {
				$options = plugin_getoptions('publisharticle');
				if (!is_array($options)) {
					$options = array();
				}
				$importer = new ArticleImporter(null, !empty($options['import_folder']) ? $options['import_folder'] : null, $options);
				$importDir = $importer->getImportDir();

				if (!$importer->ensureImportDir()) {
					publisharticle_log('warn', __METHOD__ . ': import dir unavailable or not writable: ' . $importDir);
					$this->smarty->assign('success', -1);
					return;
				}

				$destMd = $importDir . $origName;
				if (!move_uploaded_file($tmpMd, $destMd)) {
					if (!copy($tmpMd, $destMd)) {
						publisharticle_log('warn', __METHOD__ . ': cannot save scheduled article to import dir: '
							. $destMd . ' — ' . (error_get_last() ? error_get_last()['message'] : ''));
						$this->smarty->assign('success', -1);
						return;
					}
					@unlink($tmpMd);
				}

				if (!is_file($destMd) || !is_readable($destMd)) {
					publisharticle_log('error', __METHOD__ . ': scheduled article missing after upload: ' . $destMd);
					$this->smarty->assign('success', -1);
					return;
				}

				@chmod($destMd, 0644);

				if (
					isset($_FILES['images']) &&
					!empty($_FILES['images']['name'][0])
				) {
					$count = count($_FILES['images']['name']);
					for ($i = 0; $i < $count; $i++) {
						if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
							$imgName = basename($_FILES['images']['name'][$i]);
							$safeImgName = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $imgName);
							$destImg = $importDir . $safeImgName;
							if (!move_uploaded_file($_FILES['images']['tmp_name'][$i], $destImg)) {
								if (!copy($_FILES['images']['tmp_name'][$i], $destImg)) {
									publisharticle_log('warn', __METHOD__ . ': cannot save image to import dir: '
										. $destImg . ' — ' . (error_get_last() ? error_get_last()['message'] : ''));
								} else {
									@unlink($_FILES['images']['tmp_name'][$i]);
								}
							}
							@chmod($destImg, 0644);
						} else {
							$uploadError = $_FILES['images']['error'][$i];
							publisharticle_log(
								'warn',
								__METHOD__ . ': image upload rejected by PHP',
								array(
									'index' => $i,
									'error' => $uploadError,
									'name' => $_FILES['images']['name'][$i],
									'size' => $_FILES['images']['size'][$i],
									'reason' => isset($uploadErrorMessages[$uploadError])
										? $uploadErrorMessages[$uploadError]
										: 'Unknown upload error.',
								)
							);

							$reason = isset($uploadErrorMessages[$uploadError])
								? $uploadErrorMessages[$uploadError]
								: 'Unknown upload error.';
							if (
								$uploadError === UPLOAD_ERR_INI_SIZE ||
								$uploadError === UPLOAD_ERR_FORM_SIZE
							) {
								$uiImageErrors[] = sprintf(
									$lang['admin']['plugin']['publisharticle']['image_rejected_limit'],
									$i+1,
									$_FILES['images']['name'][$i],
									$reason,
									ini_get('upload_max_filesize')
								);
							} else {
								$uiImageErrors[] = sprintf(
									$lang['admin']['plugin']['publisharticle']['image_rejected'],
									$i+1,
									$_FILES['images']['name'][$i],
									$reason
								);
							}
						}
					}
				}

				$this->smarty->assign('image_errors', $uiImageErrors);
				$this->smarty->assign('success', 1);
				return;
			}

			// ── Status & date ──

			$status = $isDraft ? 'draft' : 'publish';


			// ── Upload images (original name, sanitized) ──

			$imageMap = array();

			if (
				isset($_FILES['images']) &&
				!empty($_FILES['images']['name'][0])
			) {
				// REGRESSION FIX (R22): IMAGES_DIR is a blog-root-RELATIVE path
				// on FlatPress (defaults.php: FP_CONTENT . 'images/'). The core
				// always resolves it against the absolute ABS_PATH. Using the
				// bare relative path works only when the PHP process CWD is the
				// blog root; from the admin panel is_dir()/mkdir() and
				// move_uploaded_file() target the wrong location. ImageUploader
				// now prefixes ABS_PATH when available and still falls back to
				// the legacy relative path on FlatPress 1.4.x / exotic setups.
				$imgDir = (new ImageUploader())->getImagesDir();

				if (!is_dir($imgDir)) {
					mkdir($imgDir, 0755, true);
				}

				$count = count($_FILES['images']['name']);

				for ($i = 0; $i < $count; $i++) {

					if (
						$_FILES['images']['error'][$i]
						!== UPLOAD_ERR_OK
					) {
						$uploadError = $_FILES['images']['error'][$i];
						publisharticle_log(
							'warn',
							__METHOD__ . ': image upload rejected by PHP',
							array(
								'index' => $i,
								'error' => $uploadError,
								'name' => $_FILES['images']['name'][$i],
								'size' => $_FILES['images']['size'][$i],
								'reason' => isset($uploadErrorMessages[$uploadError])
									? $uploadErrorMessages[$uploadError]
									: 'Unknown upload error.',
							)
						);

						$reason = isset($uploadErrorMessages[$uploadError])
							? $uploadErrorMessages[$uploadError]
							: 'Unknown upload error.';
						if (
							$uploadError === UPLOAD_ERR_INI_SIZE ||
							$uploadError === UPLOAD_ERR_FORM_SIZE
						) {
							$uiImageErrors[] = sprintf(
								$lang['admin']['plugin']['publisharticle']['image_rejected_limit'],
								$i+1,
								$_FILES['images']['name'][$i],
								$reason,
								ini_get('upload_max_filesize')
							);
						} else {
							$uiImageErrors[] = sprintf(
								$lang['admin']['plugin']['publisharticle']['image_rejected'],
								$i+1,
								$_FILES['images']['name'][$i],
								$reason
							);
						}
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
					} else {
						// REGRESSION FIX (R22): move_uploaded_file() can fail
						// without any visible error when the destination does
						// not live in the expected filesystem location (wrong
						// CWD + relative IMAGES_DIR, read-only dir, ...). Fall
						// back to a plain copy() and surface total failures in
						// the log instead of losing the image silently.
						if (copy($_FILES['images']['tmp_name'][$i], $dest)) {
							@unlink($_FILES['images']['tmp_name'][$i]);
							$imageMap[$rawName] = 'images/' . $safeName;
						} else {
							publisharticle_log('warn', __METHOD__ . ': cannot save image to images dir: '
								. $dest . ' — ' . (error_get_last() ? error_get_last()['message'] : ''));
						}
					}
				}
			} else {
				$images = isset($_FILES['images']) ? $_FILES['images'] : null;
				$imageNames = is_array($images) && isset($images['name']) ? $images['name'] : null;
				$imageErrors = is_array($images) && isset($images['error']) ? $images['error'] : null;
				$imageSizes = is_array($images) && isset($images['size']) ? $images['size'] : null;
				publisharticle_log(
					'debug',
					__METHOD__ . ': images field empty/absent',
					array(
						'field_present' => isset($_FILES['images']),
						'field_type' => gettype($images),
						'field_keys' => is_array($images) ? array_keys($images) : array(),
						'name_count' => is_array($imageNames) ? count($imageNames) : 0,
						'first_name' => is_array($imageNames) && isset($imageNames[0]) ? $imageNames[0] : null,
						'first_error' => is_array($imageErrors) && isset($imageErrors[0]) ? $imageErrors[0] : null,
						'first_size' => is_array($imageSizes) && isset($imageSizes[0]) ? $imageSizes[0] : null,
					)
				);
			}

			$this->smarty->assign('image_errors', $uiImageErrors);

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

			$importer = new ArticleImporter(
				null, !empty($options['import_folder']) ? $options['import_folder'] : null, $options
			);
			$importDir = $importer->getImportDir();

			if (!is_dir($importDir)) {
				$this->smarty->assign('success', -1);
				return;
			}

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

			$importer = new ArticleImporter(
				null, !empty($options['import_folder']) ? $options['import_folder'] : null, $options
			);
			$importDir = $importer->getImportDir();

			if (is_dir($importDir)) {

				$this->smarty->assign(
					'import_folder_path',
					$importDir
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

			$importer = new ArticleImporter(
				null, !empty($options['import_folder']) ? $options['import_folder'] : null, $options
			);
			$importDir = $importer->getImportDir();

			if (!is_dir($importDir)) {
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
