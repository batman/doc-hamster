# Dependencies and licenses

DocHamster is MIT-licensed. Rules for third-party software: **D-022** in [`collaboration/DECISIONS.md`](collaboration/DECISIONS.md).

- Everything we ship (Composer/npm packages, vendored JS): permissive licenses only – MIT, BSD-2/3-Clause, Apache-2.0, ISC, 0BSD, public domain (CC0, Unlicense). LGPL/MPL only as an unmodified library, listed below with the reason. **No GPL/AGPL.** Packages with several licenses ("OR") count with the permissive one.
- `composer check-licenses` (= `scripts/check-licenses.php`) checks all runtime packages; CI runs it on every push. Dev-only tools are not shipped and not checked.
- Every new **direct** dependency gets a row here in the same PR.

## Runtime (shipped)

| Package | Version | License | Why |
|---------|---------|---------|-----|
| `laravel/framework` | 13.x | MIT | Framework (D-001) |
| `laravel/tinker` | 3.x | MIT | REPL for operators (`php artisan tinker`), part of the Laravel skeleton |
| `bacon/bacon-qr-code` | 3.x | BSD-2-Clause | QR codes for connecting a phone (#37) |
| jsQR (vendored in `public/js/vendor/`) | 1.4.0 | Apache-2.0 | Reads QR codes with the phone camera, not every browser can do it natively (#37) |
| pdf.js (`pdfjs-dist` legacy build + JBIG2/JPX/ICC decoders, vendored in `public/vendor/pdfjs/`; `.mjs` renamed to `.js`; npm tarball checked against its sha512 integrity) | 6.4.299 | Apache-2.0 (decoders: BSD-3-Clause, BSD-2-Clause, MIT – licence files alongside) | Sharp PDF pages in the viewer, PDFs in the browser text recognition (points 33, 34) |
| tesseract.js + tesseract.js-core (vendored in `public/vendor/tesseract/`: `tesseract.min.js`, `worker.min.js`, `core/tesseract-core-lstm.wasm.js`, `core/tesseract-core-simd-lstm.wasm.js`; SHA-256 pinned in `LocalOcr`) | 5.1.1 | Apache-2.0 (licence files alongside) | Text recognition in the browser when the server has no Tesseract (point 18, A64) |
| tessdata_fast `deu`/`eng` traineddata (vendored in `public/vendor/tesseract/lang/`, also copied for the app-owned Tesseract) | 4.1.0 | Apache-2.0 (licence file alongside) | Language data for the browser and the app-owned text recognition (A64) |
| Tabler Icons (only the used SVGs, vendored in `resources/icons/tabler/`) | 3.49.0 | MIT | Icons, inlined via `<x-icon>` so they take the text colour |

Transitive runtime packages (77 in total at P1-1, mostly Symfony and Laravel components): all MIT, BSD-3-Clause or Apache-2.0; two are dual-licensed `BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only` and used under BSD-3-Clause.

### LGPL/MPL exceptions

None yet.

## Development only (not shipped)

| Package | License | Why |
|---------|---------|-----|
| `pestphp/pest`, `pestphp/pest-plugin-laravel`, `phpunit/phpunit` | MIT, BSD-3-Clause | Tests, arch tests (PLAN §13) |
| `larastan/larastan` | MIT | PHPStan level 8 for Laravel (PLAN §13) |
| `laravel/pint` | MIT | Code style |
| `mockery/mockery`, `fakerphp/faker` | BSD-3-Clause, MIT | Test doubles, synthetic test data |
| `nunomaduro/collision`, `laravel/pail`, `laravel/pao` | MIT | Error output, log viewer, test output for agents (Laravel skeleton) |

## Tools the administrator installs (not shipped, D-010/D-022)

Called as separate programs only; listed so admins know what they install.

| Tool | Needed for | License |
|------|------------|---------|
| Tesseract 5 | Add-on text recognition | Apache-2.0 |
| ocrmypdf | Add-on text recognition (PDF) | MPL-2.0 |
| Ghostscript | used by ocrmypdf | AGPL-3.0 |
| whisper.cpp (`whisper-cli`) | Add-on speech-to-text (D-019) | MIT |
| ffmpeg | Add-on speech-to-text (audio conversion) | LGPL-2.1+ / GPL (depends on build) |
| libheif (`heif-convert`) | Add-on HEIC conversion | LGPL-3.0 |
| libvips + `vips` PHP extension | Add-on fast image processing | LGPL-2.1+ / MIT |

## Data sets

| Data | Used for | License |
|------|----------|---------|
| GeoNames `cities1000` (from P2-5) | Offline place names (D-017) | CC BY 4.0 – attribution in the app's About page |
