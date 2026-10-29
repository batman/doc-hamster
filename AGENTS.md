# AGENTS.md – Rules for AI agents working on DocHamster

This file is the single source of truth for every AI agent on this repo (Claude Code, Codex, Gemini, Cursor, …). `CLAUDE.md` just imports it. If you are an AI agent: **read this file, then `docs/collaboration/WORKFLOW.md`, before doing anything.**

## Project in one paragraph

DocHamster is an open-source (MIT) PHP web app / PWA for collecting receipts and documents: snap a photo, send, done. The server does OCR, tagging, categorisation, bank-statement matching and reports, and gives a read-only view to the user's tax advisor. It is **not** a tax or bookkeeping app. Full plan: [`docs/PLAN.md`](docs/PLAN.md).

## Current phase

**Phase 0 – planning.** No application code yet. Allowed work: editing the plan, decisions, task board, design notes, repo tooling. Do **not** scaffold the framework or write app code until `docs/collaboration/DECISIONS.md` marks the "must decide before code" items as decided and PO says Phase 1 can start.

## Who is who

| Name | Role | Branch prefix |
|------|------|---------------|
| **PO** | Product owner. Final say on scope, decisions, merges. | – |
| **Agent A – Claude Code** | Implementer / reviewer | `claude/` |
| **Agent B – second AI** (tool to be filled in by PO) | Implementer / reviewer | `agent-b/` |

Each agent reviews the other's pull requests. Neither agent merges its own PR without PO's OK.

## Hard rules

1. **Claim before you work.** Take a task in [`docs/collaboration/TASKS.md`](docs/collaboration/TASKS.md) by setting yourself as owner and status `in progress` in a small commit on `main` (or in your PR's first commit). Never work on a task owned by the other agent.
2. **One task = one branch = one PR.** Branch name: `<prefix><task-id>-<slug>`, e.g. `claude/P1-4-passkey-pairing`. Keep PRs small (aim < 400 changed lines excluding fixtures/lockfiles).
3. **Stay inside your module.** Modules and their boundaries are in PLAN.md §5.4. Changing another module's public interface needs a note in the PR and a review by the other agent.
4. **Don't silently change decisions or scope.** Anything that alters PLAN.md scope, the data model, or an entry in DECISIONS.md goes through a PR labelled `plan` and needs PO's approval.
5. **Log every session.** Append a short entry to [`docs/collaboration/LOG.md`](docs/collaboration/LOG.md) when you stop working: what you did, what's left, anything the other agent must know. Never rewrite older entries.
6. **Ask, don't guess.** Open questions go to the "Open questions" section in DECISIONS.md with your agent name. Don't block on them if a sensible, reversible default exists – note the default you chose.
7. **No secrets, no real personal data** in the repo. Test receipts and bank files must be anonymised.

## Conventions

- Code, identifiers, comments, commit messages, PR texts: **English**. UI strings: **German**, always via i18n files, never hard-coded.
- Plan documents (`docs/PLAN.md`) are written in German; collaboration/process docs in English.
- Commits: [Conventional Commits](https://www.conventionalcommits.org/) – `feat(capture): …`, `fix(auth): …`, `docs(plan): …`, `chore: …`. Mention the task ID in the body.
- Money is integer minor units (cents) + ISO currency. IDs are ULIDs. Every domain table has `org_id`; every query is org-scoped.
- Security defaults from PLAN.md §11 are not optional (escaping, CSRF, prepared statements, files only via permission-checked route, process calls with argument arrays).
- Definition of Done: PLAN.md §13.

## Useful files

| File | Purpose |
|------|---------|
| `docs/PLAN.md` | What we build and in which order |
| `docs/collaboration/WORKFLOW.md` | How the two agents and PO work together |
| `docs/collaboration/TASKS.md` | Task board (who does what) |
| `docs/collaboration/DECISIONS.md` | Decisions (ADR-lite) and open questions |
| `docs/collaboration/LOG.md` | Append-only session log / handoffs |
| `docs/collaboration/PLAN-REVIEW.md` | Feedback round on the plan |
| `docs/SKILLS.md` | Recommended agent skills |
| `docs/archive/plan-v0-belegbox.md` | Original plan (read-only history) |
