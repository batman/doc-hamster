# Task board

Status values: `todo` · `in progress` · `in review` · `blocked` · `done`.
Owner: `PO` · `Lead` · `Manager` · `–` (free).
Task IDs match the work packages in `../PLAN.md` §14. Maintained by the Manager; claim a task by editing its row (see WORKFLOW.md §3).

> **Issue/PR numbers:** the project moved to a fresh GitHub repository on 2026-10-30 (history rewrite for the leak, see LOG.md). Numbers written before that date (e.g. "PR #19", "issue #18") refer to the **previous** repository; new numbers start again at #1. Open items were re-created: old issue #18 → #4.

## Current goal (PO, 2026-10-30): install on the PO's server as soon as possible

Goes before other Phase 0/1 work. Plan and split in **issue #12** (Lead confirms), PO checklist in **issue #13**.

| Milestone | What | Who | Status |
|-----------|------|-----|--------|
| M1 – Installation läuft | PO installs `v0.1.1` (installer → wizard → owner → login) and reports; Lead fixes findings → `v0.1.2`; cron line in the wizard; short install guide | PO (#13), Lead, Manager (guide, if assigned) | in progress |
| M2 – Belege fotografieren | P2-0, P2-1, P2-2, P2-3, P2-4, P2-7 + minimal list; login decision (interim password vs. passkeys first) | Lead, Manager (sub-tasks the Lead marks in #12) | todo |
| M3 – Kernablauf | bank CSV import, matching, receipt pack (Phase 3/4) | Lead + Manager | todo |

## Work split (maintained by the Manager)

**Lead** – decides and answers all questions (technical + product), leads the implementation, assigns coding sub-tasks to the Manager.
**Manager** – keeps this split and the board current, codes **together with the Lead** on assigned sub-tasks, reviews the Lead's PRs critically. Decides nothing.
**Lead's operator** (the person running the Lead session) – gets only **product-design and hard-to-change questions** from the Lead, briefly and in plain words, each with a `Q-N` row (AGENTS rule 6), never tasks or to-dos.
**PO** – can veto anything (authors merge their own PRs, reviews follow after the merge), and does what only a human can do (list below, issue `to:po`). Gets no questions.

### Questions for the Lead's operator (decisions only)

Only product-design and hard-to-change questions (AGENTS rule 6, operator rule 2026-10-29); the Lead decides everything technical itself. Re-checked 2026-10-29: D-000, D-011, D-025, P0-1b, Q-2, Q-3, Q-4, Q-14…Q-16 decided by the Lead; Q-11, Q-12 stay open as technical work of the Lead (no operator question).

| Question | Decision / task |
|----------|-----------------|
| From which milestone should the repo be public? | Q-17 → D-014 |
| iPhone camera: 3 taps OK, or 2 taps a must? (after the device test) | Q-18 → B5 |
| How should the receipt pack look? (asked with the layout draft P0-5) | Q-19 → D-018 |

### Needs a human – PO (actions, issue `to:po`)

| Item | Why a human | Task / decision |
|------|-------------|-----------------|
| Email Revolut about the open-source module and name | Sends mail from a real account | P0-10 |
| Pick and register the domain **before P1-4** (never changes later, passkeys bind to it; stays out of the repo) | Owns/buys the domain | B1, D-010 |
| Install `v0.1.1` on the own server and report back (fixes the domain at the same time) | Owns the server | #13, M1 |
| Provide the test server that meets the D-010 minimum requirements (details stay out of the repo) | Owns the machine/contract | D-010 |
| Create a Revolut Business **sandbox** account and keep its keys private | Account sign-up | D-016, P3-4b |
| Run the device tests on a real iPhone and Android phone | Owns the phones | P0-22 (spikes B5, B14) |
| Supply a few anonymised real bank exports, if the synthetic generator isn't enough | Has real exports | P0-4, P0-21 |
| ~~Enable GitHub secret scanning + push protection~~ – done 2026-10-30 (issue #3). Note: the new repository is already **public** – the Lead records this against Q-17/D-014 | Repo admin rights | D-014, P0-8 |

Phase 1 go: given by the PO on 2026-10-29 (issue #8).

### What the Manager can take over (always together with / assigned by the Lead)

| Item | Task |
|------|------|
| Merge the Lead's accepted review points into PLAN.md (Lead decides content) | P0-1c |
| Receipt-pack layout draft (Lead signs off, D-018) | P0-5 |
| Rebase/housekeeping of Manager PRs | P0-14 |
| History & content scan before going public (Lead decides D-014) | P0-8 |
| Coding sub-tasks the Lead marks in the Phase 1 breakdown | P0-25 → P1-* |
| Critical reviews of all Lead PRs | WORKFLOW §10 |

## Phase 0 – Planning & setup (current)

| ID | Task | Owner | Status | Depends on | Notes |
|----|------|-------|--------|------------|-------|
| P0-1a | Review PLAN.md v1 (technical) → PLAN-REVIEW.md | Lead | done | – | PR #2, merged 2026-10-29 |
| P0-1b | Review PLAN.md v1 (product) → PLAN-REVIEW.md; mark the open PO column of PLAN-REVIEW B1–B21 | Lead | done | – | PR #5: all open B/A points marked by the Lead (AGENTS rule 6); B5 tap count → Q-18. Manager (paused) or Lead merges B3, B5, B7–B10, B12–B17 + A-points into PLAN in P0-1c part 2 |
| P0-1c | Merge accepted feedback into PLAN.md (v2) | Manager (for the Lead) | done | P0-1b | Part 1: old repo PR #7. Part 2 (2026-10-30, `manager/P0-1c-plan-v2-part2`): B3, B5 (technical), B7–B9, B12, B14, B15/A1, B17, B22 + issue #4 doc items for D-008/D-010/D-017. Left for the Lead (issue #4): tax advisor step-up (§11.1), D-024/Q-12 gaps; PHP minimum waits for the new D-002 |
| P0-2a | Technical decisions D-001…D-010, D-012, D-016, D-017, D-019 (STT engine) | Lead | done | – | PR #2. All blocking decisions decided; D-005, D-006, D-007 (files), D-019 engine stay proposed until their phase |
| P0-2b | Product decisions D-000, D-011, D-014, D-018 (receipt pack layout) | Lead | in progress | – | D-000, D-011, D-014 decided (`lead/P0-15-review-followups`); D-018 waits for the layout draft P0-5. D-015, D-019 decided by PO |
| P0-3 | Repo basics: README, LICENSE, AGENTS.md, workflow, templates | Manager | done | – | 2026-10-29 |
| P0-4 | Synthetic/anonymised fixtures: ~30 receipt photos (EXIF stripped), 1 anonymised Revolut export, 2–3 other bank exports | Lead + Manager | todo | P0-21 | Lead (with operator) supplies, Manager checks anonymisation |
| P0-5 | UI sketches: capture, matching, **receipt pack PDF layout**, list, detail, settings | Manager | in review | P0-1c | Drafts done 2026-10-30: `docs/design/screens.md` (screens, 4 open product questions) and `docs/design/receipt-pack.html` + `.md` (layout, Q-19 questions). Lead signs off and asks its operator Q-19 (D-018) |
| P0-6 | Roles set: Lead + Manager (both Claude) | PO | done | – | 2026-10-29 |
| P0-7 | Public-repo hardening: SECURITY.md, rules, `.gitignore` | Manager | done | – | 2026-10-29 |
| P0-8 | Full history & content scan before going public | Manager | done | before D-014 | 2026-10-30, after the repo was already public: gitleaks over all 31 commits/all refs clean; manual scan of authors, e-mails, IPs, hostnames, IBAN/phone patterns, binary files, issue/PR texts – nothing personal or secret (only composer.lock maintainer e-mails, RFC 5737 IPs, example.com). Repeat before v1.0 (P8-3) |
| P0-9 | Install required skills (docs/SKILLS.md block 1) on own computer | Lead | done | – | Manager + Lead: done 2026-10-29 |

### Phase 0 – added 2026-10-29

| ID | Task | Owner | Status | Depends on | Notes |
|----|------|-------|--------|------------|-------|
| P0-10 | Ask Revolut (business-partners@revolut.com) whether an open-source module for Revolut Business users and the descriptive name are fine | Lead | todo | – | Blocks P3-4b, not Phase 1. Record only the generic outcome here |
| P0-11 | Decide license policy D-022 | Lead | done | – | D-022 decided, PR #5 merged 2026-10-29 |
| P0-12 | Answer Q-11: `BankConnector` interface + module install mechanism; finalise D-010 yourself (PO leaves Q-8 to the Lead) | Lead | in progress | PR #1 merged | D-010 finalised in PR #2 (one minimum requirement, no Docker); `BankConnector`/module part open; also update D-019/D-020 to "bank modules + SMTP" (PR #5 review point 10) |
| P0-13 | Dependency license list `docs/DEPENDENCIES.md` + license column for add-on tools in the install docs (D-022: tools are installed by the admin, not shipped – no Ghostscript alternative needed) | Lead | todo | P0-11 | `docs/DEPENDENCIES.md` started in P1-1 (PR #9) |
| P0-14 | Rebase PR #1 after PR #2 is merged; fill Q-8 row | Lead | done | PR #2 merged | Done by the Lead on PO request: renumbered D-020→D-021, D-021→D-022, Q-9→Q-11, merged main; Q-8 row filled in PR #2 |
| P0-15 | Fix the must-fix points of the Manager's critical review on PR #2 (downloads for the offline worker, DNS-01, SQLite backups) and answer all points | Lead | done | – | PR #5 merged 2026-10-29: D-023 backup, D-016 as module, B12 criteria, D-025 PHP raise policy; all Manager review points answered |
| P0-16 | **Critical review of PR #1** (D-021, D-022, P3 split, collaboration rules) – technical feasibility | Lead | todo | – | WORKFLOW §10. PR #1 was merged by the PO before this review → review after merge, fixes as a follow-up PR |
| P0-17 | Architecture overview `docs/architecture/OVERVIEW.md`: components, queues (`local`/`net`), data flow, add-on detection, module mechanism, where each decision lives | Lead | todo | P0-15 | Manager reviews |
| P0-18 | Concrete data model: list of migrations/tables for P1–P4 incl. B7/B8/B16/B17 and generic transaction fields (D-021) | Lead | todo | PR #1 merged | Replaces PLAN §6 tables in P0-1c |
| P0-19 | Threat model `docs/security/THREAT-MODEL.md` (STRIDE) for pairing, upload, file serving, bank modules, receipt-pack links | Lead | todo | – | Manager reviews against PLAN §11 |
| P0-20 | CI & tooling spec: GitHub Actions jobs (lint, PHPStan 8 + Larastan, Pest incl. arch tests, MariaDB + MySQL matrix, gitleaks, license check, `local` jobs without network – D-003, D-020) | Lead | todo | P0-13 | Implemented in P1-1 |
| P0-21 | Synthetic fixture generator spec: fake receipts (images) and fake bank CSVs of several formats, no real data | Lead | todo | – | Reduces P0-4 for the PO |
| P0-22 | Device test plan for the spikes B5 (camera method) and B14 (pairing inside installed PWA): devices, steps, pass criteria | Lead | todo | – | PO runs it on real phones |
| P0-23 | Break P1-1…P1-8 into concrete sub-tasks with acceptance tests, order and rough size | Lead | todo | P0-15 | Manager reviews, then Phase 1 can start |
| P0-24 | Critical review of the Manager's PLAN v2 PR (P0-1c) and receipt-pack layout (P0-5) | Lead | in progress | P0-1c, P0-5 | Post-merge review of PR #7 done (findings in old issue #18 → issue #4); P0-5 layout review open |
| P0-25 | In P0-23: mark which Phase 1 sub-tasks the Manager codes **together with the Lead** (Lead assigns, Lead reviews) | Lead | todo | P0-23 | Manager never codes alone |

## Phase 1 – Foundation (v0.1) – started 2026-10-29 (PO go, issue #8)

| ID | Task | Owner | Status | Depends on | Notes |
|----|------|-------|--------|------------|-------|
| P1-1 | Project skeleton (Laravel, D-001), config, `.env.example`, `dochamster:doctor`/`setup` (no Docker, D-010), CI, gitleaks | Manager (PO override, issue #8) | done | PO go | Old repo PR #9; review findings in issue #4 |
| P1-2 | Migrations: identity & access tables | Lead | done | P1-1 | Old repo PR #19 |
| P1-3 | Layout, CSS base, i18n, error pages | Lead | done | P1-1 | Old repo PR #24 |
| P1-4 | Setup CLI, invitations, pairing (link/QR), passkeys | Lead | todo | P1-2 | Interim: owner password login + `dochamster:owner` (old repo PR #27) until passkeys |
| P1-5 | Device token rotation, device list, revoke, step-up | Lead | todo | P1-4 | |
| P1-6 | Org scope middleware, roles/policies, permission-matrix tests | Lead | done | P1-2 | Old repo PR #20 |
| P1-7 | Security headers, CSRF, rate limiting, health endpoint | Lead | done | P1-1 | Old repo PR #21; proxy/scheme finding in issue #6 |
| P1-8 | README install, HTTPS docs, backup docs v1 | Lead | todo | P1-1 | |
| P1-9 | Setup wizard in the browser, core (D-024): securing a fresh instance, server check, database setup, lock after finishing, resume; release package if decided | Lead | done | P1-1, P1-7, Q-12 | Old repo PR #22. Release package = web installer, PR #5 (Manager review there). Q-12 not yet recorded in DECISIONS.md (issue #6) |
| P1-10 | Interactive shell install: same steps in the terminal, asks for DB credentials (D-024, AK-4.9.7) | Lead | done | P1-1, Q-12 | Old repo PR #23; must-fix: ends without an owner account (issue #6) |
| P1-11 | Setup wizard: owner account, mail, cron check, connect the phone + home-screen app, first steps | Lead | todo | P1-4, P1-9 | Part done: owner step (old repo PR #28); mail, cron, phone open |
| MVP-CSV | Bank CSV import + manual match (MVP) | Lead (this PC) | in progress | P1-6 | Install/setup stays with the other PC (Q-14) |
| MVP-ZIP | ZIP export per period (MVP) | Lead (install session) | done | P1-6 | PR #24; transactions join the index once MVP-CSV is merged |
| MVP-PEOPLE | Owner adds employees and tax advisors, resets passwords, removes access (D-012) | Lead (install session) | done | P1-6 | PR #25, #27; tax advisors read-only on everything until shares (Q-20). Also: owner-triggered update, PR #26 |
| MVP-ROLES | Roles the Admin creates and names, checkboxes per role; Admin fixed, Mitarbeiter + Steuerberater pre-installed | – | todo | MVP-PEOPLE | Agreed spec in the issue "MVP-ROLES"; builder open |
| MVP-FRONTEND | CSS/JS framework and frontend layer | Operator + Lead (install session) | todo, reserved | – | Issue #31. Changes D-004. Not started |

Phases 2–8: see PLAN.md §14 (reordered in v1.1: 2 capture with time/location → 3 Revolut & matching → 4 receipt pack = core flow done → 5 OCR → 6 search → 7 tax advisor access → 8 release). The Manager adds rows when the previous phase is in review.
