# Publish Article Plugin for FlatPress

The **Publish Article** plugin allows you to publish articles to FlatPress by simply uploading Markdown files to a designated folder.

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
    * **Tables**: Use standard HTML `<table>` tags.
    * **Task Lists**: Convert `- [ ]` and `- [x]` into interactive checkbox patterns.
    * **Custom HTML**: Direct HTML injection is supported for maximum flexibility.
* **Enhanced Image Support**:
    * Full support for `[img]` attributes: `width`, `height`, and `alt` text.
    * Example: `![Alt Text](image.jpg width=500 height=300)` → `[img alt="Alt Text" width="500" height="300"]image.jpg[/img]`.
* **Smart URL Handling**: Automatically detects and fixes protocol-relative URLs (e.g., `//example.com`) by prepending `https:`, preventing broken links or mangled content.

### ⚙️ Workflow & Automation
* **Frontmatter Support**: Use YAML-like headers to control article metadata:
    * `subject` (or `title`): The article title.
    * `author`: Author name (defaults to `admin`).
    * `date`: Article date (also used for Entry ID).
    * `categories`: Comma-separated names (`News, Tech`) or numeric IDs.
    * `publish_date`: For scheduled publishing.
    * `status`: `publish` or `draft`.
* **Collision Protection**: To prevent accidental overwriting of existing articles, the plugin automatically increments timestamps in the Entry ID if a filename collision is detected.
* **Scheduled Publishing**: Articles with a future `publish_date` are stored in a pending state and automatically published upon the first page load after the scheduled time.
* **Automatic Import**: Simply drop `.md`, `.markdown`, `.mdown`, or `.txt` files into the import folder. The plugin scans and processes them automatically.
* **Media Management**: Images found in the Markdown file are automatically detected, moved to `fp-content/images/`, and linked correctly.

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

### 🔒 Security

The import folder contains unpublished content. To prevent unauthorized access:
* **Apache/LiteSpeed**: Automatically deploys a `.htaccess` with `Options -Indexes` and `Require all denied`.
* **Caddy 2**: Provides a `caddy_import_protect.conf` snippet. You must include this in your `Caddyfile`:
  ```caddy
  @import_deny path /fp-content/content/import-in/*
  respond @import_deny "Forbidden" 403
  ```

## Examples

### Frontmatter & Markdown
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
<table>
  <tr><th>Feature</th><th>Status</th></tr>
  <tr><td>Tables</td><td>✅</td></tr>
  <tr><td>Task Lists</td><td>✅</td></tr>
</table>

Check this image with attributes:
![A beautiful landscape](photo.jpg width=600 height=400)

And a protocol-relative URL:
//example.com/style.css
```
