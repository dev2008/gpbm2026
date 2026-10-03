# Task: upload hygiene — rejected duplicates, temp files, and `.gitignore`

**Status:** Not started. Small, and mostly a decision rather than code.
**Raised:** Aug 2026, while committing the NFLAR 2034 re-parse.

---

## 1. What prompted it

The commit for the 2034 re-parse included, alongside the intended files:

- `dadabik_tmp_file_Game Report from Ab Initio Games NFLAR-MV, Turn 15_148.txt` — a temp file
  from the upload process, now in version control
- Three uploads of NFLAR-MV Turn 9 (`9_132`, `9_134`, `9_136`), of which two were correctly
  rejected as duplicates and one processed

The duplicate detection worked exactly as designed — `operational_hooks.php` hashes the content,
finds a match, marks the row `parse_status = 'duplicate'` and skips block-splitting. Nothing is
broken. But every rejected upload still leaves a file on disk, a row in `raw_uploads`, and now a
file in the repo.

## 2. Three separate questions

**(a) Should a rejected duplicate keep its uploaded file?**

The content is byte-identical to the file the original upload kept — that is precisely why it
was rejected — so the file is redundant by definition. Disk usage grows with every re-upload of
the same turn.

Argument for deleting: nothing references it, and the original is retained.
Argument against: DaDaBIK's delete behaviour is untested here, and if it removes the
`raw_uploads` row along with the file that is a different and worse proposition (see (b)).

**(b) The `raw_uploads` row should be KEPT regardless.**

It is the audit record that the detector fired. `9_134` being recorded as an identical
re-upload is genuinely useful — during the 2034 work it is what confirmed the detector was
working rather than leaving three mystery files unexplained. Deleting the row loses provenance
and could orphan `raw_upload_blocks` or `source_upload_id` references.

If DaDaBIK's delete does both together, that is the awkward case, and the safest possible test
subject is a duplicate row: no blocks, no `franchise_id`, nothing references it. If something
unexpected cascades, it cascades over nothing.

**(c) `dadabik_tmp_file_*` should almost certainly be gitignored.**

These are process artefacts, not data. They will accumulate. Check first whether anything reads
them after upload completes — if they are purely transient, `.gitignore` is enough; if they are
cleaned up on success and only survive an interrupted upload, that is worth knowing separately.

## 3. Suggested order

1. Add `dadabik_tmp_file_*` to `.gitignore` (cheap, no risk)
2. Test DaDaBIK's delete on a known-duplicate `raw_uploads` row and observe what it removes
3. Decide (a) on the evidence from (2)

## 4. Related

- `lessons.md` §22 covers what a delete does and does not cascade to — worth extending with
  whatever (2) finds
- The uploads directory is under `public_html/`. Worth confirming separately that turn files
  are not web-accessible; this task is about hygiene, not that, but they are adjacent enough
  to check while looking
