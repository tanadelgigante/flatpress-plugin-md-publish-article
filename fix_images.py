import sys

path = r'V:\devel\flatpress-publish-article-plugin\publisharticle\panels\admin.plugin.panel.publisharticle.php'

with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# The old image-handling block (with tabs)
old_block = """			// \xe2\x94\x80\xe2\x94\x80 Handle image uploads \xe2\x94\x80\xe2\x94\x80
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
			}""".encode('utf-8').decode('utf-8')

# Build the new block with original-name saving + markdown reference fixing
new_block = """			// \xe2\x94\x80\xe2\x94\x80 Handle image uploads \xe2\x94\x80\xe2\x94\x80
			// Save images with their ORIGINAL name (sanitized) and
			// build a mapping old-name → images/<new-name> so we can
			// fix the Markdown references.
			$imageMap = array();

			if (
				isset($_FILES['images']) &&
				!empty($_FILES['images']['name'][0])
			) {

				$contentDir = CONTENT_DIR . 'content';
				$imgDir = $contentDir . '/images';

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

					$rawName = basename(
						$_FILES['images']['name'][$i]
					);

					// Sanitize: keep only safe characters
					$safeName = preg_replace(
						'/[^a-zA-Z0-9_.-]/',
						'_',
						$rawName
					);

					$dest = $imgDir . '/' . $safeName;

					// Avoid overwriting: prefix if already exists
					if (file_exists($dest)) {
						$safeName = 'img_'
							. date('Ymd_His')
							. '_'
							. $safeName;
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

			// \xe2\x94\x80\xe2\x94\x80 Fix Markdown image references \xe2\x94\x80\xe2\x94\x80
			// Replace (plugin.png) with (images/plugin.png),
			// keeping attributes like (plugin.png width=500).
			foreach ($imageMap as $orig => $rel) {
				$escaped = preg_quote($orig, '/');
				$mdContent = preg_replace(
					'/\\('
						. $escaped
						. '(\\s+[^)]*)?\\)/i',
					'(' . $rel . '$1)',
					$mdContent
				);
			}""".encode('utf-8').decode('utf-8')

if old_block in content:
    content = content.replace(old_block, new_block)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print('REPLACED OK')
else:
    print('OLD BLOCK NOT FOUND')
    # Print a diagnostic: find the marker
    idx = content.find('Handle image uploads')
    if idx >= 0:
        print('Found marker at offset', idx)
        print(repr(content[idx:idx+200]))