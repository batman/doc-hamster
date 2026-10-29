# DocHamster 🐹

Snap a receipt, send it, forget it. DocHamster collects receipts and documents, reads them (OCR), tags and files them, matches them against your bank statements and hands your tax advisor a clean, read-only package.

> **Status: planning (Phase 0).** There is no runnable code yet. The plan is in [`docs/PLAN.md`](docs/PLAN.md) (German).

## Planned features

- Mobile-first PWA: camera → send, works offline
- Server-side OCR (Tesseract), automatic date/amount/merchant extraction, tags and categories
- Full-text search and filters
- CSV/XLSX bank statement import with automatic receipt matching
- Reports and exports (PDF, CSV, XLSX, ZIP)
- Read-only access for your tax advisor
- Passkey device pairing – no password every time
- Optional, pluggable AI (off by default)

DocHamster is **not** a tax or bookkeeping app.

## Tech (planned)

PHP 8.3+, SQLite (MariaDB optional), server-rendered HTML + small vanilla JS PWA, Tesseract/ocrmypdf, Docker Compose. Final choices: [`docs/collaboration/DECISIONS.md`](docs/collaboration/DECISIONS.md).

## Contributing

This project is built by a human product owner together with two AI agents. Rules for everyone (humans and AIs): [`AGENTS.md`](AGENTS.md) and [`docs/collaboration/WORKFLOW.md`](docs/collaboration/WORKFLOW.md).

## License

[MIT](LICENSE)
