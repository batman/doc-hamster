# CLAUDE.md

All agent rules live in `AGENTS.md`. Both the Lead and the Manager are Claude Code sessions, so this file must stay role-neutral.

@AGENTS.md

## At the start of every session

1. Know your role (Lead or Manager) from the session's first message. If it isn't stated, ask the PO. **Lead:** when any question comes up, ask your operator what to do before acting (AGENTS.md rule 6).
2. `git pull` – the other agent works on a **different computer**; you only see its work after it pushed and you pulled.
3. Read the latest entries in `docs/collaboration/LOG.md`, the board in `docs/collaboration/TASKS.md`, and open questions for your role in `docs/collaboration/DECISIONS.md`.
4. Check GitHub: open issues labelled for your role (`gh issue list --label to:lead` or `to:manager`) and PRs waiting for your review (`gh pr list --search "review-requested:@me"` or open PRs by the other agent). Answer or review them before starting new work.
5. Check that the required skills from `docs/SKILLS.md` are installed on your machine.

## Talking to the other agent

The Lead and the Manager run on different computers and **cannot message each other directly**. All communication goes through the GitHub repository:

- Commit and **push** your changes (LOG.md entry, TASKS.md status, PRs) before you end a session.
- Questions and requests for the other role: a GitHub **issue** with label `to:lead` / `to:manager` (or `to:po` for the PO), or a PR review comment. Reply in the same issue; close it when settled and record any decision in DECISIONS.md.
- If you can't use `gh` on your machine, tell the PO and use the GitHub web UI via the PO.
- Anything urgent: tell the PO in your reply; the PO relays it.
