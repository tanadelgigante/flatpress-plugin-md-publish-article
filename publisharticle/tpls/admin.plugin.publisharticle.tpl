<h2>{$plang.head}</h2>
<p>{$plang.description}</p>

{include file="shared:errorlist.tpl"}

{if isset($success)}
	<div class="notice{if $success < 0} error{/if}">
		{if $success > 0}{$plang.import_done}{else}{$plang.import_error}{/if}
	</div>
{/if}

{if isset($image_errors) && count($image_errors) > 0}
	<div class="notice error">
		<p><strong>{$plang.images_rejected_title}</strong></p>
		<ul>
			{foreach $image_errors as $imgErr}
				<li>{$imgErr|escape}</li>
			{/foreach}
		</ul>
	</div>
{/if}

{html_form enctype="multipart/form-data" class="option-set"}
<dl class="option-list">

	<!-- Markdown file upload -->
	<dt><label for="md_file">{$plang.file_label}</label></dt>
	<dd>
		<input type="file" name="md_file" id="md_file" accept=".md,.markdown,.mdown,.txt"
			onchange="previewMdFile(this)">
		<p class="form-help">{$plang.file_help}</p>
	</dd>

	<!-- Image upload (multiple) -->
	<dt><label for="images">{$plang.images_label}</label></dt>
	<dd>
		<input type="file" name="images[]" id="images" multiple
			accept="image/jpeg,image/png,image/gif,image/webp"
			onchange="previewImages(this)">
		<p class="form-help">{$plang.images_help}</p>
	</dd>

	<!-- Publish now -->
	<dt><label for="publish_now">{$plang.publish_now}</label></dt>
	<dd>
		<input type="checkbox" name="publish_now" id="publish_now">
	</dd>

</dl>

<!-- Live preview -->
<div id="preview-section" style="display:none; margin-top:16px; padding:12px; border:1px solid #ccc; background:#fafafa;">
	<h3>{$plang.preview_title}</h3>
	<div id="preview-images" style="margin:8px 0;"></div>
	<div id="preview-content" style="padding:8px; background:#fff; border:1px solid #eee; min-height:60px; white-space:pre-wrap; font-family:monospace;"></div>
</div>

<p class="buttonbar">
	<input type="submit" name="publisharticle-publish" value="{$plang.submit}" class="button">
	<input type="submit" name="publisharticle-draft" value="{$plang.submit_draft}" class="button">
</p>

{/html_form}

<script>
function previewMdFile(input) {
	var file = input.files[0];
	if (!file) return;
	var reader = new FileReader();
	reader.onload = function(e) {
		var section = document.getElementById('preview-section');
		var content = document.getElementById('preview-content');
		section.style.display = 'block';
		content.textContent = e.target.result;
	};
	reader.readAsText(file);
}

function previewImages(input) {
	var container = document.getElementById('preview-images');
	var section = document.getElementById('preview-section');
	container.innerHTML = '';

	var files = input.files;
	if (!files || files.length === 0) return;

	section.style.display = 'block';
	for (var i = 0; i < files.length; i++) {
		(function(file) {
			if (!file.type.match(/^image\//)) return;
			var reader = new FileReader();
			reader.onload = function(e) {
				var img = document.createElement('img');
				img.src = e.target.result;
				img.style.maxWidth = '200px';
				img.style.maxHeight = '150px';
				img.style.margin = '4px';
				img.style.border = '1px solid #ccc';
				img.style.borderRadius = '4px';
				container.appendChild(img);
			};
			reader.readAsDataURL(file);
		})(files[i]);
	}
}
</script>

<!-- Pending files / import all -->
{if isset($pending_count) && $pending_count > 0}
	<hr>
	<form method="post">
		<p>
			<input type="submit" name="publisharticle-import-now" value="{$plang.import_now}" class="button">
		</p>
	</form>
{else}
	<p>{$plang.no_pending_files}</p>
{/if}

<!-- Recent Imports -->
{if isset($recent_imports) && count($recent_imports) > 0}
	<h3>{$plang.recent_imports}</h3>
	<table class="import-log">
		<thead>
			<tr>
				<th>{$plang.log_file}</th>
				<th>{$plang.log_status}</th>
				<th>{$plang.log_time}</th>
			</tr>
		</thead>
		<tbody>
			{foreach $recent_imports as $entry}
				<tr class="{if $entry.success}import-ok{else}import-fail{/if}">
					<td>{$entry.file|escape}</td>
					<td>{if $entry.success}✓ OK{else}✗ {$entry.error|escape}{/if}</td>
					<td>{$entry.time}</td>
				</tr>
			{/foreach}
		</tbody>
	</table>
{/if}