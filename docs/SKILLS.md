# Recommended agent skills

Found with the skills registry (`npx skills find …`, [skills.sh](https://skills.sh/)) on 2026-10-29. **Nothing is installed yet** – install only what we agree on, ideally after D-001 (framework) is decided. Install counts are from that date.

## Already available in the Claude Code environment (both sessions)

| Skill | Use in DocHamster |
|-------|-------------------|
| `everything-claude-code:plan` | Planning per phase before coding |
| `everything-claude-code:tdd` / `tdd-workflow` | Test-first for extraction, matching, permissions |
| `everything-claude-code:security-review` / `security-review` | Reviews of auth, uploads, file serving, exports |
| `everything-claude-code:backend-patterns` | API / service design |
| `everything-claude-code:e2e` | Playwright E2E (capture flow, offline queue, pairing) |
| `code-review` | Manager reviews of Lead PRs |
| `anthropic-skills:pdf`, `anthropic-skills:xlsx` | Generating/inspecting test PDFs and bank XLSX fixtures |
| `anthropic-skills:apple-design` | UI review of the PWA (mobile-first, HIG lens) |

## Candidates from the registry

| Skill | Installs | Why | Install |
|-------|---------:|-----|---------|
| `jeffallan/claude-skills@laravel-specialist` | 23.4K | Laravel conventions (if D-001 = Laravel) | `npx skills add jeffallan/claude-skills@laravel-specialist` |
| `jeffallan/claude-skills@php-pro` | 15.7K | Modern PHP 8.x idioms, either framework | `npx skills add jeffallan/claude-skills@php-pro` |
| `affaan-m/ecc@laravel-security` | 12.3K | Laravel security checklist | `npx skills add affaan-m/ecc@laravel-security` |
| `affaan-m/ecc@laravel-tdd` | 11K | PHPUnit/Pest TDD for Laravel | `npx skills add affaan-m/ecc@laravel-tdd` |
| `laravel/boost@laravel-best-practices` | 2.2K | Official Laravel source | `npx skills add laravel/boost@laravel-best-practices` |
| `currents-dev/playwright-best-practices-skill@playwright-best-practices` | 99.6K | E2E tests for the PWA | `npx skills add currents-dev/playwright-best-practices-skill@playwright-best-practices` |
| `alinaqi/maggy@pwa-development` | 4.1K | Service worker, manifest, offline | `npx skills add alinaqi/maggy@pwa-development` |
| `mattpocock/skills@handoff` | 1.1M | Structured handoffs between agents/sessions – fits LOG.md | `npx skills add mattpocock/skills@handoff` |
| `othmanadi/planning-with-files@planning-with-files` | 47.4K | File-based planning, matches our repo-as-shared-state approach | `npx skills add othmanadi/planning-with-files@planning-with-files` |
| `addyosmani/agent-skills@code-review-and-quality` | 75.4K | Cross-agent code review | `npx skills add addyosmani/agent-skills@code-review-and-quality` |

Not found / weak results: WebAuthn/passkeys (only tiny or vendor-specific skills), Tesseract OCR, bank-statement parsing. We'll rely on the plan and library docs for those.

Before installing any third-party skill: read its SKILL.md, check the source repo (stars, maintainer) – skills run with the agent's permissions.

## For the Lead

Both sessions are Claude Code, so installed skills apply to both. The Lead decides which framework-specific skills to install after D-001.
