# CLAUDE.md

All agent rules live in `AGENTS.md`. Both the Lead and the Manager are Claude sessions, so this file must stay role-neutral.

@AGENTS.md

## At the start of every session

1. Know your role (Lead or Manager) from the session's first message. If it isn't stated, ask the PO.
2. Read the latest entries in `docs/collaboration/LOG.md` and the board in `docs/collaboration/TASKS.md`.
3. Check `docs/collaboration/DECISIONS.md` for open questions addressed to your role.

## Talking to the other session

Both sessions run in the Claude desktop app on the same machine, so they can message each other directly (list peers, then send a message to the other DocHamster session). Use messages only for short pings ("PR ready for review", "question Q-7 is for you"). Anything that matters goes into the repo first – messages are not a record.
