# PR review comments

Bot reviewers (Sourcery, Copilot, CodeRabbit, …) comment automatically on every
push. Use this loop instead of reading the GitHub page.

1. `scripts/pr-comments.sh <pr>` lists bot comments this workspace has not
   reviewed yet, with the comment id needed to reply. `--all` re-lists
   everything; `--mark-seen` records the current set as handled.
2. Judge each comment against the code (rubric below), then fix, reply, or
   defer.
3. Run `./scripts/dev.sh test all`, push, and let CI run.
4. `scripts/pr-comments.sh <pr> --mark-seen` once every comment is fixed,
   replied to, or explicitly deferred.

The seen list is local-only state under `.scratch/pr-comments/`, so it is not
shared between machines. Sourcery's "reviewer's guide" issue comment is
orientation, not a finding: mark it seen without acting.

## Verdicts

- **Real defect** — reproducible against this diff, or a rule the diff breaks.
  Fix it here; add a test when the logic is non-trivial.
- **Real but pre-existing / out of scope** — say so, name the follow-up, mark
  it deferred. Do not bundle it into this PR.
- **False positive** — reply with the reasoning (what the code actually does,
  or the trade-off the suggested fix would cost). One reply per declined
  finding; no silent ignores.
- **Duplicate** — reply "covered by <comment id>" and move on.

## Judgement rules

- Security, data loss and money correctness beat everything else; style and
  naming come last.
- Never apply a bot's suggested patch unread. Read the surrounding code first.
- A fix that adds a lock, query or dependency to a hot path is a trade-off, not
  a free improvement; state the cost in the reply.
- Line numbers in a comment are from review time. Re-check the current hunk
  before editing; the code may have moved under it deliberately.
- A bot can be right about the mechanism and wrong about the fix. Accept the
  finding, not the patch, when the two disagree.
