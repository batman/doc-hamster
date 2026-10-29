# Collaboration workflow: PO + two AI agents

## 1. Roles

- **PO (product owner):** sets priorities, decides open questions, approves plan changes, merges PRs.
- **Agent A (Claude Code)** and **Agent B (second AI):** equal peers. Each implements tasks and reviews the other's work. Suggested default split once coding starts (can be changed in TASKS.md):
  - Agent A: Core, Auth, Org, Capture, Processing (Phases 1–4)
  - Agent B: Banking (Phase 5, can start in parallel after Phase 1), Reports, then reviewer for Auth/Sharing security
  - Whoever is free takes the next unblocked task.

## 2. The shared state lives in the repo

The agents do not share memory or chat. Everything one agent needs to know from the other must be in the repo:

| What | Where |
|------|-------|
| Who works on what | `TASKS.md` |
| Why something is the way it is | `DECISIONS.md` |
| What happened last, what's next | `LOG.md` |
| What we build | `../PLAN.md` |

**Rule of thumb:** if it isn't committed, the other agent doesn't know it.

## 3. Task lifecycle

```
todo ──claim──▶ in progress ──PR opened──▶ in review ──approved+merged──▶ done
                     │                         │
                     └── blocked (reason) ◀────┘ (changes requested → back to in progress)
```

1. **Pick** an unblocked `todo` task (dependencies done). Prefer your default area.
2. **Claim**: set `Owner` and `Status: in progress` in TASKS.md, commit (`chore(tasks): claim P1-4`), push. If the push conflicts because the other agent just claimed it, pick another task.
3. **Branch**: `<prefix><task-id>-<slug>` from latest `main`.
4. **Work** in small commits. Run lint/tests locally before pushing.
5. **Open a PR** using the template. Status → `in review`. Request review from the other agent (PO forwards / tags it).
6. **Review**: the other agent checks against the task's acceptance criteria, PLAN.md, AGENTS.md rules, security section. Leaves comments; approves or requests changes.
7. **Merge**: PO merges (squash). Status → `done`. Log entry.

## 4. Handoff protocol

At the end of every working session, append to `LOG.md`:

```markdown
## 2026-10-29 · Agent A (Claude Code) · P1-4
- Done: …
- Not done / next step: …
- Watch out: … (gotchas, decisions taken with defaults, things the other agent must know)
- Branch/PR: claude/P1-4-passkey-pairing (#12)
```

When handing a task over to the other agent mid-way: set `Owner` to the other agent in TASKS.md, mention it in LOG.md with exact next steps, and push the branch.

## 5. Avoiding conflicts

- Never edit files that belong to a task the other agent has `in progress`, except to fix a broken `main` (then say so in LOG.md).
- Shared files that both touch often (routes, migrations list, i18n files, TASKS.md): keep edits minimal and append-only where possible; rebase before opening the PR.
- Migrations: new file per change, timestamp-named, never edit a merged migration.
- If you think the other agent's approach is wrong: comment on the PR or add an open question in DECISIONS.md. Don't rewrite their code in your PR.

## 6. Changing the plan

During Phase 0 the plan itself is the work product.

1. Feedback goes into `PLAN-REVIEW.md` (one section per agent, referencing PLAN.md section numbers).
2. PO decides what is accepted. Accepted points become edits to PLAN.md and, where relevant, entries in DECISIONS.md.
3. Edits to PLAN.md happen in a PR labelled `plan`, by **one agent at a time** (claim task P0-1 or a dedicated `PLAN-x` task first) to avoid merge conflicts in one big file.

## 7. Decisions

- `DECISIONS.md` holds numbered decisions `D-NNN` with status `open` / `proposed` / `decided` / `superseded`.
- An agent may move a decision to `proposed` with a recommendation; only PO moves it to `decided`.
- Decided entries are not edited, only superseded by a new entry.

## 8. Communication with PO

- Questions that block a task: open question in DECISIONS.md **and** mention it at the end of your session reply so PO sees it.
- PO relays between agents if they run in separate tools. Messages that should reach the other agent belong in LOG.md, not only in chat.

## 9. Quality gates (once code exists)

- CI must be green before review is requested.
- Every PR touching auth, permissions, uploads, file serving or exports gets a security-focused review from the other agent (checklist in PLAN.md §11.2).
- Definition of Done: PLAN.md §13.
