# AGENTS.md – Rules for AI agents working on DocHamster

This file is the single source of truth for every AI agent on this repo. `CLAUDE.md` just imports it. If you are an AI agent: **read this file, then `docs/collaboration/WORKFLOW.md`, before doing anything.**

> ⚠️ **This repository will become public on GitHub.** Treat every commit as published forever. Read [§ Public repository rules](#public-repository-rules) before your first commit.

## Project in one paragraph

DocHamster is an open-source (MIT) PHP web app for collecting receipts: snap a photo with the phone, send, done. The server matches it to the bank transaction (CSV/Excel import from any bank; live bank connections such as Revolut Business come as separately installable modules in their own repos, D-021), does OCR, tagging and the receipt pack (PDF), and gives a read-only view to the user's tax advisor. It is **not** a tax or bookkeeping app and uses **no cloud/external AI** – everything, incl. speech-to-text, runs locally on our server (D-019). Full plan: [`docs/PLAN.md`](docs/PLAN.md).

## Current phase

**Phase 1 – foundation (v0.1), started 2026-10-29 by PO go** (issue #8). Planning work (PLAN v2, open decisions) continues in parallel. Still open and needed before the packages that use them: D-000 (name), D-011 (v1 scope), D-018 (receipt-pack layout), Q-11 (`BankConnector`) – don't build on them until the Lead has decided.

Local checks before every PR: `composer lint`, `composer analyse`, `composer test`, `composer check-licenses`. Enable the secret-scan hook once per clone: `composer hooks` (needs gitleaks).

## Who is who

Both agents are Claude (Claude Code, separate sessions). Each session is told its role when it starts ("You are the Lead" / "You are the Manager"). If you weren't told, ask the PO before doing anything.

| Role | Responsibility | Decides | Branch prefix |
|------|----------------|---------|---------------|
| **PO** (product owner, human) | Can veto anything; standing OK that authors merge their own PRs (merge first, review after) | Nothing by default – all questions go to the Lead | – |
| **Lead** (Claude) | Technical lead and **answers all questions**: architecture, framework, server, hosting, security, scope and product questions (decides technical ones itself, asks its operator only product-design and hard-to-change questions, rule 6); implementation; assigns coding sub-tasks to the Manager | **All open decisions** (technical and product) | `lead/` |
| **Manager** (Claude) | **Manages the work split only:** who does what, what the Manager can take over, which points need an answer from the Lead's operator and which need an action by the PO; keeps TASKS.md and LOG.md current; critical reviews; **programs together with the Lead** (sub-tasks the Lead assigns) | Work split; no technical or product decisions | `manager/` |

- **All questions go to the Lead** (issue `to:lead`), not to the PO. The Lead decides; it asks its operator only product-design and hard-to-change questions (rule 6).
- The Manager **writes code only together with the Lead**: on sub-tasks the Lead assigns (e.g. from P0-23), following the Lead's technical decisions, in the Lead's review. The Manager never starts coding work on its own and answers no server/infrastructure questions.
- **Merge first, review after (PO rule, 2026-10-29).** The author merges its own PR as soon as CI is green – never wait for a review. The **other agent reviews merged PRs** when it has time (Lead reviews Manager PRs, Manager reviews Lead PRs); findings become an issue or a follow-up PR. Rules: `docs/collaboration/WORKFLOW.md` §10.

## Hard rules

1. **Claim before you work.** Take a task in [`docs/collaboration/TASKS.md`](docs/collaboration/TASKS.md) by setting yourself as owner and status `in progress`. Never work on a task owned by someone else.
2. **One task = one branch = one PR.** Branch name: `<prefix><task-id>-<slug>`, e.g. `lead/P1-4-passkey-pairing`. Keep PRs small (aim < 400 changed lines excluding fixtures/lockfiles).
3. **Respect module boundaries** (PLAN.md §5.4). Interface changes are explained in the PR.
4. **Don't silently change decisions or scope.** Anything that alters PLAN.md scope, the data model, or an entry in DECISIONS.md goes through a PR labelled `plan`.
5. **Log every session.** Append a short entry to [`docs/collaboration/LOG.md`](docs/collaboration/LOG.md) when you stop working. Never rewrite older entries.
6. **Ask, don't guess.** **All** open questions go to the Lead (issue `to:lead`, or DECISIONS.md with owner Lead) – technical and product alike.
   **Lead: decide yourself, ask your operator only what really needs them (operator rule, 2026-10-29).** Technical questions (framework, libraries, versions, security mechanics, tooling, file formats, process details) you decide yourself and record with reasoning – the operator does not want to answer them. Before asking, check whether the project (PLAN.md, existing decisions, common practice) already gives a logical, practically unambiguous answer; if so, decide and record it. Ask the operator only (a) decisions about the **logic and design of the product** (what it does for users, how it looks and behaves) and (b) decisions that are **hard to change later** (e.g. the project name, the data model's basic shape, going public). Ask at the level of the product, not in technical detail. Record every answer in the repo (DECISIONS.md, issue or LOG.md); the PO can veto any decision.
   **Lead: your operator gets only questions – never tasks, to-dos or "please do X".** Anything only a human can do (send a mail, register a domain, provide a server, test on a phone) goes to the PO as an issue `to:po`.
   **Lead: once the plan is ready, you code too** – not only plan and decide. Your Phase 1 packages are on the board; the Manager codes the ones you (or the PO) assign to it.
   **Lead: when you do ask, explain every ID to your operator – never just "Q-11" or "B7".** Say what the letter means (**D** = decision, `D-NNN` in DECISIONS.md · **Q** = open question, `Q-N` in DECISIONS.md · **B** = point from the Lead's plan review, `B1`… in PLAN-REVIEW.md · also **P** = task, e.g. `P0-12` in TASKS.md · **AK** = acceptance criterion in PLAN.md), then what the item is about in plain words, the options, your recommendation, and what changes with each answer – short and without technical depth. Every question gets a `Q-N` row in DECISIONS.md before you ask it.
7. **Follow the public repository rules below.** No exceptions.
8. **Talk through GitHub.** Questions, requests and replies between the agents go into GitHub **issues** labelled `to:lead` / `to:manager` / `to:po`, or into PR reviews. Check them at the start of every session. Decisions that come out of a discussion are recorded in DECISIONS.md.
9. **Review critically, never rubber-stamp.** A review names what was checked, what could break, and what is missing. "Looks good" alone is not a review.

## Public repository rules

The repo is private now and **will be made public**. Git history is permanent: deleting a file later does not remove it from history, forks or caches.

**Never commit:**
- Names, email addresses, phone numbers, addresses or any other data of real people (including the PO). Use roles ("PO", "Lead", "Manager").
- Real receipts, invoices, bank statements, IBANs, card numbers, tax IDs – not even "just for a test". Fixtures must be **synthetic**, or fully anonymised and checked by the Manager before commit.
- Server details: hostnames, domains, IP addresses, ports of real servers, provider account names, SSH config, deployment paths.
- Secrets of any kind: `.env`, API keys, tokens, passwords, private keys, TOTP seeds, encryption keys, webhook URLs – including Revolut client IDs, certificates and tokens, even sandbox ones.
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
| `docs/collaboration/MANAGER-KICKOFF.md` | Start prompt for a new Manager session |
| `docs/SKILLS.md` | Recommended agent skills |
| `SECURITY.md` | How to report vulnerabilities |
| `docs/archive/plan-v0-belegbox.md` | Original plan (read-only history) |
