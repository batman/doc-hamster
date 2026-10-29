# Collaboration workflow: PO + Lead + Manager

## 1. Roles

See the table in `AGENTS.md`. In short:

- **PO (human):** priorities, scope, product decisions, approves merges.
- **Lead (Claude):** all technical decisions (framework, server, hosting, infrastructure, security architecture) and the implementation.
- **Manager (Claude):** keeps the plan and the shared files current, slices work into tasks, tracks progress, reviews PRs against plan and public-repo rules, relays questions. Writes no application code and makes no server/infrastructure decisions.

### Who answers which question

| Question type | Goes to |
|---------------|---------|
| Server, hosting, network, Docker, framework, libraries, DB, OCR toolchain, security design | Lead |
| Scope, priorities, features, UX wording, what users need | PO |
| Plan structure, task breakdown, status, process, docs | Manager |

## 2. The shared state lives in the repo

The sessions don't share memory. Everything that matters must be committed:

| What | Where |
|------|-------|
| Who works on what | `TASKS.md` |
| Why something is the way it is | `DECISIONS.md` |
| What happened last, what's next | `LOG.md` |
| What we build | `../PLAN.md` |

Direct messages between the two sessions are for pings only ("PR #3 ready", "Q-6 is yours"). If it isn't committed, it didn't happen.

## 3. Task lifecycle

```
todo ──claim──▶ in progress ──PR opened──▶ in review ──approved+merged──▶ done
                     │                         │
                     └── blocked (reason) ◀────┘ (changes requested → back to in progress)
```

1. **Manager** creates/updates tasks in TASKS.md from the plan (IDs from PLAN.md §14), with dependencies and acceptance criteria references.
2. **Lead** claims the next unblocked task (owner + `in progress`).
3. Branch `<prefix><task-id>-<slug>` from latest `main`; small commits; lint/tests locally.
4. PR with the template → status `in review`; ping the Manager.
5. **Manager review:** acceptance criteria met, scope matches the task, docs/TASKS/LOG updated, public-repo rules respected (no personal data, secrets, server details). Technical depth is the Lead's responsibility; the Manager flags what looks risky.
6. PO merges (squash) → `done` → log entry.

## 4. Handoff / log entry

At the end of every session, append to `LOG.md`:

```markdown
## 2026-10-29 · Lead · P1-4
- Done: …
- Not done / next step: …
- Watch out: … (gotchas, defaults chosen, things the other role must know)
- Branch/PR: lead/P1-4-passkey-pairing (#12)
```

## 5. Avoiding conflicts

- The Manager edits PLAN.md / TASKS.md / DECISIONS.md; the Lead edits code, infra and technical docs. Where both need the same file, the Manager makes the edit after the Lead's input is recorded.
- Migrations: new file per change, never edit a merged migration.
- Disagreement: comment on the PR or add an open question in DECISIONS.md; the deciding role (see §1) settles it, PO can override.

## 6. Changing the plan (Phase 0)

1. Feedback goes into `PLAN-REVIEW.md`, one section per role, referencing PLAN.md section numbers.
2. Technical points: Lead decides. Product points: PO decides.
3. The Manager merges accepted points into PLAN.md in a PR labelled `plan` (task P0-1c).

## 7. Decisions

- `DECISIONS.md` holds numbered decisions `D-NNN` with an **Owner** (Lead or PO) and status `open` / `proposed` / `decided` / `superseded`.
- Anyone may add a recommendation (`proposed`). Only the owner sets `decided`; the PO may veto.
- Decided entries are not edited, only superseded by a new entry.

## 8. Public repository

The repo will be public. Before every PR, the author runs the checklist in the PR template; the Manager checks it again in review. Before the repo is switched to public, the Manager runs a full history scan (task P0-8) and the PO confirms.

## 9. Quality gates (once code exists)

- CI must be green before review.
- PRs touching auth, permissions, uploads, file serving, ingestion channels or exports list their security considerations in the PR.
- Definition of Done: PLAN.md §13.
