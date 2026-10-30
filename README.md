# DocHamster 🐹

Snap a receipt, send it, forget it. DocHamster takes the photo with time and (optional) location, matches it to your bank transactions (CSV/Excel import from any bank), and builds a clean receipt pack (PDF) for your tax advisor.

> **Status: planning (Phase 0).** There is no runnable code yet. The plan is in [`docs/PLAN.md`](docs/PLAN.md) (German).

## Planned features

- Mobile-first web app (PWA): camera → send, records capture time and optional location, works offline
- Bank statement import for **any bank** (CSV/Excel, mapping wizard), matched automatically to the photos
- Interface for live bank modules – e.g. an installable **Revolut Business** module with a step-by-step setup
- Receipt pack for your tax advisor: one PDF with every transaction and its receipt photo
- Server-side OCR (Tesseract), tags, categories, full-text search
- Voice notes with local speech-to-text
- Read-only access for your tax advisor
- Passkey device pairing – no password every time

DocHamster is **not** a tax or bookkeeping app, and it uses **no cloud AI**: speech-to-text and everything else runs on your own server.

## Tech (planned)

PHP 8.3+, Laravel, MariaDB 10.11+ or MySQL 8.4+, Blade + small vanilla JS PWA (no build step). No Docker needed: runs on any Linux server or web hosting that meets the minimum requirements; optional extras (OCR with Tesseract/ocrmypdf, local speech-to-text) only where the server has them. Final choices: [`docs/collaboration/DECISIONS.md`](docs/collaboration/DECISIONS.md).

## Contributing

This project is built by a human product owner together with two AI agents. Rules for everyone (humans and AIs): [`AGENTS.md`](AGENTS.md) and [`docs/collaboration/WORKFLOW.md`](docs/collaboration/WORKFLOW.md).

## License

[MIT](LICENSE)
