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
- **Status:** decided (Lead, 2026-10-29)
- **Options:** **Laravel** (queue, migrations, mail, storage, policies, rate limiting, tests built in; strong conventions) · **Slim 4 + packages** (lean, explicit, more wiring by hand).
- **Input (Manager):** Laravel's built-ins cover most of PLAN.md §5.2 and the best registry skills found are Laravel-specific (`docs/SKILLS.md`). Lead decides.
- **Decision:** **Laravel**, current stable major at P1-1, pinned in `composer.json`. Reasons: queue with retries and multiple queues (needed for the D-020 egress split), migrations, policies/gates for the permission matrix, rate limiter, encrypted casts (D-007), mail, filesystem abstraction, Pest – all things we would otherwise wire and test by hand in Slim. Two agents also benefit from strong framework conventions. Limits we set: Blade templates, no Livewire/Inertia/SPA (D-004), no Laravel cloud services, no Sanctum/Passport (own device-token auth, PLAN §11.1). Domain code stays in `app/Domain/*` per PLAN §5.3/§5.4, framework glue outside it.

### D-002 · Minimum PHP version — Owner: Lead
- **Status:** decided (Lead, 2026-10-29)
- **Input:** PHP 8.3+ (8.2 security support ends 2026-12-31), or whatever the chosen framework requires.
- **Decision:** **PHP 8.4 minimum.** It is the PHP version of Debian 13, so the standard install (D-010) works there without third-party repos; Ubuntu 24.04 LTS ships 8.3 and needs the common PHP PPA (check newer Ubuntu releases when writing the install docs). Web hosts usually let you pick the PHP version per site. 8.3 would add a version to test for no gain in a new project. Revisit when Debian/our image moves on.

### D-003 · Database default — Owner: Lead
- **Status:** decided (Lead, 2026-10-29)
- **Input:** SQLite default (FTS5), MariaDB/MySQL supported; no DB-specific SQL outside the search adapter.
- **PO input (2026-10-29, Q-8):** stick to common standards; MariaDB is expected as the prerequisite; support several databases if the effort is small.
- **Decision:** **MariaDB and MySQL**, both supported from v1, via Laravel's `mariadb` and `mysql` drivers.
  - **Minimum versions:** MariaDB **10.11** LTS (community support until 2028-02; default in Ubuntu 24.04 LTS and Debian 12; Debian 13 ships 11.8) · MySQL **8.4** LTS (8.0 reached end of life in 2026-04). Recommended: the newest LTS the server offers. Laravel itself accepts older versions (MariaDB 10.3+, MySQL 5.7+), but those no longer get security fixes.
  - **Compatibility:** MariaDB started as a fork of MySQL; for ordinary applications both are interchangeable, but they have drifted apart since MySQL 8 (JSON functions, some SQL functions, default auth plugins). We stay in the **common subset**: InnoDB, `utf8mb4`, standard SQL via the query builder, `FULLTEXT` indexes with `MATCH … AGAINST` for search (works on both), JSON columns only stored/read whole – no DB-specific JSON functions in queries. CI runs the test suite on **both** (MariaDB 10.11 + MySQL 8.4) – that is the "small effort" for the second database.
  - **Search quality:** MariaDB/MySQL `FULLTEXT` has no German stemming. AK-4.3.1 says "wenn möglich" – we normalise words in PHP before indexing (lower case, umlauts, simple suffix rules) if tests show it is needed.
  - **Not supported in v1:** SQLite (different full-text engine, would need a second search adapter) and PostgreSQL. Code stays DB-neutral (search behind a `SearchIndex` interface), so PostgreSQL could follow later if wanted.
  - Supersedes the earlier SQLite proposal. PLAN.md §3 principle 7 ("SQLite-Standard"), §5.1, §5.2, §6.2 `documents_fts`, §12 and §13 (CI "SQLite + MariaDB-Matrix") need updating in P0-1c, see PLAN-REVIEW B11.

### D-004 · Frontend approach — Owner: Lead
- **Status:** decided (Lead, 2026-10-29)
- **Input:** Server-rendered templates + small vanilla ES modules, optional htmx; no SPA framework.
- **Decision:** Blade templates + vanilla ES modules served as-is (no build step required; no Vite/npm needed to run the app). Own small CSS with custom properties (light/dark). htmx only if a concrete screen needs it (matching UI is the likely candidate) – vendored, no CDN (CSP, privacy). Strict CSP without inline scripts from day one.

### D-010 · Target hosting & network — Owner: Lead
- **Status:** decided (Lead with PO, 2026-10-29)
- **Question:** Home server/NAS on the LAN, VPS, or shared hosting? Decides whether OCR tools can run on the server **and how the phone web app reaches it** (see D-017).
- **Options:** A) VPS/home server with Docker · B) VPS without Docker · C) shared hosting (OCR likely impossible) · D) NAS.
- **Note:** The concrete server (provider, domain, IP) is **not** recorded here – only the chosen variant.
- **PO input (Q-8, 2026-10-29):** follow common standards, as compatible as reasonable; publish one minimum requirement; the phone must reach the server from anywhere; **no Docker needed**; the app detects its environment itself and offers only add-ons that work there.
- **Decision:**
  - **One app, one minimum requirement, no hosting types.** DocHamster runs anywhere the minimum below is met: own server (VPS, home server, NAS) or web hosting. There is no "server edition" vs "hosting edition".
  - **No Docker in v1.** Standard install = upload/clone the code, `composer install`, `.env`, `php artisan dochamster:setup`, cron entry. Install docs for a plain Linux server (Debian/Ubuntu) and for typical web hosting. A Docker setup may come later as a convenience, not as a requirement.
  - **Environment detection:** `dochamster:doctor` (at setup, after updates, and on the admin page) checks PHP version and extensions, DB version, cron heartbeat, HTTPS, upload limits, `proc_open` and the external tools. Add-ons whose requirements are missing are **not offered or shown greyed out** with the reason ("Texterkennung braucht Tesseract – auf diesem Server nicht vorhanden"). The core flow never depends on an add-on.
  - **Core (always available):** capture + offline queue, time/location, Revolut sync and matching, receipt pack PDF (pure PHP), search, tax advisor access, mail.
  - **Add-ons (only if the environment has them):** OCR (Tesseract 5 + ocrmypdf), local speech-to-text (whisper.cpp + ffmpeg, D-019), HEIC conversion of file uploads (libheif/vips), faster image processing (vips instead of GD).
  - **Background work runs via cron:** `schedule:run` every minute starts short queue runs (`queue:work --stop-when-empty --max-time=50`). A permanent worker process (systemd/supervisor) is optional for own servers. Long jobs (OCR, STT) are split per page/file so they fit hosting time limits.
  - **Hard requirement:** a fixed domain name with a publicly trusted TLS certificate, reachable from the internet via HTTPS. Reason: passkeys are bound to the domain (changing it later breaks all passkeys), the PWA/service worker needs a secure context, and the phone sends documents from anywhere.
  - **Minimum requirements** – published in the README, checked by `dochamster:doctor`:

    | Area | Minimum | For add-ons / recommended |
    |------|---------|---------------------------|
    | Server | Linux server or web hosting with SSH and cron (every minute) | own server for OCR/STT add-ons |
    | CPU / RAM | 2 cores, 2 GB RAM (excl. DB) | 4 cores, 6 GB for local STT |
    | Disk | 10 GB + receipts (≈ 2 MB per receipt) | SSD |
    | PHP | 8.4 with `pdo_mysql`, `mbstring`, `intl`, `sodium`, `fileinfo`, `exif`, `zip`, `gd`; `memory_limit` ≥ 256 MB; `upload_max_filesize`/`post_max_size` ≥ 26 MB | `vips` extension; `proc_open` allowed (needed by all add-ons) |
    | Database | MariaDB 10.11 or MySQL 8.4 (D-003) | newest LTS |
    | Network | own domain, publicly trusted TLS, inbound HTTPS; outbound HTTPS to the Revolut API, SMTP | – |
    | Add-on tools | – | Tesseract 5, ocrmypdf, ffmpeg, whisper.cpp, libheif |
    | Phone | current iOS Safari / Android Chrome (last 2 major versions) | installed as home-screen app |

    Estimates to verify in P1 on the PO's test server (its details stay out of the repo).
  - **Data files:** GeoNames place names (D-017) are loaded by the setup/update command (pinned version + checksum), Tesseract language data and the Whisper model by the add-on's install step. Nothing is downloaded while processing documents.

### D-011 · Scope of v1.0: single household or teams? — Owner: PO
- **Status:** open
- **Recommendation (Manager):** Multi-org in the data model from day one; member-role UI may move to v1.1.
- **Decision:** _…_

### D-015 · Capture channel: phone web app, no hardware scanner — Owner: PO
- **Status:** decided (PO, 2026-10-29)
- **Decision:** The **phone web app (camera)** is the capture channel. No hardware document scanner. PDF upload from files stays.
- **How the phone app delivers photos to the server** (upload protocol, offline queue, limits) is technical → Lead, see D-017.

### D-016 · Revolut connection — Owner: Lead (technical), PO (account type)
- **Status:** decided (Lead, 2026-10-29)
- **Goal (PO):** For every Revolut payment, get the full transaction details (merchant, amount, currency, original amount + FX rate, fee, exact time, Revolut category, status, merchant location if available) and match them with the receipt photo.
- **Ways to evaluate (Lead decides):** Revolut Business API (business accounts only) · Revolut statement export (CSV/Excel, works for personal and business, manual upload) · open-banking (PSD2 account information) provider for personal accounts.
- **Constraints:** read-only access; credentials encrypted on the server, never in the repo; file import always available as fallback.
- **Target account type (PO, 2026-10-29):** Revolut **Business** → the official Revolut Business API is available and is the expected primary way; file import stays as fallback. Open banking for personal accounts is out of scope for v1.
- **Still for the Lead:** API access setup (certificates/tokens, refresh), sync interval, which fields the API really delivers (merchant location?), sandbox for tests.
- **Decision (Lead, checked against the official Business API docs on 2026-10-29):**
  - **Primary: Revolut Business API, read-only, polling.** File import (CSV/Excel statement) stays as fallback and for history.
  - **Access setup (one-time, by the owner in Settings):** the app generates an RSA key pair and shows the public certificate; the owner adds it in the Revolut Business web app (Business API settings) with the redirect URI `https://<our domain>/settings/bank/revolut/callback`, then pastes the Client ID; the app opens the consent page with **`scope=READ`** (no WRITE/PAY – a leaked token can't move money). The callback stores the authorisation code (valid 2 min) and dispatches a high-priority `net` job that exchanges it – `app` itself never calls Revolut (D-020). The redirect is a browser redirect, so it also works when the server is only reachable on the LAN/VPN.
  - **Tokens:** access token lives 40 min; refresh token does not expire. Store refresh token and private key encrypted (D-007); sign a fresh client-assertion JWT with a short `exp` (5 min) for every refresh. Refresh invalidates the previous access token → only one process refreshes (lock). If refresh fails (consent revoked), the connection goes to `status = error` and the owner is notified. Optional IP allowlist: owner's choice, only with a static public IP.
  - **Sync:** A `net` job polls `GET /transactions` (via cron, D-010) every 15 min, plus right after each photo upload (debounced 1 min), window = last sync − 3 days overlap; pages with `to = created_at` of the last item, `count = 1000`, and the next page overlaps by the last item's timestamp (upsert removes the duplicates, so no row with an equal timestamp is skipped). The time filter works on `created_at`, so long-running card holds (hotel, fuel, car hire) can drop out of the window: every transaction still `pending` is re-fetched one by one (`GET /transaction/{id}`) until final (`completed`/`declined`/`failed`/`reverted`). Upsert by Revolut `id` (`external_id`). No webhooks in v1: they need a publicly reachable endpoint, polling works for every hosting variant.
  - **Fields we get** (`card_payment` example): `created_at` (authorisation time → used for matching), `completed_at`, `state`, `merchant.name/city/country/category_code` (MCC), per leg `amount` + `currency` (account side), `bill_amount` + `bill_currency` (original currency), `fee`, `description`. The transaction object has **no Revolut spending category**; we store the MCC as `provider_category` and show it as readable text via an MCC → German label table (PLAN-REVIEW B19, PO agreed in Q-10). Cardholder name/phone are **dropped** before storing `raw_json` (PLAN-REVIEW B8).
  - **Tests:** unit/feature tests use recorded, synthetic API responses (no network in CI). Manual end-to-end check against the Revolut **Sandbox** (needs a free sandbox account, owner/PO creates it; credentials never in the repo).
  - **Watch out:** on the Freelancer plan, history older than 90 days is only readable within 5 min of the first authorisation (PSD2) → the app runs a full history import right after consent. The Expenses API (receipts inside Revolut) is not used: photos stay on our server (D-019).
  - **Sources (official docs, read 2026-10-29):** setup, token lifetimes, "refresh token does not expire", "refreshing invalidates the previous token": <https://developer.revolut.com/docs/guides/manage-accounts/get-started/make-your-first-api-request> · scopes READ/WRITE/PAY, transaction fields, paging, max 1,000 per request: <https://developer.revolut.com/docs/business/get-transactions> · Freelancer 90-day rule: <https://developer.revolut.com/docs/guides/manage-accounts/accounts-and-transactions/retrieve-transactions> (seen in search results, re-check when implementing P3-3).

### D-017 · Capture time & location; phone → server delivery — Owner: Lead
- **Status:** decided (Lead, 2026-10-29); camera method decided in P2-2 after a device test (PLAN-REVIEW B5)
- **Requirement (PO):** The web app records **when** the photo was taken and **where** (PLAN.md §4.1 "Aufnahme-Metadaten").
- **For the Lead:** Geolocation API vs EXIF (EXIF GPS is often stripped by browsers/iOS); clock-skew handling; how coordinates are stored (encrypted? rounded?); how the place name (city) is resolved without sending locations to third parties (offline data vs. self-hosted vs. external service); upload protocol and offline queue between phone and server.
- **Fixed by the PO:** location is opt-in, removable per receipt, and appears in the receipt pack only as a city name and only if switched on.
- **Decision (Lead):**
  - **Time:** at the shutter the PWA stores `captured_at` (device clock, ms) + IANA time zone (`Intl`) + UTC offset. EXIF `DateTimeOriginal` is read **in the browser before resizing** (canvas drops EXIF, B4) and sent as a cross-check. This only exists on the `<input capture>`/file path; the in-page camera (`getUserMedia`) has no EXIF at all, and iOS usually strips GPS from picked/captured photos – so EXIF is a time cross-check, rarely a location source. Part of the P2-2 prototype criteria. Every upload also sends `client_sent_at`; the server stores `skew = received_at − client_sent_at`, keeps the raw value and a corrected `captured_at`, and flags |skew| > 2 min. Source stays `device`/`exif`/`server` as in PLAN.
  - **Location:** `watchPosition` starts when the capture screen opens (only with opt-in), so a fix is usually ready at the shutter; at most 3 s wait, else saved without location and a late fix is attached if it arrives within 60 s. Stored: lat/lon/accuracy **encrypted** (app key, D-007), full precision; never logged.
  - **Place name without third parties:** offline reverse geocoding from the GeoNames `cities1000` dataset (CC BY 4.0, attribution in the app's About page and docs), loaded into a database table at install/update; nearest city by bounding box + haversine, in the `local` worker. No external geocoding service.
  - **Phone → server (answers Q-6):** HTTPS only, same origin as the PWA. **One request per file**, so a document with 20 pages or a 25 MB PDF never has to go up in one piece and an interrupted upload resumes at the next missing page (AK-4.1.4):
    1. `PUT /api/uploads/{client_upload_id}/files/{n}` – one page/file (raw body + content type); idempotent per (`org_id`, `client_upload_id`, `n`), a repeat with the same SHA-256 returns 200.
    2. `POST /api/uploads/{client_upload_id}/complete` – JSON with page count, capture metadata and `client_sent_at`; creates the document. A repeat returns the existing document (200) instead of a new one (201).
    `client_upload_id` is a ULID made on the phone. Limits written down in config and install docs: 25 MB per file (AK-4.1.6) → PHP `upload_max_filesize`/`post_max_size` and the web server's request body limit set to ≥ 26 MB (checked by `dochamster:doctor`); unfinished uploads are deleted after 7 days.
  - **Auth and CSRF for the API:** device cookie (D-009) + session. Queued uploads may be sent a day later, when the session's CSRF token is long gone (HTTP 419). Therefore `/api/*` does **not** use the session CSRF token; instead it requires same-origin (`Origin` header = our origin, `Sec-Fetch-Site: same-origin` where sent) plus a custom request header (`X-DocHamster-Client: 1`, which a cross-site form can't set). HTML forms keep Laravel's normal CSRF token. E2E test: queue offline, expire the session, go online → upload succeeds.
  - **Offline queue:** IndexedDB holds blobs + metadata until the server confirms. Sent on app open, on `online`, on `visibilitychange`, and via Background Sync where available (not on iOS). `navigator.storage.persist()` requested; the app recommends installing to the home screen (iOS evicts storage of non-installed sites). Badge shows queue length.

### D-018 · Receipt pack layout ("Belegpaket") — Owner: PO
- **Status:** proposed
- **Proposal (Manager):** PLAN.md §10.1 – cover page with key figures, overview table, one page per transaction with details + large receipt photo, list of missing receipts. Manager drafts the layout in P0-5; PO signs off. PDF technology → Lead.
- **Decision:** _…_

### D-019 · Local processing only, no cloud AI — Owner: PO (principle), Lead (engines)
- **Status:** decided (PO, 2026-10-29), supersedes D-013
- **Decision:** Features like **speech-to-text** (and later local suggestions) are wanted, but **only running locally on our own server**. No data goes to cloud or AI providers, for privacy. Outbound connections only: Revolut API, SMTP.
- **For the Lead:** choose the local speech-to-text engine (e.g. whisper.cpp, Vosk) by German quality, CPU/RAM needs on the chosen hosting (D-010) and license; how to block other outbound traffic from the worker.
- **Engine decision (Lead, 2026-10-29):** proposed – **whisper.cpp** (MIT) with a multilingual Whisper model, run as a CLI from a `local` job; an add-on, offered only where whisper.cpp is installed (D-010). Reason: clearly better German than Vosk's German models, runs on CPU, no Python stack. Model size: default `small` (quantized) for the minimum requirements in D-010, `medium` when the server has ≥ 4 cores; configurable. Final after a 30-s benchmark on the target hardware in P5-7 (AK-4.7.1). Audio is converted with ffmpeg to 16 kHz WAV first.
- **Blocking other outbound traffic:** see **D-020** (Lead).

### D-020 · Egress control without containers — Owner: Lead
- **Status:** decided (Lead, 2026-10-29) – implements the "outbound only Revolut + SMTP" rule of D-019; no Docker (D-010)
- **Decision:**
  - Jobs are split into two queues: `local` (normalize, OCR, STT, geocoding, matching, receipt pack PDF) and `net` (Revolut token exchange and sync, all mail incl. invitations and export links). **Only `net` jobs may talk to the outside**; the Revolut API client and the mailer are only reachable from `net` jobs. Web requests (`app/Http`) never make outbound calls themselves – e.g. the Revolut callback dispatches a `net` job, mail is always queued, never sent synchronously.
  - **Enforcement in code:** an architecture test (Pest arch tests) fails if anything outside the `net` job classes uses the HTTP client, mailer or sockets. The external add-on tools (Tesseract, whisper.cpp, ffmpeg) are called with argument arrays and have no network features we use.
  - **Proof for AK-4.7.2:** CI runs the `local` job suite (OCR, STT, geocoding, PDF) inside a runner step with networking disabled; any outbound attempt fails the build. This is CI tooling only, not something users install.
  - **On own servers (optional, documented):** run the `local` worker as its own system user and block its outbound traffic with a firewall rule (e.g. nftables/iptables owner match), allow only Revolut API + SMTP for the `net` worker. On web hosting this isn't possible; there the code rules above are the protection – the doctor page says so.

## Decide before the relevant phase

| ID | Topic | Owner | Needed by | Status | Input |
|----|-------|-------|-----------|--------|-------|
| D-005 | OCR/image toolchain | Lead | images: Phase 2 · OCR: Phase 5 | proposed | Core: GD (always available) for thumbnails, orientation and re-encoding – Phase 2 (P2-7). Add-ons (D-010): vips for speed, libheif for HEIC file uploads, Tesseract 5 + ocrmypdf for OCR (Phase 5). Final in P2-0 / P5-1 |
| D-006 | WebAuthn library | Lead | P1-4 | proposed | Lead: `web-auth/webauthn-lib` (maintained, FIDO conformance tests) behind our own `PasskeyService`; final in P1-4 |
| D-007 | App-level file encryption on by default? | Lead | Phase 2 | fields: decided (Lead, 2026-10-29) · files: proposed | **Decided:** small secrets/PII fields are always encrypted with Laravel encrypted casts (Revolut refresh token + private key, coordinates, any future TOTP secret) – D-016/D-017 rely on this. **Proposed:** file encryption opt-in, off by default; volume encryption documented as strongly recommended; key backup warning in setup |
| D-008 | Org in URL vs active org in session | Lead | Phase 1 | decided (Lead, 2026-10-29) | Active org in session; every query org-scoped by middleware + policies regardless (PLAN §11.2 IDOR) |
| D-009 | Device cookie SameSite Strict vs Lax | Lead | Phase 1 | decided (Lead, 2026-10-29) | **Lax** + `Origin` check; CSRF token for HTML forms, same-origin + custom header for `/api/*` (D-017). Strict would drop the cookie when the app is opened from a mail/QR link and look like a logout |
| D-012 | Password+TOTP fallback or passkeys only? | Lead (PO requirement Q-9) | Phase 1 | decided (Lead after PO answer, 2026-10-29) | **By role.** *Owner/member:* passkeys + device token (PLAN §11.1), no password; recovery = owner issues a new pairing link, setup CLI for the owner. *Tax advisor (`accountant`):* **username + password**, nothing to install, no app, no phone needed. **No self-registration:** the owner creates the account (username, role, share scope) and the app generates a strong random password (≥ 20 characters) that is shown to the owner **once** to pass on; the owner can reset it any time (old sessions end). Safeguards: Argon2id hash, rate limit + temporary lockout after repeated failures, session 8 h without long-lived device token, logins in the audit log, account inherits share expiry/revocation (§4.6). The advisor may change the password; optional TOTP for the advisor can come later (C). Affected PLAN sections: PLAN-REVIEW B18 |
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
| Q-6 | Manager → Lead | D-010 + D-017: how does the phone web app deliver photos securely to the chosen hosting variant? | HTTPS to one fixed, publicly reachable domain with a trusted certificate (D-010), per-file idempotent upload with offline queue (D-017); same for every environment (Lead, 2026-10-29) |
| Q-7 | Manager → PO | Revolut personal or business account? | **Business** (2026-10-29) → Business API possible, see D-016 |
| Q-8 | Lead → PO | For D-010/D-019: where should DocHamster run – **VPS** or **always-on home server/NAS**? Roughly how many CPU cores and how much RAM, x86 or ARM? Should the phone reach it from the mobile network (public HTTPS) or only at home/via VPN? (Answer generically – no provider, IP or domain.) | Partly (PO, 2026-10-29): follow common standards, as compatible as reasonable, not every environment; MariaDB as prerequisite → D-003, tiers in D-010. PO, 2026-10-29 (2): the phone must reach the server from anywhere → public HTTPS; define and publish minimum requirements (table in D-010); a stable test server is available. PO, 2026-10-29 (3): no Docker needed; the app detects its environment and greys out add-ons that don't work there → D-010 decided |
| Q-9 | Lead → PO | D-012: passkeys only, or keep password + TOTP as fallback? Passkeys only = less code and attack surface, but a tax advisor on a desktop without Windows Hello/Touch ID must confirm with the phone (QR) or a security key. | **PO, 2026-10-29:** the tax advisor installs nothing and needs no phone app; logs in with **username + password**; no self-registration – the owner/admin assigns username and password. → D-012 |
| Q-10 | Lead → PO | PLAN-REVIEW B19: Revolut's API has no spending category, only the merchant category code (MCC). OK to show the MCC as a German label (e.g. "Tankstelle", "Restaurant") in the app and receipt pack instead of a "Revolut-Kategorie"? | **Yes** (PO, 2026-10-29) → B19 |
