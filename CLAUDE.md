# CLAUDE.md

All agent rules live in `AGENTS.md`. Both the Lead and the Manager are Claude Code sessions, so this file must stay role-neutral.

@AGENTS.md

## At the start of every session

1. Know your role (Lead or Manager) from the session's first message. If it isn't stated, ask the PO.
2. `git pull` – the other agent works on a **different computer**; you only see its work after it pushed and you pulled.
3. Read the latest entries in `docs/collaboration/LOG.md`, the board in `docs/collaboration/TASKS.md`, and open questions for your role in `docs/collaboration/DECISIONS.md`.
4. Check that the required skills from `docs/SKILLS.md` are installed on your machine.

## Talking to the other agent

The Lead and the Manager run on different computers and **cannot message each other directly**. All communication goes through the GitHub repository:

- Commit and **push** your changes (LOG.md entry, TASKS.md status, PRs) before you end a session.
- Questions for the other role go into DECISIONS.md "Open questions" or as a PR comment.
- Anything urgent: tell the PO in your reply; the PO relays it.
