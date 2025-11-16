# ChatGPT Conversation Exporter

![PHP Version](https://img.shields.io/badge/php-%5E8.2-777bb4?logo=php)
![Laravel 12](https://img.shields.io/badge/laravel-12.x-ff2d20?logo=laravel)
![License](https://img.shields.io/badge/license-MIT-blue)

A CLI-only Laravel 12 application that turns the official ChatGPT data export (specifically `conversations.json` and the accompanying `file_*.jpeg` assets) into reader-friendly artifacts. It streams huge JSON dumps (>200 MB) without loading them fully into memory, asks whether to export conversations automatically or one-by-one, copies image assets (plus thumbnails), and can write each conversation as Markdown, PDF, or both.

## Features
- **Streaming parser** powered by `halaxa/json-machine` keeps memory usage constant, even with massive exports.
- **Approvals**: choose automatic export or confirm each conversation interactively.
- **Safe filenames** with collision handling and optional attachment copying/reference modes.
- **Image handling**: copies relevant `file_*.jpeg` assets beside each transcript, generates thumbnails, and rewrites Markdown/PDF references.
- **Dual outputs**: Markdown, PDF (via Dompdf), or both; Markdown is converted through CommonMark so PDFs match the same transcript content.

## Requirements
- PHP 8.2+
- Composer
- GD or Imagick extension (Intervention Image uses GD by default)
- NodeJS is *not* required because this is a console-only workflow.

## Installation
```bash
git clone https://github.com/your-github-username/chatgpt-md-exporter.git
cd chatgpt-md-exporter
composer install
```

The first `composer install` will also publish the Dompdf and Intervention assets that the exporter uses.

## Command Overview
All exporting is handled by a single Artisan command:
```
php artisan conversations:export
```

### Required Argument
| Argument | Description |
|----------|-------------|
| `file`   | Absolute or relative path to `conversations.json` inside the ChatGPT export bundle. |

### Common Options
| Option | Description |
|--------|-------------|
| `--output=` | Target directory for Markdown/PDF files. Defaults to `storage/app/conversations`. The exporter will create the folder if it does not exist. |
| `--assets=` | Directory containing the exported attachment files (`file_*.jpeg`, etc.). Defaults to the folder that contains `conversations.json`. |
| `--mode=` | `auto` exports everything with no prompts; `manual` asks before each conversation. If omitted, you’ll be prompted interactively. |
| `--asset-mode=` | `copy` (default) copies images into the transcript folder; `reference` keeps links pointing to the original export directory. |
| `--thumbnail-width=` | Width (in px) of generated thumbnails used in Markdown/PDF (default `512`). Use `0` to skip thumbnails entirely. |
| `--format=` | `markdown`, `pdf`, or `both` (comma-separated also supported, e.g. `markdown,pdf`). Default is `markdown`. |

### Usage Examples
Export everything into Markdown plus thumbnails, approving each conversation manually:
```bash
php artisan conversations:export \
  ../chatgpt-export/conversations.json \
  --assets=../chatgpt-export \
  --output=storage/app/markdown \
  --mode=manual \
  --thumbnail-width=320
```

Export both Markdown and PDF while referencing the original assets (no copies):
```bash
php artisan conversations:export \
  /path/to/conversations.json \
  --asset-mode=reference \
  --format=both
```

Generate PDF-only output with thumbnails disabled (useful when you already have attachments somewhere else):
```bash
php artisan conversations:export \
  /path/to/conversations.json \
  --format=pdf \
  --thumbnail-width=0
```

## Output Layout
For each conversation:
- Markdown file: `<slug>.md`
- Optional PDF: `<slug>.pdf`
- Attachments: `<slug>_assets/` containing copied images.
- Thumbnails (if enabled): `<slug>_assets/thumbnails/`.

Filenames are deduplicated so re-running the command won’t overwrite existing exports unless they share the exact slug and extension.

## Tips
- Keep the original `conversations.json` and attachment directory in the same relative layout; the exporter guesses image paths from the JSON pointer IDs.
- When processing very large exports in manual mode, consider `--mode=auto` first to inspect the summary, then re-run in manual mode if needed.
- PDFs use a simple built-in stylesheet. If you want fully branded output later, you can extend `convertMarkdownToHtml()` with your own HTML/CSS template.

## Troubleshooting
- **Missing images**: ensure `--assets` points to the folder that contains the `file_*.jpeg` files. If they’re missing, Markdown will display the pointer ID instead.
- **Thumbnail errors**: the command logs warnings if GD/Imagick cannot read an image. Thumbnails are skipped for those files, but the full image link remains.
- **PDF rendering issues**: Dompdf relies on absolute file paths, which the exporter injects automatically. If you move the exports after creation, regenerate the PDFs so the paths stay valid.

Happy exporting! :rocket:
