# Sign Docs

WordPress plugin for signing and verifying PDF documents with simple electronic signature.

## Description

Sign Docs fixes signed PDF documents with a server-side SHA-256 hash and a public verification page. It stores the original PDF as a control copy, creates a public stamped copy with a visual mark, and generates a QR code linking to the verification page.

Built for Russian educational organizations under Government Resolution №1802 (20.10.2021), which requires local acts to be signed electronically and published on the official website.

## Features

- Upload PDF documents and sign them with browser-based PDF stamping
- Server-side SHA-256 hash of the original file (source of truth)
- Two-phase signing: prepare (save original) → browser stamp → complete (save stamped copy)
- Public verification page at `/signed/{id}/` with document details and hash
- Client-side PDF integrity checker (SHA-256 comparison, no server upload)
- QR code linking to the verification page
- Gutenberg block `sign-docs/document` for embedding documents
- AI-assisted metadata autofill (optional, requires WP AI Client + OpenRouter)
- Configurable document title templates
- Document replacement workflow (new version replaces old)
- Custom taxonomy: category, type, department, institution
- Archive instead of delete policy
- Admin capability management for document upload

## Requirements

- WordPress 6.5+
- PHP 8.1+
- Modern browser with JavaScript enabled (for PDF stamping)

## Installation

1. Download the latest release ZIP from GitHub Releases.
2. In WordPress admin, go to Plugins → Add New → Upload Plugin.
3. Upload the ZIP and activate.
4. Go to Sign Docs → Settings and configure the signer details and stamp defaults.

### Manual Installation

```bash
# Build the distribution
bash tools/build-dist.sh

# The built plugin is in sign-docs/ — copy it to wp-content/plugins/
cp -r sign-docs /path/to/wp-content/plugins/
```

## Usage

1. Go to Sign Docs → Add Document.
2. Select a PDF file.
3. Fill in document details (category, type, date, number, title).
4. Click "Save and Sign" (browser stamps the PDF and sends it via REST API) or "Save without Signature".
5. The verification page is available at `/signed/{id}/`.
6. Use the Gutenberg block `Document` to embed documents in posts and pages.
7. Visitors can verify PDF integrity on the verification page using their local file.

## Updates

The plugin supports self-updates from GitHub Releases. When a new version is published, the WordPress admin plugins page will show an update notification.

## Development

### Project structure

```
sign-docs.php          # Plugin entry point
includes/
  class-plugin.php     # Coordinator, hooks registration
  class-post-type.php  # CPT registration
  class-meta.php       # Meta fields contract
  class-taxonomies.php # Taxonomies (category, type, department, institution)
  class-storage.php    # File storage and SHA-256
  class-translit.php   # Cyrillic-to-Latin filename conversion
  class-site-icon.php  # Site icon cache for QR codes
  class-title-template.php  # Configurable title templates
  class-document-service.php # Document creation, prepare/complete workflow
  class-rest-controller.php  # REST API endpoints
  class-ai-metadata.php      # AI-assisted metadata suggestions
  class-verification-page.php # Public verification page
  class-settings.php   # Plugin settings
  class-admin.php      # Admin UI
  class-blocks.php     # Gutenberg block
  class-usage-index.php # Block usage index
  class-updater.php    # GitHub releases updater
assets/
  js/                  # JavaScript (admin, block, public)
  css/                 # Stylesheets
  vendor/              # Third-party libraries (pdf-lib, PDF.js, qrcode, fontkit)
docs/                  # Module documentation
tools/
  build-dist.sh        # Build clean distribution
  create-release.sh    # Build and create GitHub release
```

### Build distribution

```bash
bash tools/build-dist.sh
```

Creates `sign-docs/` with only the files needed for WordPress.

### Create a release

```bash
# 1. Build and create release ZIP
bash tools/create-release.sh v0.2.0

# 2. Upload the ZIP to GitHub Releases:
#    - Go to https://github.com/igor-blag/sign-docs/releases
#    - Create a new release with tag v0.2.0
#    - Upload sign-docs-v0.2.0.zip as an asset
```

### Run tests

```bash
php tests/run.php
```

## License

GPL v2 or later.
