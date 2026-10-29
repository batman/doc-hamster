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
