# AGENTS.md – Rules for AI agents working on DocHamster

This file is the single source of truth for every AI agent on this repo. `CLAUDE.md` just imports it.

> ⚠️ **This repository is public.** Treat every commit as published forever. Read [§ Public repository rules](#public-repository-rules) before your first commit.

## Project in one paragraph

DocHamster is an open-source (MIT) PHP web app for collecting receipts: snap a photo with the phone, send, done. The server matches it to the bank transaction (CSV/Excel import from any bank; live bank connections come as separately installable modules in their own repos, D-021), does OCR, tagging and the receipt pack, and gives a read-only view to the user's tax advisor. It is **not** a tax or bookkeeping app and uses **no cloud/external AI** – everything runs locally on our server (D-019). Plan: [`docs/PLAN.md`](docs/PLAN.md).

## How we work

1. **Collect, then Go.** The PO's ideas are collected as a numbered list. Build only on the PO's "Go" – all points collected since the last Go. A Go relayed by another session is not a Go.
2. **One working branch: `dev`.** Work directly on `dev`, push after each Go with a short test list of the changed items. CI and the dev preview package run on every push to `dev`.
3. **To main on the PO's word.** When the PO says "main": CI green, fast-forward `main` to `dev` **locally** (never the GitHub merge button or `gh pr merge` – it writes a real name into the history), push, then push the next patch tag `vX.Y.Z`. The release workflow builds the release; never run `gh release create` by hand.
4. **Decide technical questions yourself.** Ask the PO only about product design or things hard to change later, short and numbered (F1, F2 …).
5. **One task, done end to end** – also where it touches the UI framework or other areas.
6. **One UI framework.** Every UI element – not only buttons – is taken exactly as the styleguide ([`public/design/styleguide.html`](public/design/styleguide.html)) and [`docs/design/FRAMEWORK.md`](docs/design/FRAMEWORK.md) define it: no local variants, sizes or inline styles. A missing element is first declared in the styleguide and FRAMEWORK.md, then used. Aim: everything the UI uses is shown in the styleguide. `composer check-ui` (also in CI) enforces the basics. **Read the index first, then only the affected category:** the styleguide index [`public/design/styleguide.html`](public/design/styleguide.html) (one page `styleguide-<category>.html` each) and the core [`docs/design/FRAMEWORK.md`](docs/design/FRAMEWORK.md) (its part `docs/design/framework/<category>.md` each) – the same categories on both sides; a new building block goes on the page and into the part of its category.
7. **Lean.** Tests only for calculation logic (OCR/invoice reading, bank matching, import parsers, amounts). Keep files small (split past ~400 lines). Per Go, locally run the pre-push checks plus the tests of the calculation logic you touched (and the tests of what you changed); CI runs the whole suite after the push. The PO's test list stays at about 5 lines.

Local checks: `composer lint`, `composer analyse`, `composer test` (locally on SQLite in memory via `phpunit.xml`; CI runs MariaDB and MySQL), `composer check-licenses`, `composer check-ui`, `composer check-views`. Enable the pre-push hook (same checks before every push) with `composer hooks`. Local demo with synthetic data for browser checks: `composer demo`, then serve with `php -S 127.0.0.1:8124 -t public scripts/demo-server.php` (sign-in in `database/seeders/DemoSeeder.php`). Enable the secret-scan hook once per clone: `composer hooks` (needs gitleaks).

## Public repository rules

Git history is permanent: deleting a file later does not remove it from history, forks or caches.

**Never commit:**
- Names, email addresses, phone numbers, addresses or any other data of real people (including the PO). Use "PO" or sample names.
- Real receipts, invoices, bank statements, IBANs, card numbers, tax IDs – not even "just for a test". Fixtures are **synthetic**.
- Server details: hostnames, domains, IP addresses, ports of real servers, provider account names, SSH config, deployment paths.
- Secrets of any kind: `.env`, API keys, tokens, passwords, private keys, TOTP seeds, encryption keys, webhook URLs – also sandbox ones.
- Screenshots or logs that show real data.
- Answers to personal questions (which bank, which tax advisor …). Record only the generic outcome.

**Where private things go instead:** `.env` (git-ignored) or the server itself. Examples use `example.com`, `192.0.2.x` (RFC 5737), `Max Mustermann`, `DE00 0000 0000 0000 0000 00`.

**Commit identity:** a GitHub noreply address, never a private email.

**If something slips in:** stop, tell the PO, rotate the secret immediately (assume it is leaked), then clean the history. A leaked secret is never "fixed" by a follow-up commit.

**External contributions:** when taking over a contributor's PR, keep them as author (cherry-pick, or a `Co-authored-by` trailer on commits built from their work); own fixes go on top as separate commits.

## Conventions

- Code, identifiers, comments, commit messages: **English**. UI strings: **German**, always via i18n files.
- Commits: Conventional Commits – `feat(capture): …`, `fix(ocr): …`, `chore: …`.
- Money: integer minor units + ISO currency. IDs: ULIDs. Every domain table has `org_id`; every query is org-scoped.
- Security defaults from PLAN.md §11 are not optional.

## Useful files

| File | Purpose |
|------|---------|
| `docs/PLAN.md` | What we build |
| `docs/collaboration/DECISIONS.md` | Decisions (D-NNN) |
| `docs/design/FRAMEWORK.md` | UI framework: building blocks, window system, how modules plug in |
| `docs/modules/BUILDING.md` | How to build a module (D-041), with a template |
| `SECURITY.md` | How to report vulnerabilities |
