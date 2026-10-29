# Collaboration workflow: PO + Lead + Manager

## 1. Roles

See the table in `AGENTS.md`. In short:

- **PO (human):** priorities, scope, product decisions, approves merges.
- **Lead (Claude):** all technical decisions (framework, server, hosting, infrastructure, security architecture) and the implementation.
- **Manager (Claude):** manages the **work split** only – who does what, what the Manager takes over, where the Lead's operator must answer or act (TASKS.md "Work split"). Programs **together with the Lead** on sub-tasks the Lead assigns. Critical reviews. No technical or product decisions; all questions go to the Lead.

### Who answers which question

| Question type | Goes to |
|---------------|---------|
| Server, hosting, network, Docker, framework, libraries, DB, OCR toolchain, security design | Lead |
| Scope, priorities, features, UX wording, what users need | Lead (asks its operator) |
| Plan structure, task breakdown, status, process, docs | Manager (questions about them → Lead) |

## 2. The shared state lives in the repo

The sessions don't share memory. Everything that matters must be committed:

| What | Where |
|------|-------|
| Who works on what | `TASKS.md` |
| Why something is the way it is | `DECISIONS.md` |
| What happened last, what's next | `LOG.md` |
| What we build | `../PLAN.md` |

The Lead and the Manager run on **different computers** and cannot message each other. The GitHub repository is the only channel: pull at the start of a session, push before ending it. Urgent things go to the PO, who relays them. If it isn't pushed, the other agent doesn't know it.

## 3. Task lifecycle

```
todo ──claim──▶ in progress ──PR opened──▶ in review ──approved+merged──▶ done
                     │                         │
                     └── blocked (reason) ◀────┘ (changes requested → back to in progress)
```

1. **Manager** creates/updates tasks in TASKS.md from the plan (IDs from PLAN.md §14), with dependencies and acceptance criteria references.
2. **Lead** claims the next unblocked task (owner + `in progress`).
3. Branch `<prefix><task-id>-<slug>` from latest `main`; small commits; lint/tests locally.
4. Push the branch, open a PR with the template → status `in review`; tell the PO so the Manager reviews it.
5. **Critical review by the other agent** (§10): the Manager reviews Lead PRs, the Lead reviews Manager PRs. The reviewer either approves or requests changes; the author answers every point.
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

## 10. Critical review (both directions)

Every PR gets a review from the **other** agent before the PO merges. Reviews are meant to find problems, not to be polite.

**The reviewer must:**
- Say **what was checked** (acceptance criteria, plan/decisions consistency, public-repo rules, security, tests, first-install/update/failure paths).
- List concrete findings as **must fix / should fix / question**, each with "what breaks" or "what is missing".
- Check the PR against **other decisions** – a change can be right on its own and still contradict D-0xx.
- Look for what is **not** in the PR (missing tests, missing docs, missing edge cases, missing migration path).
- Use `code-review-and-quality` (and `security-review` for auth, uploads, file serving, bank modules, exports).
- Use GitHub's **Request changes** when a must-fix exists; **Approve** only when none is left.

**The author must:**
- Reply to **every** point (fixed / disagree + reason / follow-up task with ID).
- Not merge around an open must-fix. Disagreements that stay open go to the deciding role (§1); the PO can override.

**Lead reviews Manager PRs** with focus on technical feasibility: is the plan implementable as written, are estimates and phase order realistic, do acceptance criteria test the right thing.
**Manager reviews Lead PRs** with focus on plan fit, scope, consistency between decisions, public-repo hygiene, and the user-visible result.

## 11. Communication channels

| What | Where |
|------|-------|
| Question / request to the other role | GitHub issue, label `to:lead`, `to:manager` or `to:po` |
| Feedback on a change | PR review comments |
| Result of a discussion | DECISIONS.md (decision) or TASKS.md (new task) |
| Session summary / handoff | LOG.md |

Open issues addressed to you are checked at the start of every session and answered before new work starts.

