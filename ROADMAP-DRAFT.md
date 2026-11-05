# DocHamster roadmap draft (for discussion)

Status: **external input, not a plan change.** Grounded in `docs/PLAN.md`
(v2), `docs/collaboration/TASKS.md`, and roadmap issue #35. If the PO/Lead
accept parts of it, they get folded into PLAN.md / TASKS.md through the
normal process (new scope = `plan` PR, Lead decides).

Scope guardrails (PLAN §1, non-negotiable here): no tax calculation, no
bookkeeping records (SKR, Buchungssätze), no FinTS/HBCI, no payments (read
only), no native app, no hardware scanner, no cloud AI — everything local.

## Where things stand (2026-11-05)

Done: Phase 1 foundation large parts, setup wizard + installer, basic bank
CSV import + manual match (MVP-CSV), ZIP export (MVP-ZIP), people/roles/tags
(MVP-*), UI restyle rounds, Docker try-out (#63/#64).
In progress: PHONE capture (#37), passkeys (P1-4 still todo — issue #35
says password login stays until then, but passkeys must land before
recommending DocHamster to others).
Order of work follows PLAN phases (P2 capture → P3 bank/match → P4 pack →
P5 OCR → P6 search → P7 advisor → P8 release) as re-sequenced by #35
(phone early, interface before new ways in).

## The 8 planned features, split into tasks

### 1. Mobile-first PWA: camera → send, time + location, offline

Plan: P2 (v0.2). Board: PHONE #37 steps 1–2 in progress.

- PWA shell + install prompt + home-screen guide (iPhone/Android differ)
- Camera screen + real-device comparison of camera methods (B5 → Q-18)
- Multi-page bundle (≤ 20) + per-file idempotent upload API
- Offline queue (IndexedDB) + resume + visible badge; iOS path is primary
- Capture metadata: device time + skew handling, opt-in location, offline
  place names via GeoNames loader (pinned + checksummed, D-017)
- Post-capture chips (note, category, payment method)
- (S) Web Share Target — "Share → DocHamster" from other apps
- Needs PO: real-device tests (P0-22), Q-18 answer after the test

### 2. Bank import for any bank (CSV/Excel) + automatic matching

Plan: P3 (v0.3), AK-4.4.1…9, E4. Done: MVP-CSV basics.

- Close MVP-CSV gaps: XLSX, split debit/credit columns, "needs no receipt"
- Import wizard polish + saved per-account profiles + 5-bank proof (AK-4.4.8)
- Matching engine: time/amount/merchant/place score + stored reasons (P3-5)
- Calibration fixtures (synthetic pairs with known outcomes)
- Reconcile UI: 3 views + 1-tap confirm + drag & drop (P3-6); n:m (S)
- (S, later) CAMT.053 / MT940 import

### 3. Live bank modules — installable, Revolut Business first

Plan: D-016/D-021, P3-4a/b. Blocked on: Q-11 (Lead), P0-10 (PO mail).

- Q-11 first: `BankConnector` shape + one-command module install/enable (P3-4a)
- Revolut module in its own repo: connector, `created_at` matching (B7),
  cardholder-data trim (B8), READ-only scope (B9)
- Guided setup wizard: ≤ 10 min without docs (AK-4.4.R5)
- Sandbox end-to-end test (needs PO sandbox account, P0-10 answer first)
- (S) "Matches …?" one-tap confirm right after the photo (P3-7)

### 4. Receipt pack for the tax advisor (one PDF)

Plan: P4 (v0.4 = core flow done), D-018 proposed. Board: layout draft in
review (P0-5).

- Layout sign-off with the operator (Q-19) — blocks the builder
- Run the B12 benchmark with `scripts/b12-corpus.php` → confirm/reject Dompdf
- Pack builder: cover, overview table, one page per booking + photo,
  missing-receipt list, immutable versions
- Companions: ZIP + index.csv, CSV/XLSX overview, async + expiring links
- Mail-link sending (link, never big attachments)
- (C) Eigenbeleg template for missing receipts

### 5. Server-side OCR, tags, categories, full-text search

Plan: P5 + P6 (v0.5/v0.6), E3 (≥ 80% date+amount on German receipts).

- Finalize D-005 toolchain + doctor detection + greyed-out UX (D-010)
- Pipeline jobs: normalize → OCR → field extraction → rules/merchants →
  duplicates → full-text index (all `local` queue, D-020)
- Extraction fixture benchmark in CI (≥ 30 anonymized receipts, E3)
- Search UI: search bar, filters, tiles/table, detail view, review mode
- Trash + restore + step-up delete
- Perf gate: 10k documents, search < 1 s (P8-1)

### 6. Voice notes with local speech-to-text

Plan: S, P2-8 + P5-7. Engine proposed: whisper.cpp, CLI from a `local`
job (D-019, to be finalized after benchmark).

- Record + play in the capture flow (P2-8, can precede STT)
- 30-second benchmark on target hardware → finalize engine (AK-4.7.1)
- TranscribeAudio job → searchable, editable transcript
- Per-org kill switch; no-egress proof in CI (AK-4.7.2)

### 7. Read-only access for the tax advisor

Plan: P7 (v0.7). Interim decided (Q-20): all receipts read-only + export
until shares exist. Done: account management (MVP-PEOPLE, D-012).

- Shares model: scope (period/accounts/categories) + expiry (P7-1)
- Read-only surface over packs/list/detail/download, writes locked (P7-2)
- Instant revoke + owner audit view (P7-3)
- Full rights-matrix review against the plan (P7-4, Manager + Lead)

### 8. Passkey pairing — no passwords in daily use

Plan: P1-4/P1-5 (still todo). Advisor keeps username + password (D-012).

- Finalize WebAuthn library (D-006) behind `PasskeyService`
- Pairing: QR/link + in-PWA code for installed-app cookie stores (B14)
- Real-device pairing test, incl. owner recovery path (P0-22)
- Device tokens: rotation, theft detection, list/revoke, step-up actions

## Additional integration suggestions (PROPOSALS, beyond v1 scope)

Each needs PO/Lead acceptance first; none is in PLAN.md today.

1. **E-invoice ingestion (ZUGFeRD/Factur-X + XRechnung).** ZUGFeRD embeds
   machine-readable XML in the invoice PDF, so DocHamster could extract
   merchant/amount/date exactly instead of OCR-guessing them. Pure-PHP
   MIT-licensed readers exist (e.g. horstoeko/zugferd,
   dealerweb/einvoice), so this fits D-022 and needs no new infra — a
   `local`-queue step plus an accepted-file note. Sources: ZUGFeRD
   open-source libraries page (inspected 2026-11-05). Note: issue-side
   sending is out (DocHamster never issues invoices); ingestion only.
2. **Paperless-ngx migration import.** Many self-hosters already run
   Paperless-ngx; a one-shot importer (documents + tags + originals via
   its REST API: list/search on `/api/documents/`, file via
   `/api/documents/{id}/download/`, token auth) would win those users.
   Source: official Paperless-ngx `docs/api.md` (inspected 2026-11-05).
   Read-only against the foreign instance; runs as a `net`-queue job or
   a one-shot artisan command (Lead decides).
3. **Tax-advisor pipeline export (DATEV direction) — UNVERIFIED, needs
   evaluation.** Pushing receipt images (+ index, never booking records
   — the no-bookkeeping non-goal stands) into the advisor's DATEV
   inbox would remove the "download pack, upload to DATEV" media break.
   Evidence gap: DATEV's developer portal returned no inspectable
   content in this run; verify the `accounting:documents` API
   (DATEVconnect online, "Belegbilder übergeben") incl. partner terms
   before scoping. If the terms are hostile, drop it — the PDF pack
   stays the universal path.
4. **More bank modules on the same pattern.** Once `BankConnector` +
   install mechanism exist (Q-11/P3-4a), further bank APIs become
   repeat work in own repos per D-021. Explicitly not PSD2/XS2A account
   aggregation for personal accounts in v1 (scoped out, D-016) — that
   road needs its own licensing/compliance decision later.
5. **Backup-target push.** The D-023 backup ZIP exists; add an optional
   scheduled push of the (encrypted) ZIP to user-provided storage.
   Generic mechanism, no new vendors in the core; credentials encrypted
   like bank tokens (D-007).

## Inputs needed from PO / Lead (already on the board)

Q-18 camera taps, Q-19 pack layout, Q-11 connector shape, P0-10 Revolut
mail + sandbox account, fixed domain before P1-4, device tests (P0-22),
bank sample exports (P0-4). Nothing new invented here.
