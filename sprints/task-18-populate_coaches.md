# Task: Automate `coaches.id_user` population

**Status:** Design complete (`security.md` §7), zero implementation exists. Every design
decision below has been made and reasoned through; nothing here has been built — no hook, no
custom page, no validation code, no way of surfacing a mismatch to an Administrator. This
document exists so a fresh conversation can pick up straight into implementation without
re-deriving the design from `security.md`/conversation history first.

---

## 1. Goal

`coaches.id_user` (nullable lookup to `zpbm_users.id_user`, already present in the schema —
**not a new column, no migration needed for the column itself**) links a `coaches` row (one
person's coaching identity within one league) to a real website login. Right now it's populated
**entirely by hand** — every existing value was set via a direct SQL `UPDATE` for testing.
That's fine for a two-person family install; it doesn't scale to "other coaches log in and
upload their own turns," which is the explicitly stated future direction (`security.md` §2,
group 2 "Normal" accounts).

This task automates keeping `id_user` current as that happens — detecting, from a coach's own
turn upload, that their login corresponds to a specific `coaches` row, and linking it safely.

---

## 2. What's already designed — don't re-derive, just implement

Full reasoning lives in `security.md` §7; summarized here for a self-contained spec.

### The signal
`raw_uploads` carries both `uploaded_by` (who uploaded — populated by a pre-existing,
DaDaBIK-native mechanism, **not fully confirmed as reliably live vs. one-time/historical — see
caveat below**) and `franchise_id` (which franchise the turn was for) on the same row, every
time. Every turn upload is natively a `(coach, franchise)` candidate pairing.

**Caveat, worth resolving before/during this task, not assumed:** `uploaded_by`'s population
mechanism was investigated but never fully pinned down — confirmed working on at least one live
insert during testing, but whether it's DaDaBIK's own auto-fill, a DB trigger, or something else
entirely was never conclusively identified. Doesn't block this task (the column reliably has the
right value either way, confirmed via testing), just flagged so nobody assumes more certainty
about the *mechanism* than actually exists.

### The validation gate — this is the actual design, not optional

Nothing stops an Administrator (or in principle any logged-in user) from uploading a turn for a
franchise that isn't theirs. So the design is **not** "trust every upload's `(uploaded_by,
franchise_id)` pairing" — it's:

1. **Candidate signal:** an upload's `(uploaded_by, franchise_id)` pair.
2. **Resolve the current coach:** which `coaches` row currently holds that franchise's open
   tenure — `franchise_coach_tenures WHERE franchise_id = :fid AND end_week_id IS NULL` →
   `coach_id` → `coaches.name`.
3. **Validation gate:** does the uploader's registered name (`zpbm_users.first_name_user`/
   `last_name_user`, falling back to `username_user`) match *that specific* `coaches.name`? Not
   "matches some coach somewhere" — matches the one currently holding this exact franchise.
4. **Match → safe to auto-update that `coaches` row's `id_user`. Mismatch → surface it for an
   Administrator to review. Never auto-update on a mismatch, no exceptions.**

That fourth point is a stated general principle, not just a rule for this one feature: **a
permission-relevant field should never be silently updated from a path that includes
user-supplied content, no matter how indirect.** Whether that's someone hand-editing turn text,
or just picking the wrong franchise from a dropdown (mistake or otherwise) — auto-granting
`id_user` off the back of it would mean the access decision is effectively made by whoever's
logged in, not by an Administrator. This is why an Administrator uploading for the wrong
franchise doesn't need special-casing — their own name simply won't match that franchise's
current coach, so the gate naturally rejects it and surfaces it like any other mismatch.

### Known fragility, and how it's meant to be handled — don't build around it differently

`coaches.name` is free text from turn-file box scores ("Alan Milnes"). Comparing that against
`zpbm_users.first_name_user`/`last_name_user` is fragile on formatting alone (case, middle
names, nicknames) even when the underlying match is genuinely correct person-to-person.

**Deliberately not solved with fuzzy-matching or normalization logic.** The design fixes this at
the *source* instead: whenever a registration flow exists for new coach accounts, it should
carry an explicit, prominent instruction — **"register your name exactly as it appears on your
turns."** Building smarter matching to compensate for sloppy names was considered and rejected;
preventing the mismatch is better than working around it. (Whether "a registration flow" means
DaDaBIK's own native user registration with a customized label/instruction, or a custom-built
page, hasn't been confirmed — check what's actually available before assuming either way.)

### Residual risk — accepted, not solved, don't try to close this here

A non-admin coach uploading *another* coach's turn as a favor would still pass the validation
gate if the names happen to line up. Low-stakes, explicitly accepted rather than engineered
around — not the same as the admin case, which the design already handles cleanly (see above).

---

## 3. What's genuinely undesigned — real decisions needed during implementation

`security.md` documents the *rules*, not an implementation. These are open:

### Where does this logic run?
Two established architectural patterns already exist in this codebase, and this feature could
reasonably follow either:
- **A hook**, like `operational_hooks.php`'s existing after-insert handling on `raw_uploads`
  (which already runs automatically on every upload, splitting it into blocks) — this validation
  could piggyback on the same trigger point.
- **A staged, manually-triggered custom page**, matching the deliberate architectural choice
  already made for the main parser pipeline (`extract_standings.php`/`extract_games.php`/
  `extract_playbyplay.php` are explicitly staged and manually-triggered, *not* hooks — a real,
  documented decision, not an oversight).

Genuine tradeoff, not an obvious pick: a hook means this happens invisibly, every time, no
extra step for anyone; a staged page means an Administrator reviews before anything runs, more
in keeping with how the rest of the parsing pipeline was deliberately designed. Worth deciding
explicitly, not defaulting to whichever is less code.

### How does a mismatch actually get surfaced?
Nothing exists for this today. Options, not mutually exclusive:
- A plain table of pending-review items, browsable the same way `raw_uploads` itself already is
  via the Process Turns menu.
- A warning panel at upload-processing time, same visual pattern `extract_games.php` already
  uses for its "unresolved teams" message.
- Something else — this hasn't been designed, only the fact that surfacing (not silence) is
  required has been decided.

### The actual query/code
`security.md` gives the logic in prose (§2 above); no SQL or PHP implementing it exists yet.
Building block for step 2 above, as a starting point:
```sql
SELECT c.coach_id, c.name
FROM franchise_coach_tenures fct
JOIN coaches c ON c.coach_id = fct.coach_id
WHERE fct.franchise_id = :franchise_id AND fct.end_week_id IS NULL;
```
— then compare `c.name` against the uploader's resolved display name (same
first+last-then-username fallback pattern already established and working in `coach.php`'s
`resolve_display_name()` — reuse that function/logic rather than reimplementing name resolution
a second time).

---

## 4. Non-goals — explicitly out of scope, don't let this task grow into them

- **Not** about detecting or creating new `franchise_coach_tenures` rows. This task assumes the
  current open tenure already correctly identifies who's coaching a franchise — it links a
  login to that existing record, it doesn't manage coaching changes.
- **Not** fuzzy name-matching or normalization. The mismatch case is handled by surfacing for
  review, not by smarter matching — see §2.
- **Not** solving the residual non-admin-uploads-for-someone-else risk — accepted, see §2.
- **Not** building the registration page/flow itself, though it's a soft dependency (see §2) —
  confirm what actually exists in DaDaBIK before assuming a custom page is needed.

---

## 5. Suggested first steps for whoever picks this up

- [ ] Confirm what registration flow (if any) currently exists for new users in this DaDaBIK
      install, and whether the "register your name exactly as it appears" instruction has
      anywhere to actually go yet
- [ ] Decide hook vs. staged-page (§3) — probably the single biggest architectural call in this
      task, worth deciding deliberately before writing any code
- [ ] Decide how mismatches get surfaced (§3)
- [ ] Implement the validation-gate query/logic (§3), reusing `coach.php`'s
      `resolve_display_name()` rather than reimplementing name-fallback logic
- [ ] Test against a real mismatch case deliberately (e.g. upload as an Administrator for a
      franchise that isn't theirs) to confirm the gate actually rejects it and surfaces rather
      than silently doing nothing or silently updating
