# AGENTS.md – Rules for AI agents working on DocHamster

This file is the single source of truth for every AI agent on this repo. `CLAUDE.md` just imports it. If you are an AI agent: **read this file, then `docs/collaboration/WORKFLOW.md`, before doing anything.**

> ⚠️ **This repository will become public on GitHub.** Treat every commit as published forever. Read [§ Public repository rules](#public-repository-rules) before your first commit.

## Project in one paragraph

DocHamster is an open-source (MIT) PHP web app for collecting receipts: snap a photo with the phone, send, done. The server matches it to the Revolut transaction (by time, amount, merchant), does OCR, tagging and the receipt pack (PDF), and gives a read-only view to the user's tax advisor. It is **not** a tax or bookkeeping app and has **no AI features** (D-013). Full plan: [`docs/PLAN.md`](docs/PLAN.md).

## Current phase

**Phase 0 – planning.** No application code yet. Allowed work: editing the plan, decisions, task board, design notes, repo tooling. Do **not** scaffold the framework or write app code until the "must decide before code" items in `docs/collaboration/DECISIONS.md` are decided and the PO says Phase 1 can start.

## Who is who

Both agents are Claude (Claude Code, separate sessions). Each session is told its role when it starts ("You are the Lead" / "You are the Manager"). If you weren't told, ask the PO before doing anything.

| Role | Responsibility | Decides | Branch prefix |
|------|----------------|---------|---------------|
| **PO** (product owner, human) | Priorities, scope, product questions, approves merges | Product & scope decisions; can veto anything | – |
| **Lead** (Claude) | Technical lead: architecture, framework, server, hosting, infrastructure, security architecture, implementation | **All technical and server decisions** | `lead/` |
| **Manager** (Claude) | Project management: keeps PLAN.md, TASKS.md, DECISIONS.md, LOG.md current; breaks work into tasks; tracks progress; reviews PRs against the plan and the public-repo rules; relays questions between Lead and PO | Process & documentation; no technical decisions | `manager/` |

- The Manager **does not write application code** and does not answer server/infrastructure questions – those go to the Lead.
- The Manager reviews every Lead PR for plan fit, task scope, docs and public-repo hygiene. The Lead reviews Manager PRs that touch technical content.
- Nobody merges their own PR without the PO's OK.

## Hard rules

1. **Claim before you work.** Take a task in [`docs/collaboration/TASKS.md`](docs/collaboration/TASKS.md) by setting yourself as owner and status `in progress`. Never work on a task owned by someone else.
2. **One task = one branch = one PR.** Branch name: `<prefix><task-id>-<slug>`, e.g. `lead/P1-4-passkey-pairing`. Keep PRs small (aim < 400 changed lines excluding fixtures/lockfiles).
3. **Respect module boundaries** (PLAN.md §5.4). Interface changes are explained in the PR.
4. **Don't silently change decisions or scope.** Anything that alters PLAN.md scope, the data model, or an entry in DECISIONS.md goes through a PR labelled `plan`.
5. **Log every session.** Append a short entry to [`docs/collaboration/LOG.md`](docs/collaboration/LOG.md) when you stop working. Never rewrite older entries.
6. **Ask, don't guess.** Open questions go to DECISIONS.md, addressed to the role that decides (technical → Lead, product → PO). Don't block if a sensible, reversible default exists – note the default.
7. **Follow the public repository rules below.** No exceptions.

## Public repository rules

The repo is private now and **will be made public**. Git history is permanent: deleting a file later does not remove it from history, forks or caches.

**Never commit:**
- Names, email addresses, phone numbers, addresses or any other data of real people (including the PO). Use roles ("PO", "Lead", "Manager").
- Real receipts, invoices, bank statements, IBANs, card numbers, tax IDs – not even "just for a test". Fixtures must be **synthetic**, or fully anonymised and checked by the Manager before commit.
- Server details: hostnames, domains, IP addresses, ports of real servers, provider account names, SSH config, deployment paths.
- Secrets of any kind: `.env`, API keys, tokens, passwords, private keys, TOTP seeds, encryption keys, webhook URLs.
- Screenshots or logs that show real data.
- Answers to personal questions (which bank you use, who your tax advisor is …). Record only the generic outcome ("support Sparkasse CSV first").

**Where private things go instead:** `.env` (git-ignored), the server itself, or a separate private repo/notes the PO controls. Examples in the repo use `example.com`, `192.0.2.x` (RFC 5737), `Max Mustermann`, `DE00 0000 0000 0000 0000 00`.

**Commit identity:** commits use a GitHub noreply address (`<id>+<user>@users.noreply.github.com`), not a private email.

**If something slips in:** stop, tell the PO, rotate the secret immediately (assume it is leaked), then clean history *before* the repo goes public. A leaked secret is never "fixed" by a follow-up commit.

**Tooling (set up by the Lead in P1-1):** gitleaks pre-commit hook + CI job; GitHub secret scanning and push protection enabled before going public; Dependabot.

## Conventions

- Code, identifiers, comments, commit messages, PR texts: **English**. UI strings: **German**, always via i18n files.
- `docs/PLAN.md` is German; collaboration/process docs are English.
- Commits: Conventional Commits – `feat(capture): …`, `docs(plan): …`, `chore: …`. Task ID in the body.
- Money: integer minor units + ISO currency. IDs: ULIDs. Every domain table has `org_id`; every query is org-scoped.
- Security defaults from PLAN.md §11 are not optional.
- Definition of Done: PLAN.md §13.

## Useful files

| File | Purpose |
|------|---------|
| `docs/PLAN.md` | What we build and in which order |
| `docs/collaboration/WORKFLOW.md` | How Lead, Manager and PO work together |
| `docs/collaboration/TASKS.md` | Task board |
| `docs/collaboration/DECISIONS.md` | Decisions (ADR-lite) and open questions |
| `docs/collaboration/LOG.md` | Append-only session log / handoffs |
| `docs/collaboration/PLAN-REVIEW.md` | Feedback round on the plan |
| `docs/collaboration/LEAD-KICKOFF.md` | Start prompt for the Lead session |
| `docs/SKILLS.md` | Recommended agent skills |
| `SECURITY.md` | How to report vulnerabilities |
| `docs/archive/plan-v0-belegbox.md` | Original plan (read-only history) |
