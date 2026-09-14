<h2>{$plang.head}</h2>
<p>{$plang.description}</p>

{include file="shared:errorlist.tpl"}

{if isset($success)}
	<div class="notice{if $success < 0} error{/if}">
		{if $success > 0}{$plang.import_done}{else}{$plang.import_error}{/if}
	</div>
{/if}

<!-- Folder Status -->
<div class="import-info">
	<dl>
		<dt>{$plang.import_folder}</dt>
		{if isset($import_folder_path)}
			<dd><code>{$import_folder_path|escape}</code></dd>
		{else}
			<dd>{$plang.import_folder_not_set}</dd>
		{/if}

		<dt>{$plang.pending_files}</dt>
		<dd>{$pending_count}</dd>
	</dl>

	{if isset($import_folder_status)}
		<div class="import-status {if $import_folder_status.protected}protected{else}unprotected{/if}">
			<strong>{$import_folder_status.server|upper}:</strong> {$import_folder_status.message}
		</div>
	{/if}
</div>

<!-- Import Button -->
{if $pending_count > 0}
	<form method="post">
		<p>
			<input type="submit" name="publisharticle-import-now" value="{$plang.import_now}" class="button">
			<span class="form-help">{$plang.import_now_help}</span>
		</p>
	</form>
{else}
	<p class="import-no-pending">{$plang.no_pending_files}</p>
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
