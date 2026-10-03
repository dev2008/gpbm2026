# Task: <one line — the feature, from `todo.md`>

## Context

We are modernizing a complex legacy system (a bespoke Windows tool, Excel, and a legacy web
frontend) into a pure PHP/MariaDB web application on DaDaBIK 13.5.

Please review these before beginning, and **tell me if any are missing or look stale**:

- `todo.md` — the feature list. **This task is Feature N.**
- `task-N-<name>.md` — the task doc for this feature, where the design work already done lives.
  **Treat its figures, queries and proposed fix as a hypothesis to test, not as findings.** In
  particular, any query it contains encodes the scope its author assumed — before adopting one,
  work out what population it *cannot* see and say whether that population is genuinely out of
  scope or merely invisible (`lessons.md` §27)
- `schema.md` — database structure. **§12 (franchises, team identities, team codes) is
  mandatory reading before writing any query that maps a team name or code to a franchise.**
- `new_schema.sql` — the schema as designed. Where its DDL comments and `schema.md`'s prose
  disagree, the DDL comments win (`lessons.md` §12)
- `<latest structure dump>.sql` — the schema as implemented. Check it against `new_schema.sql`
  rather than assuming they match. **If no dump is supplied, ask for one or read
  `information_schema` before writing any query — do not infer a table's columns from prose**
  (`lessons.md` §11)
- `lessons.md` — established patterns and past pitfalls
- `style-guide.md` — layout and theming conventions
- `<any PHP files this touches>`

## Objective

<what the page/feature/migration must do>

## Scope

**In scope:** <specific list>

**Out of scope:** <specific list — things that will come up and must NOT be picked up. Name
databases and tables explicitly; "server data" or "the legacy system" is ambiguous when the
work spans `gplan_pbm`, `gplan_main` and the MountZion deployment.>

If something outside this scope turns out to be a **blocker**, stop and tell me before doing
anything about it. If it is not a blocker, write it up as a task doc for `todo.md` and carry on
(`lessons.md` §21). One chat, one feature.

## Working agreement

- **Check against real data, never assume.** If a claim can be verified with a query, verify it
  before building on it. This applies to your own reasoning as much as to anything I tell you —
  an explanation that fits the evidence is not the same as one that has been tested against it.
- **Account for the whole population.** State the table's total row count and reconcile every
  row your analysis excludes. A filter that silently drops tens of thousands of rows is the
  easiest way to report a feature complete while leaving most of the defect in place
  (`lessons.md` §27).
- **Read actual rows before acting on an aggregate.** Before any destructive step, dump a full
  sample from at least one affected case and read it. Ratios and counts describe the data; they
  do not show you what is in it (`lessons.md` §25).
- **Propose before implementing.** Flag assumptions and get them confirmed rather than acting on
  them silently.
- **You have no database access** — give me SQL to run and I will paste back the output.
  - *One query, small result:* inline in the chat.
  - *A batch, or wide results:* write a `.sql` file with `SELECT '=== A.1  label ==='`
    banners between sections (the CLI gives no other indication of which grid came from
    which statement). I run
    `mysql -u nz -p -t gplan_pbm < file.sql > results.txt` and upload the output.
  - For **read-only** batches, add `--force` after `-p` so one bad statement doesn't abort the
    rest. Never use it on anything that writes — stopping at the first error is the point there.
  - Don't tell me to add `2>&1` to a command with `-p` — the redirect swallows the password
    prompt and it fails as `using password: NO`.
- **Stage anything destructive**: read-only inspection first, then a reversible change, then
  verification. Never a destructive operation while validation is ambiguous.
- **Say when you get something wrong**, and put it in `lessons.md` if it is the kind of mistake
  that would recur.

## Deliverables

- <the artefact(s)>
- Verification steps or test queries proving the output is correct. These must include:
  - **at least one check that could fail** — not only ones that confirm the happy path
  - **at least one check run over the whole population**, not just the rows the change touched,
    so it fails if the change did too little somewhere unexamined
  - **expected values derived from the data, not computed by hand.** Snapshot a count before the
    change and reconcile against it; a constant worked out in your head inherits the reasoning
    it was meant to check (`lessons.md` §26)
  - **gates that always emit a row.** A check whose PASS depends on a row existing is
    indistinguishable from a check that never ran
  - **when restating an earlier check in a new context, a diff against the original** rather
    than a retype from memory
- Any documentation updates: `lessons.md`, `schema.md`, `new_schema.sql`, `todo.md`. Edit the
  living files directly rather than producing a separate "to be merged" document — the last two
  sessions both left one unmerged.

## Done means

Feature complete and tested locally. Server deployment is out of scope.
