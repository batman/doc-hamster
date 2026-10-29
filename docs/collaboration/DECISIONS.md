# Decisions (ADR-lite) & open questions

Status: `open` → `proposed` (recommendation) → `decided` (by the **owner**; PO may veto) → later maybe `superseded by D-NNN`.
Owner = who decides: **Lead** for technical/server topics, **PO** for product/scope (see WORKFLOW.md §1).
The original v0 plan referred to questions "F1–F4 in chat"; that chat is not in the repo, so they are reconstructed below as D-001, D-003, D-010 and D-011. **PO: if your F1–F4 answers differ, just write them in.**

> Public repo: write decisions generically. No server names, IPs, providers, personal bank or advisor details here.

## Must decide before code (blocks Phase 1)

### D-000 · Project name — Owner: PO
- **Status:** proposed
- **Question:** v0 used the working title "Belegbox"; the repo is "DocHamster".
- **Recommendation (Manager):** Use **DocHamster** everywhere (repo, namespace `DocHamster\`, UI). "Belegbox" retired.
- **Decision:** _…_

### D-001 · PHP framework: Laravel vs Slim — Owner: Lead
- **Status:** open
- **Options:** **Laravel** (queue, migrations, mail, storage, policies, rate limiting, tests built in; strong conventions) · **Slim 4 + packages** (lean, explicit, more wiring by hand).
- **Input (Manager):** Laravel's built-ins cover most of PLAN.md §5.2 and the best registry skills found are Laravel-specific (`docs/SKILLS.md`). Lead decides.
- **Decision:** _…_

### D-002 · Minimum PHP version — Owner: Lead
- **Status:** proposed
- **Input:** PHP 8.3+ (8.2 security support ends 2026-12-31), or whatever the chosen framework requires.
- **Decision:** _…_

### D-003 · Database default — Owner: Lead
- **Status:** proposed
- **Input:** SQLite default (FTS5), MariaDB/MySQL supported; no DB-specific SQL outside the search adapter.
- **Decision:** _…_

### D-004 · Frontend approach — Owner: Lead
- **Status:** proposed
- **Input:** Server-rendered templates + small vanilla ES modules, optional htmx; no SPA framework.
- **Decision:** _…_

### D-010 · Target hosting & network — Owner: Lead
- **Status:** open
- **Question:** Home server/NAS on the LAN, VPS, or shared hosting? Decides whether OCR tools can run on the server **and how a scanner can deliver files** (see D-015).
- **Options:** A) VPS/home server with Docker · B) VPS without Docker · C) shared hosting (OCR likely impossible) · D) NAS.
- **Note:** The concrete server (provider, domain, IP) is **not** recorded here – only the chosen variant.
- **Decision:** _…_

### D-011 · Scope of v1.0: single household or teams? — Owner: PO
- **Status:** open
- **Recommendation (Manager):** Multi-org in the data model from day one; member-role UI may move to v1.1.
- **Decision:** _…_

### D-015 · Primary capture channel: document scanner vs. phone — Owner: PO (product) + Lead (ingestion/network)
- **Status:** proposed
- **Context:** PO prefers connecting a document scanner over a phone app.
- **Recommendation (Manager):** Make the **scanner the primary channel** for batches at home/office and keep a **minimal web upload** (works from phone browser too) as secondary, because receipts are often lost before reaching the scanner. Concretely:
  - Generalise the *Capture* module into **Ingestion** with pluggable channels: (1) **consume folder** – scanner writes PDFs/images into a watched directory, worker imports them; (2) **web upload / API**; later (3) **mail-in** (IMAP mailbox the scanner or user sends to).
  - Batch handling: one file per document (scanner setting), or split a batch PDF by **separator sheets** (blank page or barcode/patch-T page); blank-page removal; duplex.
  - Scanner OCR output (searchable PDF) can be used directly; server OCR remains the fallback.
  - The PWA "camera first" screen (PLAN.md §4.1) is downgraded to S (should) / or a simple upload page.
- **Questions for the Lead (network/server):**
  - How does the scanner reach the server? Same LAN (SMB/SFTP share) is easy; a public server needs SFTP or a small LAN bridge that pushes via the API with a device token – **never plain FTP/SMB over the internet**.
  - Which delivery protocols do we support and document (SMB, SFTP, WebDAV, email)?
  - Security of the consume folder: dedicated, non-web-readable directory; per-channel org mapping; size/type limits; quarantine for invalid files.
- **Questions for the PO:** Which scanner (model or at least: ADF, duplex, which "scan to …" targets it supports)? Is phone capture still wanted at all for on-the-go receipts?
- **Decision:** _…_

## Decide before the relevant phase

| ID | Topic | Owner | Needed by | Status | Input |
|----|-------|-------|-----------|--------|-------|
| D-005 | OCR/image toolchain | Lead | Phase 3 | proposed | Tesseract + ocrmypdf + libvips |
| D-006 | WebAuthn library | Lead | P1-4 | open | `web-auth/webauthn-lib` or framework-native |
| D-007 | App-level file encryption on by default? | Lead | Phase 2 | open | Volume encryption required; app-level opt-in |
| D-008 | Org in URL vs active org in session | Lead | Phase 1 | proposed | Active org in session |
| D-009 | Device cookie SameSite Strict vs Lax | Lead | Phase 1 | open | Lax + CSRF tokens |
| D-012 | Password+TOTP fallback or passkeys only? | Lead | Phase 1 | open | Keep fallback |
| D-013 | AI provider for optional enrichment | Lead | Phase 8 | open | Interface only until Phase 8 |
| D-014 | When does the repo go public? | PO | anytime | open | After P0-8 history scan; suggested at v0.3 |

## Open questions

| # | From → To | Question | Answer |
|---|-----------|----------|--------|
| Q-1 | Manager → PO | ~~Which AI is the second agent?~~ | Claude, as Lead (2026-10-29) |
| Q-2 | Manager → PO | Which bank export formats should be supported first? (Answer generically, e.g. "Sparkasse CSV" – no account details.) | |
| Q-3 | Manager → PO | Public right away, or later? → see D-014 | |
| Q-4 | Manager → PO | Typical first users: household, freelancer, small business, club? | |
| Q-5 | Manager → PO | Scanner details for D-015 (capabilities, not serial numbers or network data). | |
| Q-6 | Manager → Lead | D-010 + D-015: how should a scanner deliver files securely to the chosen hosting variant? | |
