# Decisions (ADR-lite) & open questions

Status: `open` → `proposed` (agent recommendation) → `decided` (PO only) → later maybe `superseded by D-NNN`.
The original v0 plan referred to questions "F1–F4 in chat"; that chat is not in the repo, so they are reconstructed below as D-001, D-003, D-010 and D-011. **PO: if your F1–F4 answers differ, just write them in.**

## Must decide before code (blocks Phase 1)

### D-000 · Project name
- **Status:** proposed
- **Question:** v0 used the working title "Belegbox"; the repo is "DocHamster".
- **Recommendation:** Use **DocHamster** everywhere (repo, code namespace `DocHamster\`, UI name). "Belegbox" is retired.
- **Decision:** _…_

### D-001 · PHP framework: Laravel vs Slim
- **Status:** open
- **Options:**
  - **Laravel** – queue, migrations, mail, storage, policies, rate limiting, testing built in; huge ecosystem; both AI agents know it very well, which reduces style drift. Heavier, more "magic".
  - **Slim 4 + packages** – lean and explicit; every piece (DI, migrations, queue, mail, validation) chosen and wired by hand → more code to write and keep consistent.
- **Recommendation (Agent A):** Laravel. The features DocHamster needs (queue, mail, file storage, policies, migrations, tests) are exactly what Laravel ships, and conventions help two agents produce consistent code. Frontend stays light (Blade + small vanilla JS), so "no heavy framework" still holds for the PWA.
- **Decision:** _…_

### D-002 · Minimum PHP version
- **Status:** proposed
- **Recommendation:** PHP 8.3+ (8.2 leaves security support end of 2026). Follow whatever the chosen framework version requires.
- **Decision:** _…_

### D-003 · Database default
- **Status:** proposed
- **Recommendation:** SQLite as default (FTS5 for search, zero setup), MariaDB/MySQL supported and tested in CI. No DB-specific SQL outside the search adapter.
- **Decision:** _…_

### D-004 · Frontend approach
- **Status:** proposed
- **Recommendation:** Server-rendered templates + small ES-module vanilla JS, no build step required. Allow htmx (≈14 kB) for partial updates if it saves code. No React/Vue.
- **Decision:** _…_

### D-010 · Target hosting
- **Status:** open
- **Question:** Where will PO (and typical users) run it? This decides whether OCR can rely on Tesseract/ocrmypdf on the server.
- **Options:** A) VPS/home server with Docker (recommended) · B) VPS without Docker · C) shared hosting (OCR likely impossible → external provider) · D) NAS.
- **Decision:** _…_

### D-011 · Scope of v1.0: single user/household or teams?
- **Status:** open
- **Question:** Are multiple organisations and the "member" role needed in v1.0, or only owner + tax advisor?
- **Recommendation:** Keep multi-org in the data model from day one (cheap now, expensive later), but the member role UI could move to v1.1 if time is short.
- **Decision:** _…_

## Decide before the relevant phase

| ID | Topic | Needed by | Status | Recommendation |
|----|-------|-----------|--------|----------------|
| D-005 | OCR/image toolchain (Tesseract 5 + ocrmypdf + libvips vs Imagick) | Phase 3 | proposed | Tesseract + ocrmypdf + libvips |
| D-006 | WebAuthn library | Phase 1 (P1-4) | open | `web-auth/webauthn-lib` (or framework-native if Laravel: `laragear/webauthn`) |
| D-007 | App-level file encryption (libsodium) on by default? | Phase 2 | open | Volume encryption documented as required; app-level opt-in |
| D-008 | Org in URL (`/o/{org}/…`) vs active org in session | Phase 1 | proposed | Active org in session, shorter URLs, explicit switcher |
| D-009 | Device cookie SameSite=Strict vs Lax | Phase 1 | open | Lax (pairing via link/QR breaks with Strict); CSRF tokens cover the rest |
| D-012 | Password+TOTP fallback in v1 or passkeys only? | Phase 1 | open | Keep fallback (older devices, tax advisors) |
| D-013 | AI provider for optional enrichment/transcription | Phase 8 | open | Interface only until Phase 8 |
| D-014 | Hosting of the public repo: stays private until v0.x? | anytime | open | Private until v0.3, then public |

## Open questions (agents → PO)

| # | Asked by | Question | Answer |
|---|----------|----------|--------|
| Q-1 | Agent A | Which AI tool is Agent B (Codex, Gemini, ChatGPT, Cursor …)? Determines how it reads AGENTS.md and the branch prefix. | |
| Q-2 | Agent A | Which banks do you actually use? Their export files decide which import templates come first. | |
| Q-3 | Agent A | Is the repo meant to become public on GitHub (MIT) right away, or later? | |
| Q-4 | Agent A | Who are the first real users besides you (household, small business, club)? | |
