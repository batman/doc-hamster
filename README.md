# DocHamster 🐹

Snap a receipt, send it, forget it. DocHamster takes the photo with time and (optional) location, matches it to your Revolut transaction, and builds a clean receipt pack (PDF) for your tax advisor.

> **Status: planning (Phase 0).** There is no runnable code yet. The plan is in [`docs/PLAN.md`](docs/PLAN.md) (German).

## Planned features

- Mobile-first web app (PWA): camera → send, records capture time and optional location, works offline
- Revolut transactions matched automatically to the photos (other banks via CSV/XLSX import)
- Receipt pack for your tax advisor: one PDF with every transaction and its receipt photo
- Server-side OCR (Tesseract), tags, categories, full-text search
- Read-only access for your tax advisor
- Passkey device pairing – no password every time

DocHamster is **not** a tax or bookkeeping app, and it contains **no AI features**.

## Tech (planned)

PHP 8.3+, SQLite (MariaDB optional), server-rendered HTML + small vanilla JS PWA, Tesseract/ocrmypdf, Docker Compose. Final choices: [`docs/collaboration/DECISIONS.md`](docs/collaboration/DECISIONS.md).

## Contributing

This project is built by a human product owner together with two AI agents. Rules for everyone (humans and AIs): [`AGENTS.md`](AGENTS.md) and [`docs/collaboration/WORKFLOW.md`](docs/collaboration/WORKFLOW.md).

## License

[MIT](LICENSE)
