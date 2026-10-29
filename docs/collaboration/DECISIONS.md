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
- **Question:** Home server/NAS on the LAN, VPS, or shared hosting? Decides whether OCR tools can run on the server **and how the phone web app reaches it** (see D-017).
- **Options:** A) VPS/home server with Docker · B) VPS without Docker · C) shared hosting (OCR likely impossible) · D) NAS.
- **Note:** The concrete server (provider, domain, IP) is **not** recorded here – only the chosen variant.
- **Decision:** _…_

### D-011 · Scope of v1.0: single household or teams? — Owner: PO
- **Status:** open
- **Recommendation (Manager):** Multi-org in the data model from day one; member-role UI may move to v1.1.
- **Decision:** _…_

### D-015 · Capture channel: phone web app, no hardware scanner — Owner: PO
- **Status:** decided (PO, 2026-10-29)
- **Decision:** The **phone web app (camera)** is the capture channel. No hardware document scanner. PDF upload from files stays.
- **How the phone app delivers photos to the server** (upload protocol, offline queue, limits) is technical → Lead, see D-017.

### D-016 · Revolut connection — Owner: Lead (technical), PO (account type)
- **Status:** open
- **Goal (PO):** For every Revolut payment, get the full transaction details (merchant, amount, currency, original amount + FX rate, fee, exact time, Revolut category, status, merchant location if available) and match them with the receipt photo.
- **Ways to evaluate (Lead decides):** Revolut Business API (business accounts only) · Revolut statement export (CSV/Excel, works for personal and business, manual upload) · open-banking (PSD2 account information) provider for personal accounts.
- **Constraints:** read-only access; credentials encrypted on the server, never in the repo; file import always available as fallback.
- **Target account type (PO, 2026-10-29):** Revolut **Business** → the official Revolut Business API is available and is the expected primary way; file import stays as fallback. Open banking for personal accounts is out of scope for v1.
- **Still for the Lead:** API access setup (certificates/tokens, refresh), sync interval, which fields the API really delivers (merchant location?), sandbox for tests.
- **Decision:** _…_

### D-017 · Capture time & location; phone → server delivery — Owner: Lead
- **Status:** open
- **Requirement (PO):** The web app records **when** the photo was taken and **where** (PLAN.md §4.1 "Aufnahme-Metadaten").
- **For the Lead:** Geolocation API vs EXIF (EXIF GPS is often stripped by browsers/iOS); clock-skew handling; how coordinates are stored (encrypted? rounded?); how the place name (city) is resolved without sending locations to third parties (offline data vs. self-hosted vs. external service); upload protocol and offline queue between phone and server.
- **Fixed by the PO:** location is opt-in, removable per receipt, and appears in the receipt pack only as a city name and only if switched on.
- **Decision:** _…_

### D-018 · Receipt pack layout ("Belegpaket") — Owner: PO
- **Status:** proposed
- **Proposal (Manager):** PLAN.md §10.1 – cover page with key figures, overview table, one page per transaction with details + large receipt photo, list of missing receipts. Manager drafts the layout in P0-5; PO signs off. PDF technology → Lead.
- **Decision:** _…_

### D-019 · Local processing only, no cloud AI — Owner: PO (principle), Lead (engines)
- **Status:** decided (PO, 2026-10-29), supersedes D-013
- **Decision:** Features like **speech-to-text** (and later local suggestions) are wanted, but **only running locally on our own server**. No data goes to cloud or AI providers, for privacy. Outbound connections only: Revolut API, SMTP.
- **For the Lead:** choose the local speech-to-text engine (e.g. whisper.cpp, Vosk) by German quality, CPU/RAM needs on the chosen hosting (D-010) and license; how to block other outbound traffic from the worker.
- **Engine decision:** _…_

## Decide before the relevant phase

| ID | Topic | Owner | Needed by | Status | Input |
|----|-------|-------|-----------|--------|-------|
| D-005 | OCR/image toolchain | Lead | Phase 3 | proposed | Tesseract + ocrmypdf + libvips |
| D-006 | WebAuthn library | Lead | P1-4 | open | `web-auth/webauthn-lib` or framework-native |
| D-007 | App-level file encryption on by default? | Lead | Phase 2 | open | Volume encryption required; app-level opt-in |
| D-008 | Org in URL vs active org in session | Lead | Phase 1 | proposed | Active org in session |
| D-009 | Device cookie SameSite Strict vs Lax | Lead | Phase 1 | open | Lax + CSRF tokens |
| D-012 | Password+TOTP fallback or passkeys only? | Lead | Phase 1 | open | Keep fallback |
| D-013 | AI in the app | PO | – | superseded by D-019 | (was: no AI features at all) |
| D-014 | When does the repo go public? | PO | anytime | open | After P0-8 history scan; suggested at v0.3 |

## Open questions

| # | From → To | Question | Answer |
|---|-----------|----------|--------|
| Q-1 | Manager → PO | ~~Which AI is the second agent?~~ | Claude, as Lead (2026-10-29) |
| Q-2 | Manager → PO | Which bank export formats should be supported first? (Answer generically, e.g. "Sparkasse CSV" – no account details.) | |
| Q-3 | Manager → PO | Public right away, or later? → see D-014 | |
| Q-4 | Manager → PO | Typical first users: household, freelancer, small business, club? | |
| Q-5 | Manager → PO | ~~Scanner details~~ | Obsolete – no scanner (D-015) |
| Q-6 | Manager → Lead | D-010 + D-017: how does the phone web app deliver photos securely to the chosen hosting variant? | |
| Q-7 | Manager → PO | Revolut personal or business account? | **Business** (2026-10-29) → Business API possible, see D-016 |
