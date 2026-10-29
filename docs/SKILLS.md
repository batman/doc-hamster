# Agent skills (required)

Both agents use the same skills so they follow the same conventions. **Skills are installed per computer**, so each agent installs them on the machine it runs on. The Lead and the Manager run on **different computers**, so installing on one does nothing for the other.

Source: skills registry ([skills.sh](https://skills.sh/), `npx skills find …`), checked 2026-10-29. Each skill's SKILL.md was read before it was added to this list (markdown instructions only, no scripts, no network calls).

## 1. Required now – both agents

| Skill | Installs | Use in DocHamster |
|-------|---------:|-------------------|
| `jeffallan/claude-skills@php-pro` | 15.7K | Modern PHP 8.3+, strict types, PSR, tests |
| `currents-dev/playwright-best-practices-skill@playwright-best-practices` | 99.6K | E2E tests: camera upload, offline queue, geolocation, passkeys |
| `alinaqi/maggy@pwa-development` | 4.1K | Service worker, manifest, offline |
| `mattpocock/skills@handoff` | 1.1M | Handoff document at the end of a session (user-invoked: `/handoff`); complements LOG.md |
| `addyosmani/agent-skills@code-review-and-quality` | 75.4K | Reviewing each other's PRs |

Install (run in a terminal on the agent's own computer, outside the repo folder, so nothing lands in the repo):

```bash
npx -y skills add jeffallan/claude-skills -s php-pro -g -a claude-code -y
npx -y skills add currents-dev/playwright-best-practices-skill -s playwright-best-practices -g -a claude-code -y
npx -y skills add alinaqi/maggy -s pwa-development -g -a claude-code -y
npx -y skills add mattpocock/skills -s handoff -g -a claude-code -y
npx -y skills add addyosmani/agent-skills -s code-review-and-quality -g -a claude-code -y
```

Check: `npx skills list -g` shows all five; restart the Claude Code session afterwards so they load.

## 2. Required once D-001 = Laravel – Lead (Manager optional)

| Skill | Installs | Use |
|-------|---------:|-----|
| `jeffallan/claude-skills@laravel-specialist` | 23.4K | Laravel conventions |
| `laravel/boost@laravel-best-practices` | 2.2K | Official Laravel source |
| `affaan-m/ecc@laravel-security` | 12.3K | Laravel security checklist |
| `affaan-m/ecc@laravel-tdd` | 11K | PHPUnit/Pest TDD |

Read each SKILL.md before installing; note in LOG.md which ones were installed. If D-001 = Slim, skip this block.

## 3. Rules for using skills

- **Repo rules win.** If a skill contradicts `AGENTS.md` or `docs/PLAN.md`, follow the repo. Known case: `php-pro` asks for PHPStan level 9, PLAN.md §13 says level 8 – the Lead decides and updates PLAN.md.
- Use `code-review-and-quality` for every PR review, `playwright-best-practices` for every E2E test, `pwa-development` for anything in `public/sw.js` / manifest, `php-pro` for all PHP.
- `handoff` writes to the OS temp folder, which the other agent can't see. Anything the other agent needs still goes into `LOG.md`.
- New skill? Propose it in this file via PR; the other agent installs it too.

## 4. Deliberately not used

| Skill | Why not |
|-------|---------|
| `othmanadi/planning-with-files` | Brings its own hook scripts and planning files that compete with TASKS.md/LOG.md |
| WebAuthn/OCR/bank-parsing skills | Nothing trustworthy found; use library docs |

## 5. Built-in skills (Claude Code, no install)

`security-review`, `code-review`, `everything-claude-code:tdd`, `everything-claude-code:e2e`, `anthropic-skills:pdf`, `anthropic-skills:xlsx`, depending on what each agent's environment provides.

## Install status

| Agent | Block 1 | Block 2 (Laravel) | Date |
|-------|---------|-------------------|------|
| Manager | installed | – (waits for D-001) | 2026-10-29 |
| Lead | installed (manual copy, see note) | todo – D-001 = Laravel, install in P1-1 | 2026-10-29 |

**Note (Lead):** `npx skills` needs Node.js ≥ 20 (it imports `styleText` from `node:util`); on Node 18 every command fails with a `SyntaxError`. Workaround used on the Lead's computer: shallow-clone the five source repos, read each SKILL.md, copy the skill folders to `~/.claude/skills/`. Same result, no Node upgrade needed.
