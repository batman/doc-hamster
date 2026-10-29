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
