# Session log (append-only)

Newest entry at the bottom. Format: see WORKFLOW.md §4. Never edit old entries.

---

## 2026-10-29 · Agent A (Claude Code) · P0-3
- Done: Initialised the repo. Moved the original plan to `docs/archive/plan-v0-belegbox.md`. Wrote the detailed plan `docs/PLAN.md` (v1 draft, German) with acceptance criteria, architecture, data model, routes, pipeline, matching score, security model, phases with work-package IDs. Set up collaboration files (AGENTS.md, CLAUDE.md, WORKFLOW, TASKS, DECISIONS, PLAN-REVIEW, this log), README, MIT LICENSE, PR template, `.gitignore`, `.editorconfig`. Listed recommended skills in `docs/SKILLS.md`.
- Not done / next step: Agent B reviews PLAN.md (task P0-1a) and writes feedback into PLAN-REVIEW.md. PO answers D-000/D-001/D-010/D-011 and Q-1…Q-4.
- Watch out: The v0 plan mentioned questions "F1–F4 in chat" that aren't in the repo; I reconstructed them as decisions in DECISIONS.md. No application code exists yet – Phase 0 is planning only.
- Branch/PR: initial commit on `main`.

## 2026-10-29 · Manager · P0-6, P0-7
- Done: Roles changed by the PO: both agents are Claude; the other session is the **Lead** (all technical/server decisions), this session is the **Manager** (plan, tasks, decisions log, reviews, coordination; no app code). "Agent A" in the entry above = Manager. Rewrote AGENTS.md, CLAUDE.md (role-neutral), WORKFLOW.md, TASKS.md, DECISIONS.md (owner per decision). Added public-repo rules (AGENTS.md), SECURITY.md, private paths in `.gitignore`, LEAD-KICKOFF.md. Replaced personal names in the repo with the role "PO". New decision D-015: document scanner as primary capture channel (recommendation recorded, Lead owns the network/ingestion side, Q-6).
- Not done / next step: PO starts the Lead session with LEAD-KICKOFF.md. Lead: P0-1a review + technical decisions incl. D-010/D-015. PO: D-000, D-011, D-014, Q-2, Q-4, Q-5. Manager: P0-1c once reviews are in; P0-8 history scan before going public.
- Watch out: Local commits so far were authored with a private email; switch to a GitHub noreply address before pushing (PO decision).
- Branch/PR: `main`, not pushed yet.

## 2026-10-29 · Manager · PLAN v1.1
- Done: PO decisions recorded: **no hardware scanner** – phone web app is the capture channel (D-015); **no AI in the app** (D-013); capture **time + location** (D-017), **Revolut** transaction details matched to photos (D-016), **receipt pack PDF** for the tax advisor (D-018). PLAN.md updated to v1.1 (core flow in §1, §4.1 capture metadata, §4.4 Revolut, §4.5/§10.1 receipt pack, data model, time-based matching score §9.2, privacy for location) and phases reordered: capture → Revolut & matching → receipt pack (= core flow done, v0.4) → OCR → search → tax advisor access → release. All AI parts removed.
- Not done / next step: **Lead:** D-016 (how to connect Revolut), D-017 (location handling, phone → server delivery), D-001, D-010, review of the reordered phases (PLAN-REVIEW A7/A8). **PO:** Q-7 (Revolut personal or business). **Manager:** receipt pack layout sketch (P0-5).
- Watch out: §4.4/§9.0 list Revolut options only as input – the Lead decides. Test photos must have EXIF stripped (no real GPS in the public repo).
- Branch/PR: `main`, not pushed.

## 2026-10-29 · Manager · Q-7
- Done: PO answered Q-7: target is Revolut **Business** → Business API is the expected main way (D-016, PLAN.md §4.4, P3-3). Open banking for personal accounts dropped from v1.
- Next: Lead decides the API details in D-016 (access setup, sync interval, available fields, sandbox).
- Branch/PR: `main`, not pushed.

## 2026-10-29 · Manager · D-019
- Done: PO clarified "no AI": the concern is privacy, not the features. Speech-to-text and similar features are wanted, **running locally on our server only**, nothing sent to cloud/AI providers. Recorded as D-019 (supersedes D-013). PLAN.md: principle 5, non-goals, §4.7 local STT with acceptance criteria, data model, pipeline, threat model (egress), P5-7 local STT, P8-0 local suggestions (C).
- Next: Lead picks the local STT engine (D-019) together with hosting (D-010).
- Branch/PR: `main`, not pushed.

## 2026-10-29 · Manager · P0-9
- Done: The PO confirmed the Lead runs on a **different computer**. Direct messaging between the sessions is not possible; the GitHub repo (pull/push, PRs) is the only channel, the PO relays urgent things. Fixed CLAUDE.md, WORKFLOW.md, LEAD-KICKOFF.md. Skills are now **required** for both agents (docs/SKILLS.md): php-pro, playwright-best-practices, pwa-development, handoff, code-review-and-quality; Laravel set once D-001 = Laravel. Installed block 1 on the Manager's computer.
- Next: **Lead** installs block 1 on its own computer (P0-9, step 1 of LEAD-KICKOFF.md). The repo must be pushed first, otherwise the Lead sees nothing.
- Branch/PR: `main`, not pushed.

## 2026-10-29 · Manager · D-021
- Done: PO wants a private Revolut part and CSV import for **every bank** in the public project. A private branch in a public repo isn't possible, so: public core = CSV/Excel import for every bank + `BankConnector` interface; Revolut Business connector in a **separate private repo** (D-021). PLAN.md: core flow, non-goals, §4.4 split table + AK-4.4.8/9, modules, §9, Phase 3 (P3-3 CSV for every bank first, P3-4 connector interface + private Revolut package), P8-3. README/AGENTS updated.
- Next: **Lead** answers Q-11 in D-021 (interface, how the private package is installed, private repo setup). Lead's D-016 stays valid, implementation moves to the private package.
- Watch out: branch `manager/D-021-bank-csv` was cut from `main` before the Lead's review PR was merged; merge the Lead's PR first, then this one (LOG.md may need a trivial rebase).
- Branch/PR: manager/D-021-bank-csv

## 2026-10-29 · Manager · D-021 (revised)
- Done: PO changed D-021: **no private repo**. Revolut Business is a built-in, optional module in the public repo, off by default, with an easy setup wizard so any Revolut Business user can connect their own account (AK-4.4.R5/R6). CSV import for every bank stays core. Updated PLAN.md, DECISIONS.md, README, AGENTS.md (Revolut credentials incl. sandbox never in the repo).
- **PO answer to Q-8 (Lead's question about hosting):** the PO leaves this to the Lead – the Lead decides D-010 (VPS vs. home server, resources, reachable from the mobile network or not) and records it. Manager fills the Q-8 row once the Lead's PR is merged.
- Next: Lead answers Q-11 in D-021 and decides D-010/Q-8.
- Branch/PR: manager/D-021-bank-csv (#1)

## 2026-10-29 · Manager · D-021 (module), D-022, review of PR #2
- Done: PO decided Revolut becomes a **separately installable module in its own public repo**; the core keeps CSV/Excel for every bank + `BankConnector` only (D-021 rewritten, PLAN §4.4 AK-4.4.R6/R7, P3-4a/P3-4b). Proposed license policy **D-022** (permissive for bundled code, no AGPL, no prebuilt images). New tasks P0-10…P0-14 for PO, Lead, Manager. Reviewed the Lead's PR #2: OK to merge (comment on the PR).
- Next: PO merges #2, then #1 (Manager rebases, P0-14); PO: P0-10 (ask Revolut), P0-11 (license policy); Lead: P0-12, P0-13; Manager: P0-1c, P0-5.
- Branch/PR: manager/D-021-bank-csv (#1)

## 2026-10-29 · Manager · collaboration rules, Lead tasks
- Done: PO asked for more Lead work, direct communication and critical mutual reviews. Added: GitHub issues with labels `to:lead` / `to:manager` / `to:po` as the communication channel (AGENTS rule 8, CLAUDE.md start routine, WORKFLOW §11); critical review rules in both directions (AGENTS rule 9, WORKFLOW §10, PR template review block). Lead tasks P0-15…P0-24; Phase 1 tasks assigned to the Lead (start after P0-23 + PO go). Posted a second, critical review on PR #2 with **Request changes** (must fix: install-time downloads for the offline worker, DNS-01 setup, SQLite backups; should fix: passkeys for the tax advisor, D-016 wording after D-021, Chromium size, PHP version rule).
- Next: Lead: issue "Lead: next tasks" → P0-15, P0-16 first. Manager: P0-1c, P0-5.
- Branch/PR: manager/D-021-bank-csv (#1)

## 2026-10-29 · Manager · Lead asks operator
- Done: PO rule: when questions come up, the **Lead always asks its operator** (the person running the Lead session) what to do and waits – no own defaults. Added to AGENTS.md rule 6, CLAUDE.md, LEAD-KICKOFF.md; noted in issue #3.
- Branch/PR: manager/D-021-bank-csv (#1)

## 2026-10-29 · Manager · all questions to the Lead
- Done: PO rules: **all questions go to the Lead** (not to the PO); the Manager **programs only together with the Lead** (sub-tasks the Lead assigns and reviews). Updated AGENTS.md roles + rule 6, WORKFLOW §1, DECISIONS owners (D-000, D-011, D-014, D-018, D-022, Q-2…Q-4 → Lead), TASKS (P0-1b, P0-2b, P0-4, P0-5 sign-off, P0-10, P0-11 → Lead; new P0-25). The PO still merges and can veto.
- Branch/PR: manager/D-021-bank-csv (#1)

## 2026-10-29 · Manager · work split
- Done: PO clarified the Manager role: **manage the work split only** (who does what, what the Manager can take over, where the Lead's operator must answer or act), program **together with the Lead**, all questions to the Lead. Added a "Work split" section at the top of TASKS.md with an explicit list of operator items (Revolut mail, domain, server, sandbox account, device tests, real bank exports, product choices, Phase 1 go). AGENTS.md and WORKFLOW §1 updated.
- Branch/PR: manager/D-021-bank-csv (#1)


## 2026-10-29 · Manager · handover
- Done: Added `docs/collaboration/MANAGER-KICKOFF.md` so a new Manager session can pick up. The PO will start it once the Lead has answered issue #3 and reviewed PR #1.
- Open: PR #1 waits for the Lead's critical review (P0-16); PR #2 waits for the Lead's fixes (P0-15); rebase #1 after #2 is merged (P0-14).
- Branch/PR: manager/D-021-bank-csv (#1)

## 2026-10-29 · Lead · P0-9, P0-1a, P0-2a
- Done: First Lead session, on its own computer. Installed skills block 1 (P0-9) – `npx skills` fails on Node 18, so the skill folders were copied manually after reading each SKILL.md (note in SKILLS.md). Technical review of PLAN.md v1.1 in PLAN-REVIEW.md (B1–B21), plus a second independent review pass whose findings are worked in. PO answered Q-8, Q-9, Q-10 during the session. Decisions **decided:** D-001 Laravel, D-002 PHP 8.4, D-003 MariaDB 10.11+ / MySQL 8.4+ (no SQLite), D-004 Blade + vanilla JS, D-007 field encryption, D-008 active org in session, D-009 SameSite=Lax, **D-010 one minimum requirement, no Docker, environment detection with optional add-ons (OCR, STT, HEIC)**, D-012 login by role (owner/member passkeys, tax advisor username + password set by the owner, no self-registration), D-016 Revolut Business API read-only + polling (sources linked), D-017 time/location + per-file upload + API CSRF, D-020 egress via two queues + arch tests. **Proposed:** D-005, D-006, D-007 file encryption, D-019 engine whisper.cpp. Answered Q-6.
- Not done / next step: **PO:** mark the remaining PLAN-REVIEW B-points. **Manager:** review PR #2; merge accepted points into PLAN.md in P0-1c – notably B3 (P3-1 → Phase 2 as P2-0), B11 (MariaDB/MySQL), B18 (login by role), B19 (MCC, `declined`/`failed`), B20 (upload routes §7), B21 (§12 without Docker, minimum requirements). WORKFLOW §5 ("Manager edits DECISIONS.md") and LEAD-KICKOFF step 4 ("Lead sets decisions") contradict each other – please settle. **Lead:** install skills block 2 (Laravel) at the start of P1-1 (deviation from LEAD-KICKOFF step 5: nothing Laravel-specific is written before P1-1).
- Watch out: Passkeys bind to the domain – the domain must be chosen before P1-4 and never change. Revolut card transactions contain cardholder name/phone – stripped before storage (B8). Revolut consent must use `scope=READ`. Without Docker the egress rule is enforced by code + CI, network-level only with an optional firewall on own servers (D-020).
- Branch/PR: `lead/P0-1a-review` (#2)

## 2026-10-29 · Lead · merge of PR #2 and #1
- Done: On PO request ("merge everything") PR #2 was squash-merged first. Then, on the Manager's branch of PR #1: renumbered the Manager's new entries to avoid clashes with #2 (**D-020 → D-021** Revolut module, **D-021 → D-022** license policy, **Q-9 → Q-11**), merged `main`, resolved LOG/TASKS conflicts (owners from #1, statuses from #2), added a Q-11 row.
- Not done / next step: **Lead:** follow-up PR for the Manager's critical review of #2 (P0-15: DB backup method, D-016 rewritten as the Revolut module behind `BankConnector` per D-021, B12 benchmark criteria, D-002 rule) and Q-11. D-022 still says "we publish the Dockerfile" – obsolete since D-010 (no Docker), Lead fixes it when deciding D-022 (P0-11).
- Watch out: PR #2 was merged while the Manager's review said "changes requested"; the open points are tracked in P0-15. D-020 now means egress control (Lead), D-021 the Revolut module (PO).
- Branch/PR: `manager/D-020-bank-csv` (#1)

## 2026-10-29 · Manager · P0-1c part 1 (PLAN v2)
- Done: Merged into PLAN.md only what is clearly approved: PLAN-REVIEW points with ✅ (B11 MariaDB/MySQL, B18 login by role, B19 MCC + `declined`/`failed`, B21 no Docker + minimum requirements) and points covered by decisions with status *decided*: B1 (fixed domain, D-010), B2 (two queues, D-020), B4/B6/B20 (EXIF before resize, clock skew, per-file upload + API CSRF, D-017), D-001/D-002/D-004/D-007 (fields)/D-009 in the §5.2 stack table. Touched §2.1, §3, §4.1, §4.4, §4.6, §5.1–§5.3, §6.1–§6.3, §7, §8, §10.1, §11.1–§11.2, §12 (rewritten), §13, §14 (P1-1, P1-8, P2-3, P5-5, P7-1), §15. README tech line updated.
- Not done (part 2): points without a decision yet – B3 (P3-1 → P2-0), B5 (camera method), B7–B9 (wait for D-016 rewritten as the module), B12–B16. Nothing in PLAN.md uses SQLite or Docker any more.
- Also: PO rule passed to the Lead – explain every Q/B/D item to the operator in detail incl. what the letters mean (issue #6, AGENTS.md rule 6 in PR #4).
- Reviewed the Lead's PR #5 (D-023 backup, D-022, D-016/D-002 follow-ups): **changes requested** – must fix: mPDF in B12 is GPL (conflicts with D-022), D-016 still claims LAN/VPN works (contradicts D-010), D-023 needs owner-only download links and safe restore (zip-slip, hashes, schema version); 9 should-fix points.
- Next: **Lead:** fixes on PR #5; critical review of this PR (P0-24), PR #4, issue #6. **Manager:** re-review PR #5; P0-1c part 2 incl. B22 (backup) after #5 is merged.
- Branch/PR: manager/P0-1c-plan-v2

## 2026-10-29 · Manager · P0-14 follow-up, WORKFLOW
- Done: New Manager session (MANAGER-KICKOFF.md). Found PR #2 merged and PR #1 already updated by the Lead (renumbering D-020→D-021, D-021→D-022, Q-9→Q-11, main merged) – my own parallel merge with a different numbering was dropped, the Lead's version stays. On top: WORKFLOW §1/§5/§6/§7 aligned with the current roles – the **Lead writes decisions directly into DECISIONS.md, the Manager keeps it tidy** without changing content (settles the §5 vs LEAD-KICKOFF contradiction the Lead raised) – plus a numbering rule against clashes (later-merged PR renumbers). TASKS: stale Docker wording removed from P0-17, P0-20, P1-1; work split: domain before P1-4, test server per D-010, open PO column of PLAN-REVIEW goes to the Lead (P0-1b).
- PR #1 was squash-merged by the PO at 12:54, before this commit was pushed (12:57) – so this work moved to its own follow-up PR. PR #1 went in without the Lead's critical review (P0-16): the Lead reviews it after the fact, fixes come as a follow-up PR.
- Next: **Lead:** review this follow-up PR (WORKFLOW changes), P0-16 post-merge review of #1, P0-15 rest, Q-11 (P0-12, now unblocked). **Manager:** P0-1c with the ✅ B-points (B11, B18, B19, B21); P0-5.
- Watch out: PLAN.md still says SQLite/Docker in places – that is P0-1c, not this PR. Nobody else should push to a Manager branch while a Manager session runs on it; the Lead did on PO request, fine this time.
- Branch/PR: manager/P0-14-workflow-followup

## 2026-10-29 · Manager · merge-first rule, Lead's review of #4
- Done: PO rule: **merge first, review after** – authors merge their own PRs (squash) as soon as CI is green, never wait for a review; the other agent reviews merged PRs when it has time, must-fix findings become an issue or follow-up PR (AGENTS "Who is who", WORKFLOW §1/§10, PR template). Worked in the Lead's review of this PR: "Docker" removed from WORKFLOW §1, §6 wording = AGENTS rule 6, §7 "not edited once merged to `main`", numbering check again right before merging, P0-13 reworded after D-022.
- Next: merge #4, #7, #9 (CI green); Lead reviews them after the merge; Lead may merge its own PRs (#5) the same way.
- Branch/PR: manager/P0-14-workflow-followup (#4)

## 2026-10-29 · Manager · P1-1 project skeleton (PO override)
- Done: PO gave the **Phase 1 go** and overrode two rules for P1-1: Manager builds it (normally Lead) – issue #8 tells the Lead not to start P1-1. Laravel 13.39 on PHP 8.4 (`config.platform.php` 8.4.1, D-002), **no Vite/npm** (D-004), MariaDB/MySQL only, no SQLite/PostgreSQL connections (D-003), queues `local`/`net` (`App\Queue\QueueName`, D-020), cron-driven worker + heartbeat in `routes/console.php` (D-010), `.env.example` with placeholders and production-safe defaults. **`dochamster:doctor`** checks the D-010 minimum requirements (PHP, extensions, limits, DB version per server type, HTTPS, cron, proc_open) and the add-ons OCR/STT/HEIC/vips; **`dochamster:setup`** = doctor → app key → migrate. German/English texts in `lang/`. Default `users` migration and model **left out** (identity tables = P1-2, migrations are never edited after merge); `sessions` got its own migration with a ULID `user_id`. Tooling: Pint (strict types), PHPStan 8 + Larastan, Pest with unit/feature/arch tests (arch test enforces the D-020 egress rule – verified that it catches a violation), gitleaks config + pre-commit hook, license allowlist script (D-022) + `docs/DEPENDENCIES.md`, GitHub Actions (quality, MariaDB 10.11 + MySQL 8.4 matrix incl. migrate up/down/up and doctor against the real DB, gitleaks), Dependabot.
- Local checks: 31 tests green, Pint clean, PHPStan 0 errors, licenses 77/77 permissive, gitleaks: history clean. Not run locally: anything with a real database (no DB on this machine) – CI covers it.
- Found and fixed: the array form of `Schedule::command()` rendered `--stop-when-empty=1`, which `queue:work` rejects – the cron worker would have failed every minute; regression test added.
- Also: PO rules passed to the Lead (issue #6, PR #4): its operator gets only questions; human actions go to the PO (`to:po`); the Lead codes once the plan is ready.
- Next: **Lead:** critical review of the P1-1 PR; then P1-2 (identity tables incl. `users`, D-012). **Manager:** fixes from the review.
- Watch out: this machine got PHP 8.4 (winget), Composer (official phar, checksum verified) and gitleaks on PO approval – tool setup, nothing in the repo.
- Branch/PR: manager/P1-1-skeleton
- Merged right after CI was green on MariaDB + MySQL (merge-first rule); Lead reviews after the merge.

## 2026-10-29 · Manager · D-024 setup wizard (PO requirement)
- Done: PO requirement recorded as **D-024**: users have little technical knowledge → the main install path is a **setup wizard in the browser** that explains every step in plain German (server check, database incl. asking for DB credentials and creating the tables, owner account, mail, cron, **connecting the phone** + home-screen app, first steps); a shell install stays as alternative. PLAN §4.9 (flow + AK-4.9.1–8), §12 install line, §14/TASKS new packages **P1-9** (wizard core), **P1-10** (interactive shell install), **P1-11** (wizard: owner, mail, cron, phone – after P1-4). Technical design → **Q-12** for the Lead (8 points: release package without Composer, securing a fresh instance, where config is written, DB setup, cron, owner + phone, HTTPS, shell scope).
- Next: **Lead:** answer Q-12 with its operator (explain each point in detail); D-010 install path needs updating once decided. **Manager:** can take P1-10 if the Lead assigns it.
- Branch/PR: manager/D-024-setup-wizard

## 2026-10-29 · Manager · handover: all coding to the Lead
- PO: the Manager session has no usage left – **the Lead does all programming from now on** (P1-2 … P1-11 incl. setup wizard P1-9/P1-11 and shell install P1-10). Manager work pauses.
- Open for the Lead: Q-12 (issue #14), Q-11, PR #5 (my review points), post-merge reviews of #7, #9, #13 (issue #10), Dependabot PRs #11/#12 (gitleaks-action v3 may need CI changes).
- State: `main` has the P1-1 skeleton (CI green on MariaDB + MySQL). Local checks: `composer lint`, `composer analyse`, `composer test`, `composer licenses`.
- Branch/PR: manager/handover-coding-to-lead

## 2026-10-29 · Lead · P0-15, P0-11
- Done: Follow-ups from the Manager's critical review of PR #2. **D-023 backup** (PO requirement): "Backup herunterladen" for the owner – alles or von/bis, size estimate, direct streamed download up to 500 MB, background job above or when password-protected, no secrets in the ZIP, restore of full backups via CLI; plus `dochamster:backup` (consistent DB dump + storage) for operators. **D-016** reworded as the Revolut module behind `BankConnector` (D-021). **D-002** now a rule (follow Debian stable, min. 8.4). **B12** with pass criteria (150 pages ≤ 60 s, ≤ 256 MB), no headless Chromium. **D-022 decided:** permissive licenses for everything we ship; add-on tools (incl. Ghostscript/AGPL) are installed by the admin, not shipped – no Ghostscript alternative needed. New B22.
- Not done / next step: **Lead:** Q-11 (`BankConnector` interface, module install) in its own PR. **Manager:** review this PR; P0-1c should include B22 (backup ACs in §4.8, §12).
- Watch out: Downloads for the offline worker and DNS-01 from the Manager's review were already settled in PR #2 (D-010: data files via setup command; public HTTPS required, no LAN-only setups).
- Branch/PR: `lead/P0-15-review-followups`

## 2026-10-29 · Lead · PR #5 review fixes, new operator rule, open questions re-checked
- Done: Merged `main` twice into PR #5 (Manager's PLAN v2, P1-1 skeleton, D-024 setup wizard, handover). Worked in all points of the Manager's review of PR #5: mPDF out of B12 (GPL); D-016 without LAN/VPN, plus OAuth `state` check and encrypted code; D-023 with owner-only download (no bearer link, no cross-org export for the instance admin), scope checkboxes for coordinates/trash/audit log – all ticked by default (**operator answer, Q-13**), GPS stripped from exported file copies when unticked, sanitised folder names + fallback without Belegnummer, one backup per org + disk check, cleanup not only via cron, password ≥ 12 chars and never in the queue in clear, neutral names inside a protected ZIP, 7-Zip hint, restore treats the ZIP as untrusted (empty instance, paths, hashes, content validation, limits, schema version), safer `dochamster:backup` (credentials file, umask, excluded folders), tax advisor passwords reset after restore. D-002 kept as on `main` + clarification; raise policy is new **D-025** (from 1.0 only in a major release).
- **New operator rule (AGENTS rule 6, CLAUDE.md, LEAD-KICKOFF, WORKFLOW, TASKS):** the Lead decides technical questions itself; the operator gets only product-design and hard-to-change questions, short, each with a Q-number. Re-checked every open item under it: decided by the Lead – D-000 (DocHamster), D-011 (several orgs with owner/member/tax advisor in v1, as PLAN already requires), D-025, Q-2 (PLAN §9.1 list, verified on real anonymised samples), Q-3, Q-4, Q-14…Q-16, WORKFLOW §7 (clarifications with a "Changed" line allowed), all B/A points of PLAN-REVIEW (P0-1b). Left for the operator: **Q-17** (from which milestone public, D-014), **Q-18** (iPhone 3 taps OK? after the device test), **Q-19** (receipt pack layout, with P0-5). Q-11 and Q-12 stay technical work of the Lead.
- Checked: 4 independent review agents (consistency, security, review coverage, operator filter) – all findings worked in.
- Numbering: `main` got Q-12/D-024 in the meantime → my entries renumbered to Q-13…Q-16 and D-025.
- Next: **Lead:** merge PR #5; post-merge reviews of #7, #9, #13 (issue #10; found already: 424 `.phpstan-cache` files committed on `main` despite `.gitignore`); Q-12 design PR; P1-2. **PO:** enable GitHub secret scanning + push protection (TASKS).
- Branch/PR: `lead/P0-15-review-followups` (#5)

## 2026-10-29 · Lead · PR #5 merged, post-merge reviews of #7, #9, #13
- Done: PR #5 squash-merged after green CI (merge-first rule). Post-merge reviews of #7, #9, #13 (issue #10, closed): 42 findings from independent review passes, each adversarially verified, **34 confirmed**. Urgent part fixed in this PR: `.phpstan-cache/` and `bootstrap/cache/*.php` untracked, gitleaks allowlist no longer skips them or `storage/`, pre-commit hook executable, `composer setup` no longer rotates `APP_KEY`, queue failed/batches fallback `mariadb`. The rest is listed in issue #18 (doctor reads CLI ini, setup key check, D-020 arch-test gaps + offline CI step, secure config fallbacks, hashed session IPs, PLAN/D-024 gaps for Q-12).
- **Watch out – leak:** the committed PHPStan cache held an environment dump incl. a Claude Code session token of the Manager computer and personal paths; several web-UI squash merges carry a private e-mail as author. Repo is private. PO actions in issue #17 (invalidate token, GitHub e-mail privacy, secret scanning + push protection, history rewrite before going public).
- Closed: issues #6 (superseded by the new rule 6), #8 (P1-1 merged), #10 (reviews done).
- Next: **Lead:** Q-12 design PR (setup wizard, incl. #13 findings), P1-2 identity tables, fixes from #18. **PO:** issue #17.
- Branch/PR: `lead/P1-1b-repo-hygiene` (#16)

## 2026-10-30 · Manager · History rewrite for the leak (#17)
- Done (PO go): rewrote the history of all 21 branches with `git filter-repo` and force-pushed. Removed `.phpstan-cache/`, `.phpunit.cache/` and `bootstrap/cache/*.php` from every commit; private author e-mail → noreply, real-name author → login. `main`'s tree is unchanged. Verified: 0 hits for the token, personal name, private address or profile paths. The cache files came from my commits in #7/#9 – from now on I check the staged file list before every commit.
- **Watch out – all hashes changed:** the Lead must re-clone or hard-reset before pushing again (issue #29). Pushing an old branch brings the leak back.
- Still open (PO, issue #17): restart the Claude app on the Manager computer (token); GitHub e-mail privacy; secret scanning + push protection; **old commits are still reachable on GitHub via `refs/pull/*`** → GitHub Support purge or a fresh repo before going public.
- Branch/PR: `manager/P0-8-history-rewrite-log`

## 2026-10-30 · Manager · reviews, board update
- Done: First session in the new repository (fresh, **public**, created 2026-10-30; numbers restart at #1). **Critical review of the Lead's PR #5** (web installer + release workflow), posted on the PR: must fix – setup code downloadable over HTTP when the app is unpacked inside the web root, existing `index.php`/`.htaccess` overwritten without backup, a failed unpack can't be retried; should fix – SHA-256 from the same release isn't tamper protection (sign the manifest), expiry by `filemtime` breaks with WinSCP, no HTTPS check/cookie flags, ZIP in memory, release chosen twice + GitHub API limit, copied `public/` goes stale, Plesk-only wording; no tests. **Post-merge review** of P1-2, P1-6, P1-7, P1-9, P1-10, owner login and owner wizard step → **issue #6**: must fix – shell install ends "eingerichtet" without an owner while the wizard is locked; no trusted proxies (APP_URL becomes `http://`, HSTS missing, API uploads 403 behind a TLS proxy); plus 5 should-fix points and Q-12 still "Open" in DECISIONS.md. TASKS.md: P1-2, P1-3, P1-6, P1-7, P1-9, P1-10 → done, notes column for Phase 1, note that old issue/PR numbers refer to the previous repository.
- Not reviewed: P1-3 layout/CSS, the PHP 8.3 change, Dependabot PRs #1/#2.
- Next: **Lead:** fix PR #5 points 1–3 before the first `v*` tag (the release job publishes `install.php`); issue #6; issue #4; record Q-12; record that the repo is public (Q-17/D-014). **PO:** nothing new.
- Branch/PR: manager/board-update-2026-10-30

## 2026-10-30 · Manager · P0-8 scan, P0-1c part 2, more reviews
- Done: **Issue #8** – PR #5 findings as a checklist (merged before my review; `v0.1.0` with that `install.php` is already published, asked the Lead to mark it pre-release until fixes 1–3 ship). **P0-8 history & content scan** of the public repo: gitleaks over all refs clean; manual scan of authors, e-mails, IPs, hostnames, IBAN/phone patterns, binaries, issue/PR texts – nothing personal or secret. **Reviews:** Dependabot #1 (checkout v7) and #2 (gitleaks-action v3) OK to merge (comments on the PRs; `release.yml` still on checkout v5); PHP 8.3 change and P1-3 → addendum on issue #6 (D-002/D-025 and PLAN still say 8.4 – Lead to record; CI only on 8.3; warning colour fails WCAG AA). **P0-1c part 2** – PLAN.md: B3 (queue → P2-0, dependencies of P2-5/P2-7/P3-4a/P5-1/P5-2/P5-7 moved), B5 technical part, B7–B9 in §9.0/§6.3/§9.2/AK-4.4.R4/P3-4b, B12 in §5.2/P4-2, B14 in §11.1/P1-4, B15/A1 in AK-4.1.3/P2-4/§15, B17 in §6.2, B22 as §4.8 AK-4.8.1–7 + §12 + P4-5/P8-5; from issue #4: §5.2 image row (GD + add-ons), §7 without `/o/{org}`, AK-4.1.5 ULID.
- Not done: PHP minimum in PLAN (waits for the Lead's new D-002); tax advisor step-up and the D-024 gaps from issue #4 (Lead decisions). P0-5 (UI sketches incl. receipt-pack layout) is my next open task.
- Next: **Lead:** issues #8, #6, #4; merge Dependabot #1/#2 or let me know who should. **Manager:** P0-5.
- Branch/PR: manager/P0-1c-plan-v2-part2

## 2026-10-30 · Manager · PO goal: install on the PO's server
- PO asked to stop code reviews and to get DocHamster installed on the PO's server as soon as possible, together with the Lead. State: `v0.1.1` (with the #8/#6 fixes) installs end to end – installer → wizard → owner → password login – but after login only "im Aufbau" is shown.
- Done: **issue #12** (`to:lead`) – milestones M1 "Installation läuft" (PO installs v0.1.1, cron line in the wizard, install guide, fixes → v0.1.2), M2 "Belege fotografieren" (P2-0/1/2/3/4/7 + minimal list; Lead decides: interim password login vs. passkeys first), M3 core flow; asks the Lead which pieces the Manager takes; notes that `TRUSTED_PROXIES` read via `env()` in `withMiddleware()` may miss `.env` values (default localhost proxy is fine for M1). **Issue #13** (`to:po`) – server checklist (domain + HTTPS, PHP 8.3 + extensions + limits, empty DB, upload `install.php`, wizard, cron, what to report and what never to post). TASKS.md: "Current goal" section on top, PO row, P0-5 paused with the screen wireframes drafted in `docs/design/screens.md` (receipt-pack layout not started).
- Next: **PO:** #13. **Lead:** answer #12 (milestones, login decision, Manager assignments). **Manager:** install guide / M2 sub-tasks once assigned; otherwise P0-5 receipt-pack layout.
- Branch/PR: manager/install-goal-board

## 2026-10-30 · Manager · P0-5 receipt-pack layout
- Done while waiting for the Lead's answer on #12: receipt-pack layout draft `docs/design/receipt-pack.html` (cover with key figures, overview, one page per transaction with large photo, missing receipts; Dompdf-friendly CSS, synthetic data) + `docs/design/receipt-pack.md` (choices and 4 questions for Q-19). P0-5 → in review.
- Next: **Lead:** #12 first (install goal), then sign off P0-5 and ask Q-19. **PO:** #13.
- Branch/PR: manager/P0-5-receipt-pack

## 2026-10-31 · Manager · MVP-ROLES
- Done: Operator asked the Manager to code; took the next free task on roadmap #35 and said so in #30. `roles` table + `memberships.org_role_id`, checkboxes (`RoleRight`) replace the fixed matrix (`Role::access`, `Access`, the two org settings). Admin = `memberships.role = owner`, several allowed. New: role screen, change a person's role, permission `tags.manage` for MVP-TAGS. Existing organisations keep today's rights in the migration; only new ones get the wider defaults of #30.
- Decided while building, for the Lead to veto: "Belege löschen" means the trash only, deleting forever stays with Admins. Editing and deleting are limited to what the person can see. Nobody can change or remove their own membership, so one Admin always remains.
- Fixed on the way: the export ignored who may see what. With checkboxes that would have let "Exportieren" without "Alle Belege sehen" download everyone's receipts.
- Not done: PLAN §2.1 (docs pause, #39). Screens only rendered in tests, not looked at in a browser.
- Next: **Lead:** post-merge review; release when it suits. **Manager:** MVP-TAGS is next on the roadmap.
- Branch/PR: manager/MVP-ROLES-custom-roles

## 2026-10-31 · Manager · MVP-TAGS
- Done: Tags per #32: `tags` + `document_tags` (PLAN §6.2), pre-installed tags for new and existing organisations, tag screen, chips at upload and per receipt, AND filter in the list, `Tags` column in `index.csv`, own group in `install.php`. Also relaxed the QR library pin (`^3.0`, in #40), which had `main` failing `composer validate --strict` since the device probe.
- Decided while building, for the Lead to veto: pre-installed tags aren't protected, #32 only says they exist, so they can be renamed and deleted. Ticking only "Tags" in a fresh install leaves the list empty until someone adds tags.
- Not done: screens only rendered in tests, not looked at in a browser or on a phone. PLAN (categories, docs pause).
- Next: **Lead:** post-merge review. Stage 1 of #35 is then done apart from your MVP-CSV and PHONE.
- Branch/PR: manager/MVP-TAGS-tags

## 2026-11-03 · PO (Claude chat) · UI-STYLE
- Done: Went through the old styleguide with the PO and collected twelve points. New styleguide with every app page, new stylesheet and new Tabler icons, all next to the running app (`public/design/`), plus the hand-over `docs/design/ui-redesign.md`. `public/css/app.css` and the views are untouched, so the app looks as before.
- Not done / next step: **UI-APPLY** – put the new look into the Blade views and JS and replace `public/css/app.css` with `public/design/app.css` in the same PR (issue `to:lead`).
- Watch out: the new CSS needs the new markup (left column with dark block + menu), CSS alone would break the layout. The hamster is out of the UI for now; keep `public/js/hamster.js`.
- Branch/PR: po/UI-STYLE-new-look

## 2026-11-03 · PO (Claude chat) · UI-STYLE-2
- Done: Design round 2 with the PO (points 13 to 23): tags as pills with colours, documents never cropped (frame with padding), icons instead of thumbnails in Details/Liste/Kleine Symbole, receipt view next to the list (dialog on narrow windows), filter with "Anwenden", Tags page with search and editor, Einstellungen as a column menu with folding strips. New styles are added at the end of `public/css/app.css` (existing rules unchanged). The styleguide is back in `public/design/` with working samples; ten Tabler icons added. Hand-over: `docs/design/ui-redesign.md` (Round 2).
- Not done / next step: **UI-APPLY-2** for the Lead (views, JS, thumbnails stored at upload, data model changes with label `plan`). Open product question for the PO: what "Versenden" does.
- Watch out: thumbnails must never be cropped; the "#" of a tag comes from CSS.
- Branch/PR: po/design

## 2026-11-03 · PO (Claude chat) · UI-STYLE-2 (logo)
- Done: New logo (point 24): `public/img/logo.png` (for dark surfaces) and `public/img/logo-on-light.png`. Logo height in the dark block 3rem, image attributes 476 x 186 in the layout and the styleguide.
- Branch/PR: po/design


## 2026-11-04 · Manager · clone reset, post-merge reviews, board
- Done: Reset the clone to `origin/main` as asked in #45 and #49 (no local branch kept the removed PR #44 commit, nothing pushed from the old clone) and closed both. **Post-merge review** of #42, #50, #51, #54, #55 → **issue #56**. Must fix: a receipt titled with `/` or `\` can't be downloaded or shared (500, verified); the share-link list shows and revokes every link in the organisation for anyone with "Exportieren", even without "Alle Belege sehen". Should fix: link lifetime settable by any exporter, opening a link isn't audited (PLAN §8/§11), share download without file extension, shared notes show titles of receipts the reader may not see, GD thumbnails in the upload request without a pixel check (48 MP ≈ 194 MB measured vs. 256M), Ghostscript on uploaded PDFs, bank amounts with the currency before the sign (`EUR -12,30` → +12,30, verified). #51 clean. Board: MVP-TAGCOLORS → done (built in #54), new row GO-1104 for #55.
- Not done: JS, CSS and views not looked at in a browser; no real update run.
- Next: **Lead:** the two must-fix points of #56; close #43, #48, #53 or say what is left. **Manager:** nothing assigned; PHONE still waits for the PO's device test.
- Branch/PR: manager/board-review-2026-11-04

## 2026-11-04 · Manager · fixes from review #56, old review lists checked
- Done: The operator asked me to take every free task. On the board, nothing else is free: the rest is owned, reserved (MVP-FRONTEND, MVP-TIMELINE) or behind PHONE (INBOX → HAMSTER → PRINT/MAIL). So I took the #56 findings (claimed in #56). **#58** (REVIEW-56a): one `DocumentFile::downloadName()` for downloads and share links ("/" no longer gives a 500, file type kept), share list and revoke by `visibleTo`, link lifetime Admin-only, `document.share_opened` in the audit log, shared notes show only visible receipts. **#59** (REVIEW-56b): thumbnails skip > 40 MP during uploads (the command makes them later) and follow EXIF orientation; bank amounts with a minus behind the currency, rounding of more than two decimals, "0,005" not read as thousands. Both merged locally (rebase + fast-forward) after green CI. Also checked the old review lists #4, #6, #8 against today's code and commented what is fixed, so the Lead can tick or close them.
- Decided while building, for the Lead to veto: link lifetime only for Admins; notes don't name receipts the reader may not see.
- Not done: point 8 of #56 (Ghostscript, Lead decides), Revolut `State` rows (product question). Pages not looked at in a browser. Locally 5 setup/update tests fail on SQLite on unchanged `main` too (Windows environment); CI is green.
- Next: **Lead:** post-merge review of #58/#59; point 8; tick/close #4, #6, #8, #43, #48, #53. **Manager:** nothing free left on the board.
- Branch/PR: manager/REVIEW-56a-share-links (#58), manager/REVIEW-56b-thumbs-amounts (#59), manager/board-2026-11-04b

## 2026-11-04 · Manager · HAMSTER-FIG
- Done: On the PO's request (PO go in the Claude chat) the hamster as a real 3D figure (three.js r160, vendored) drawn as pixel art like the PO's reference sheet: toon shader with two tones, one-pixel outline, orthographic camera, coarse pixel grid. Animations as key poses at 8 fps (no smooth motion): idle, walk, stand up, read, eat and tear a document, wave, lie flat, sleep, held, fall, land. Free-moving mascot: wanders, turns in 45° steps, can be grabbed with the mouse (long press on touch), dragged, thrown, falls and lands; eating a document is an animation only. All in `public/js/hamster-mascot.js` (`docHamster3d.createRenderer` / `mountMascot`), demo page `public/design/hamster.html`.
- Not done (Lead, as agreed with the PO): where the mascot appears (logo, pages, desktop app HAMSTER), what eating a document really does, replacing `public/js/hamster.js`. Not looked at on a real phone.
- Watch out: needs WebGL; under "reduce motion" it stays put. The vendored three.min.js prints a deprecation warning in the console (unmodified upstream build).
- Branch/PR: manager/HAMSTER-FIG-mascot

## 2026-11-04 · Manager · HAMSTER-FIG (pixel pass)
- Done (PO go): every frame now goes through a pixel pass – one outline around the whole figure, inner lines only where a part lies in front of another (head on body, legs, paws, ears), no lines where parts melt into each other; eyes, mouth, cheeks and the "z" are pixel stamps taken from the lying sprite in `public/js/hamster.js` (the PO's reference image is that sprite). Lying/sleeping fitted to the sprite by overlay at the same pixel scale (head turned to the viewer, body mound behind, flat underside, hind foot flat behind); breathing, blinking and the "z" path use the sprite's timing. Fur `#ff7622` (`--primary`) with shadow `#d4500f`, all other colours from the sprite.
- Not done: still not looked at on a real phone. Integration stays with the Lead.
- Branch/PR: manager/HAMSTER-FIG-mascot

## 2026-11-04 · Manager · HAMSTER-FIG (back to 3D, start 2D)
- Done (PO go): `public/js/hamster-mascot.js` back to the version before the pixel pass (PO: that one was better). PO decision: the whole hamster becomes 2D pixel art; the 3D model stays only as a measure for sizes and proportions. Base poses traced pixel by pixel from the PO's two reference images (lying = the sprite in `public/js/hamster.js`); shown to the PO for sign-off before any animation is built.
- Next: after the PO's OK, layered 2D animations like `public/js/hamster.js`; PR only once the PO is happy (PO, F5).
- Branch/PR: manager/HAMSTER-FIG-mascot

## 2026-11-04 · Manager · HAMSTER-FIG (2D)
- Done (PO go): the hamster is now 2D pixel art. Base poses traced pixel by pixel from the PO's reference sheet on one common pixel grid (so proportions match between poses), edges cleaned (closed one-pixel outline, no stray pixels); lying = the `public/js/hamster.js` sprite. `public/js/hamster-sprites.js` holds the pixel data, `public/js/hamster-mascot.js` (`docHamster2d`) builds the animations like hamster.js (pose swaps, whole-pixel moves, small overlays) and keeps the mascot behaviour (wander, drag, throw, fall, land, eat). Lying/sleeping use hamster.js breathing, blinking and "z" timing exactly. three.js removed again (no longer needed).
- Not done: no real phone test; PR waits until the PO is happy (F5).
- Branch/PR: manager/HAMSTER-FIG-mascot

## 2026-11-04 · Manager · HAMSTER-FIG (animation polish)
- Done (PO: "you set the tone"): went through every frame as an animator and fixed what would not pass a client review. Arms are now drawn parts that reach, hold and follow the document (read: pick up / put away; eat: up to the mouth, paws follow the shrinking sheet, back to the chest; tear: wind-up, rip with each paw on its half, throw with the halves, they fly out and fall, dust off). Paper scraps fly out beside the body instead of looking like dirt on the belly. Walk is a real four-frame cycle. Stand-up/kneel/lie-down/get-up keep the same open eyes and go through in-between poses (crouch, rear up, sit). Held: stretched, arms hang, legs kick; fall: arms up. Front-only animations turn to the front first in 45° steps. Automatic check over every frame of every animation and view: 0 open outlines, 0 stray pixels (fixed on the way: a hole in the head outline, cuts after squashing, two open pixels at an ear tip in the hamster.js sprite).
- Not done: no real phone test.
- PO: OK, finish it in the repo – PR opened and merged after green CI (one squashed commit on current main).
- Next: **Lead:** post-merge review; where the mascot goes in the app (logo, pages, desktop app HAMSTER), what eating a document really does, whether it replaces `public/js/hamster.js`.
- Branch/PR: manager/HAMSTER-FIG-pixel-mascot (squashed; the work-in-progress branch manager/HAMSTER-FIG-mascot can be deleted)

## 2026-11-06 · Manager · SCAN (proposal)
- Done (PO go in the Claude chat): tested a real home inkjet printer on the LAN – a scan through the open eSCL interface works; the display accepts no custom destination (tried twice via the open Windows scan protocol; vendor-app role not used on purpose). Wrote `docs/proposals/printer-scan-station.md` (separate MIT module, scan one/many pages as one document, folders, scan button in the phone web app, QR/NFC sticker, IPP printing, receiver for printers that push). Opened issue #77 (`to:lead`), board row SCAN, Q-30, note on D-015.
- Not done: no code. Needs the Lead's decisions (D-015 amendment, folders in core, interface shape, Scan Station pairing, roadmap order).
- Watch out: no real data in the repo (no IPs, serials, MACs). Do not use vendor private interfaces. Mopria eSCL spec licence terms still to be read before the first release.
- Branch/PR: manager/SCAN-printer-scanner-module

## 2026-11-07 · Lead · PREVIEW-DEPLOY
- Done: Added the updater flow for open, non-draft PRs targeting `main` with the `preview-ready` label. The update page lists eligible branches, warns and requires confirmation before installing one, and offers a direct return to the recommended stable Main release. Preview packages are checksum-verified; both the app and installer recheck the PR label and exact commit before swapping files. Added a package workflow with read-only build and separate release publisher jobs; preview releases are removed when the label is removed or the PR closes. Added the label safety criterion to the PR template and cached branch discovery for five minutes.
- Not done / next step: Automated tests and local quality checks have not been run. Composer is unavailable in this environment; GitHub CI must pass before merge. The feature becomes available only after this PR is merged and the `preview.yml` workflow is active.
- Watch out: The publisher and cleanup jobs need `contents:write`, approved by the PO for this workflow. The preview installer runs the selected PR's database migrations, so the `preview-ready` checklist requires that existing data remain safe and switching back to Main work.
- Branch/PR: lead/PREVIEW-DEPLOY-branch-selection (#85)

## 2026-11-07 · Lead · PREVIEW-DEPLOY (release)
- Done: All CI jobs passed after a Pint formatting fix. Locally fast-forwarded PR #85 into `main`; pushed tag `v0.1.18`. The release workflow completed and published the stable ZIP, installer, and manifest. PR #85 is merged.
- Not done / next step: The app owner can now use “Nach Updates suchen” and install v0.1.18. The `preview.yml` package job is active for newly labelled eligible PRs.
- Watch out: None.
- Branch/PR: `main`, tag `v0.1.18`; PR #85

## 2026-11-07 · Lead · PREVIEW-PUBLISHER
- Done: Fixed the preview release publisher and cleanup commands to select the repository explicitly, since these jobs do not check out the repository.
- Not done / next step: Verify CI, merge the fix and re-trigger preview builds for the open test PRs.
- Watch out: Preview releases are still created only for same-repository open PRs labelled `preview-ready`.
- Branch/PR: lead/PREVIEW-PUBLISHER-repo-context

## 2026-11-07 · Lead · BUG-DROP-1
- Done: Prepared the requested drag-and-drop safeguards for preview. Document preview images cannot start native image drags, and a marked drop from an in-app preview is ignored while external file drops remain available.
- Not done / next step: Browser/device reproduction and CI are pending before the preview can be offered.
- Watch out: This change does not add file-content deduplication.
- Branch/PR: lead/BUG-DROP-1-preview

## 2026-11-07 · Lead · BUG-DROP-1 (preview ready)
- Done: PR #86 passed lint, static analysis, license checks, secret scan, and tests on MariaDB and MySQL. The preview release package is published after the publisher workflow fix.
- Not done / next step: The PO still needs to reproduce the original light upward drag on the server and confirm no copy is created.
- Watch out: None.
- Branch/PR: lead/BUG-DROP-1-preview (#86)

## 2026-11-07 · Lead · BUG-DROP-1 (PO verification)
- Done: The PO tested the PR #86 preview on the server, confirmed the duplicate is gone, and approved merging it into `main`.
- Not done / next step: Merge after the updated PR's CI passes, then publish the stable update.
- Watch out: Keep PR #87 open for the separate zoom preview.
- Branch/PR: lead/BUG-DROP-1-preview (#86)

## 2026-11-06 · Manager · SCAN (folders in the core)
- Done (PO order: "just do it, I make the decisions"): D-026 recorded. Folders in the core: table `folders` + `documents.folder_id`, nested up to 5 levels, page under Einstellungen > Ordner (create, rename, move, delete), folder bar above the receipt list, folder choice in the receipt view, uploads inside a folder stay there. Every organisation has the folder "Belege" (undeletable, new receipts land there; existing receipts moved in by the migration). Bank matching only offers receipts of folders with "Bankabgleich" on (on for "Belege", off for new folders). 13 new folder tests plus one bank test; pint and PHPStan clean.
- Not done: print button and zoom in the document view (next PR); the module itself. Full suite not run on MySQL/MariaDB here (CI does); on SQLite three tests fail the same way on clean main (SetupCommandTest x2, UpdateTest x1), unrelated.
- Watch out: `Document::scopeMatchable()` still counts receipts without a folder (old data) as matchable. `Folder::defaultIdFor()` creates "Belege" for an organisation that has none.
- Branch/PR: manager/SCAN-folders

## 2026-11-07 · Manager · Folders on their own (preview)
- Done (PO request): the folders commit from the SCAN/PHONE branch (#89) taken out into its own branch and PR so the PO can preview and release folders first: folders and subfolders, an automatic "Belege" folder for every organisation, a folder bar in Belege, "Ordner" in the receipt view, bank matching per folder. Applied cleanly on main; full suite on SQLite passes except the known 5 MySQL-only tests; pint/PHPStan clean.
- Watch out (Lead): #89 still contains the same commit; once this is on main, rebasing #89 drops it.
- Branch/PR: manager/SCAN-folders
