# Security policy

DocHamster stores receipts, invoices and bank data – sensitive personal and financial information. We take security reports seriously.

## Reporting a vulnerability

Please **do not open a public issue**. Use GitHub's private vulnerability reporting instead: *Security → Report a vulnerability* on this repository.

Include what you found, how to reproduce it, and the impact you expect. We aim to acknowledge reports within 7 days.

## Supported versions

DocHamster is in early development (pre-1.0). Only the latest `main` receives fixes.

## For self-hosters

- Run DocHamster only over HTTPS.
- Keep `.env`, the storage directory and backups out of any web-accessible path and out of version control.
- Enable disk/volume encryption on the server and keep a safe copy of any encryption keys – losing them means losing the data.
- Keep PHP, the OCR tools and dependencies up to date.

## For contributors

The repository is public. Never commit personal data, real documents, server details or secrets – see "Public repository rules" in [`AGENTS.md`](AGENTS.md).
