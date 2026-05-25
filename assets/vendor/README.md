# Vendor assets

These files are intentionally vendored so PDF stamping works in local Studio and on production sites without a CDN dependency.

- `pdf-lib.min.js` - pdf-lib 1.17.1.
- `pdf.min.mjs` - PDF.js 5.4.394 display module for first-page text extraction.
- `pdf.worker.min.mjs` - PDF.js 5.4.394 worker module.
- `fontkit.umd.min.js` - @pdf-lib/fontkit 1.1.1 for embedding custom fonts.
- `qrcode.min.js` - qrcode-generator 1.4.4.
- `GolosText-Regular.ttf` - Golos Text regular font.
- `GolosText-Medium.ttf` - Golos Text medium font.

The server-side SHA-256 remains the source of truth. These browser libraries only create the public stamped PDF artifact.
