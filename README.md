# Publish Article Plugin for FlatPress

The **Publish Article** plugin allows you to publish articles to FlatPress by simply uploading Markdown files to a designated folder. It is compatible with FlatPress **1.4.x** and **1.5.x** (PHP 8.x; verified on 1.5.1).

## Installation

Copy the `publisharticle/` folder into your FlatPress `fp-plugins/` directory:

```text
fp-plugins/
  publisharticle/
    plugin.publisharticle.php     ← Plugin entry point
    lang/lang.en-us.php
    panels/admin.plugin.panel.publisharticle.php
    tpls/admin.plugin.publisharticle.tpl
    ArticleProcessor.php
    ArticleImporter.php
    ...
```

**Note:** The main file must be named `plugin.publisharticle.php` for FlatPress to recognize it. After copying, enable **Publish Article** in the admin area (**Manage → Plugins**).

## Features

### 📝 Content Conversion
* **Markdown to BBCode**: Seamlessly converts Markdown syntax into FlatPress-compatible BBCode.
* **Advanced Markdown Support**: Supports complex elements via HTML injection:
    * **Tables**: Standard Markdown tables with per-column alignment (`:---`, `:---:`, `---:`) are converted to HTML `<table>` elements.
    * **Task Lists**: Convert `- [ ]` and `- [x]` into interactive checkbox list items.
    * **Custom HTML**: Direct HTML tags are preserved for maximum flexibility.
* **Enhanced Image Support**:
    * Full support for `[img]` attributes: `width`, `height`, and `alt` text.
    * Markdown: `![Alt Text](image.jpg width=500 height=300)` → `[img="images/image.jpg" alt="Alt Text" width="500" height="300"]`.
* **Smart URL Handling**: Automatically detects and fixes protocol-relative URLs (e.g., `//example.com`) by prepending `https:`, preventing broken links or mangled content.

### ⚙️ Workflow & Automation
* **Frontmatter Support**: Use YAML-like headers to control article metadata:
    * `version` — version of the entry format. The default is **dynamic**: `system_ver()` when the FlatPress runtime is loaded (e.g. `fp-1.5.1` on 1.5.x, `fp-1.4.1` on 1.4.x), with fallback `fp-1.5.1` (`FALLBACK_VERSION`, in `ArticleComposer`) outside FlatPress; override it per entry with this field.
    * `subject` (alias `title`) — entry title.
    * `author` — entry author (default `admin`).
    * `date` — entry date (also used for the Entry ID).
    * `categories` — comma-separated names (`News, Tech`) or numeric IDs (`5,15`); names are translated to IDs using FlatPress `categories_encoded.dat` (or `categories.txt`).
    * `publish_date` / `scheduled` / `scheduled_date` — future publish date for **scheduled/delayed publishing**.
    * `status` — `publish` or `draft`.
* **Collision Protection**: To prevent accidental overwriting of existing articles, the plugin automatically increments the timestamp in the Entry ID if a collision is detected.
* **Scheduled Publishing**: Articles with a future `publish_date` are stored in a pending state and automatically published upon the first page load after the scheduled time.
* **Automatic Import**: Drop `.md`, `.markdown`, `.mdown`, or `.txt` files into the import folder — the plugin scans and processes them automatically on page load. Processed files are archived into the `done/` (success) or `failed/` (error) subfolder. If the import folder is not writable, files are instead marked in place as `*.md.done` / `*.md.failed` so they are never published twice.
* **Scheduled Imports Stay in the Import Folder**: A file with a future `publish_date` is **not** moved to `done/`; it is simply left untouched in the import folder. It is re-scanned on every run and published (then archived to `done/`) as soon as its publish time has come.
* **Media Management**: **All** image files found in the import folder are imported into `fp-content/images/` **keeping their original file name**, and their sources are moved to `done/`. If an image name is already in use in the images folder (or duplicated in the import folder), the whole article import fails and the file is archived into `failed/` — existing images are never overwritten.

### 🛠 Configuration

Settings are available in **Manage → Plugins → Publish Article**:

| Setting | Default | Description |
| --- | --- | --- |
| `import_folder` | `fp-content/content/import-in/` | Path to the Markdown source folder. |
| `import_frequency` | `every_page_load` | Execution interval: `manual`, `every_page_load`, or a **cron expression** (e.g., `*/15 * * * *`). |
| `default_category` | *(none)* | Category ID for articles without specified categories. |
| `default_status` | `publish` | Default status (`publish` or `draft`). |
| `done_subdir` | `done` | Subfolder for successfully processed files. |
| `failed_subdir` | `failed` | Subfolder for files that failed to import. |
| `log_level` | `info` | Log verbosity (`debug`, `info`, `warn`); WARN/ERROR/FATAL always logged. See [Logging](#logging). |

### 🔒 Security

The import folder contains unpublished content. To prevent unauthorized access:
* **Apache/LiteSpeed**: Automatically deploys a `.htaccess` with `Options -Indexes` and `Require all denied`.
* **Caddy 2**: Provides a `caddy_import_protect.conf` snippet. You must include this in your `Caddyfile`:
  ```caddy
  @import_deny path /fp-content/content/import-in/*
  respond @import_deny "Forbidden" 403
  ```

## Frontmatter & Markdown example

```markdown
---
subject: My Advanced Article
author: ilgigante77
date: 2026-09-13
categories: News, Tech
publish_date: 2026-09-20 10:00:00
---

# Welcome to my article!

Here is a task list:
- [x] Implement Markdown support
- [ ] Write documentation

Here is a table:

| Feature    | Status |
|:-----------|:------:|
| Tables     |   ✅   |
| Task Lists |   ✅   |

Check this image with attributes:

![Alt Text](image.jpg width=500 height=300)

And a protocol-relative URL:
//example.com/style.css
```

## Compatibility

The plugin runs on FlatPress **1.4.x** and **1.5.x** (verified on 1.5.1, PHP 8.x).

| FlatPress | Entry `VERSION` | Notes |
| --- | --- | --- |
| 1.5.x | `fp-1.5.1` — dynamic via `system_ver()` (fallback `fp-1.5.1`) | Verified checklist 10/10; Smarty 5 |
| 1.4.x | `fp-1.4.1` — dynamic via `system_ver()` | Retro-compatible; same entry format |

Legacy entries marked `fp-1.4.1` remain readable on both major versions. The `docs/fp151-compat-report.md` report documents the 1.5.1 verification.

## CI/CD and the `[skip-ci]` Tag

The Gitea Actions workflow (`.gitea/workflows/ci.yml`) skips unnecessary jobs when the head commit message contains the token **`[skip-ci]`**.

- Push with `[skip-ci]` in the (head) commit message → `lint` and `test` jobs are skipped, together with their downstream jobs.
- **Option B**: with `[skip-ci]` every job is skipped, **including the `release` job on a `v*` tag**. A release can still happen on a tag whose commit has no token, or manually via `workflow_dispatch job=release`.
- `workflow_dispatch` runs are **never** skipped (explicit manual trigger).
- The post-release bump commit automatically carries the token, e.g.:

    `Bump version to 1.0.1-SNAPSHOT after release v1.0.0 [skip-ci]`

## Logging

The plugin logs to PHP's `error_log()` with the `[publisharticle]` prefix, in the format:

    [publisharticle] <LEVEL> <message> [key=value ...]

The `log_level` plugin option (admin panel) controls verbosity:

| `log_level` | Emits |
| --- | --- |
| `debug` | DEBUG + INFO + WARN + ERROR + FATAL |
| `info` (default) | INFO + WARN + ERROR + FATAL |
| `warn` | WARN + ERROR + FATAL |

**WARN, ERROR and FATAL are always active** in any configuration. Implementation: `PublishArticleLogger.php` plus the `publisharticle_log($level, $message, $context)` helper.

## Documentation

- Wiki: <https://github.com/tanadelgigante/flatpress-plugin-md-publish-article/wiki>
- `docs/flatpress_plugin_docs.md` — “Creating a FlatPress Plugin” guide
- `docs/WORKPLAN.md` — work plan and decisions D1-D5
- `docs/fp151-compat-report.md` — FlatPress 1.5.1 compatibility report

## Contact

- **Email:** <info@tanadelgigante.com>
- **GitHub:** [@ilgigante77](https://github.com/tanadelgigante)
- **Website / Blog:** <https://www.tanadelgigante.com>


