# The OriDB ACS motif

How the ARS consensus sequence (ACS) motif shown on origin pages was derived, how each
element is scored against it, and which records are flagged for curation.

Throughout, **ACS** means the motif &mdash; the matrix and the score columns derived from
it. An **A element** is the record in the database: a functionally validated element
required for ARS activity, which is scored against the ACS but is not the same thing as a
match to it. The element layer also holds **B2 elements**, which carry their own matrix.

Everything here is produced by `bin/build_elements.py`; the numbers below are read back
from `public/data/release.json` and `public/data/elements.tsv` after a build.

## Why a matrix was needed

OriDB v2 stored each A element as a short string (10, 11 or 15 bp) plus an integer
`offset`, and displayed an alignment. The strings alone are too short to show the motif:
the 11 bp consensus `WTTTAYRTTTW` is a weak match criterion, and nothing in the stored
data says where the core sits inside a 15 bp string. The matrix is derived instead from
genomic sequence, with the stored strings used only to locate each element.

## Anchoring and the frame

Every element is located in sacCer3 by its own sequence, then extended into the genome
either side of the core. Placing the core inside the stored string needs one number, the
v2 `offset`, which is a coordinate in the alignment frame of Figure 6 of Chang et al.
(PMID 18573888), where every stored element begins at position -9. So

    core index inside the stored string = 9 - offset

The 68 confirmed elements carry a per-record `offset`; the three study-level tables carry
one each: Nieduszynski2006_ACS 7, Eaton2010_ACS 6, Xu2006_ACS 6. Under their declared
offsets the Eaton and Xu 33-mers occupy exactly the window derived below, which is an
independent check that the frame is the same one in all four studies.

## A corrected offset in four records

Four `sc_confirmed_ACS` records (source ids 60, 63, 65, 66) carried `offset` 8 where the
rest of their study carries 7, putting their core one base off. All four reproduce their
Nieduszynski 2006 (PMID 16847347) table S1 proACS sequence and position exactly, so the
site is not in doubt — only the core position inside the 15-mer. Across all 228 table S1
proACS sequences the core starts at index 2 of the 15-mer — the 11 bp core with 2 bp of
flank either side — as the best or tied-best match to the consensus in 221 cases, scoring
9 or more of 11 in 218. Index 2 is `offset` 7. These four are therefore transcription
errors, and `OFFSET_CORRECTIONS` in `bin/build_elements.py` sets them to 7. The
uncorrected value stays in the `source_offset` column of `elements.tsv`, so the change is
visible and reversible.

| element | ARS | stored | corrected | core match | motif score |
|---|---|---:|---:|---|---|
| ODB-EL-0590 | ARS1021 | 8 | 7 | 6 &rarr; 7 | -16.72 &rarr; +14.66 |
| ODB-EL-0929 | ARS1526 | 8 | 7 | 8 &rarr; 9 | +8.51 &rarr; +7.46 |
| ODB-EL-0938 | ARS1529.5 | 8 | 7 | 7 &rarr; 9 | -3.52 &rarr; +20.69 |
| ODB-EL-1001 | ARS1626.5 | 8 | 7 | 6 &rarr; 11 | -8.50 &rarr; +21.03 |

A fifth record, ODB-EL-0417 (ARS731), was flagged alongside these but is correctly
placed: its `offset` is 7, its core sits at index 2 where table S1 puts it, and it is
simply a weak match to the ACS (7 of 11).

## Choosing the window

The window was not assumed. Information content was computed column by column over a wide
span around the core, from the confirmed elements extended into sacCer3. It is measured
against the sacCer3 base composition
(A 0.309, C 0.192, G 0.191, T 0.308),
not against a uniform background: the genome is 62% AT, so an all-T column carries
1.70 bits here rather than 2. Logo heights use the same measure.

| column | offset from core start | information (bits) |
|---:|---:|---:|
| 1 | -3 | 0.32 |
| 2 | -2 | 0.33 |
| 3 | -1 | 0.77 |
| 4 | +0 | 0.45 |
| 5 | +1 | 1.36 |
| 6 | +2 | 1.05 |
| 7 | +3 | 0.90 |
| 8 | +4 | 1.13 |
| 9 | +5 | 0.61 |
| 10 | +6 | 0.41 |
| 11 | +7 | 1.43 |
| 12 | +8 | 1.51 |
| 13 | +9 | 1.50 |
| 14 | +10 | 0.58 |
| 15 | +11 | 1.06 |
| 16 | +12 | 0.47 |
| 17 | +13 | 0.46 |
| 18 | +14 | 0.01 |
| 19 | +15 | 0.10 |
| 20 | +16 | 0.03 |
| 21 | +17 | 0.09 |
| 22 | +18 | 0.02 |
| 23 | +19 | 0.18 |
| 24 | +20 | 0.06 |
| 25 | +21 | 0.01 |
| 26 | +22 | 0.02 |
| 27 | +23 | 0.03 |
| 28 | +24 | 0.01 |
| 29 | +25 | 0.03 |
| 30 | +26 | 0.06 |
| 31 | +27 | 0.34 |
| 32 | +28 | 0.82 |
| 33 | +29 | 0.36 |

Three features decide the window:

1. background below column 1 (offset -4 and lower), so the motif starts at -3;
2. the 11 bp core at columns 4-14, peaking at columns 11-13;
3. a second, separate peak at columns 31-33 — 17 to 19 bp past the core — after a
   13 column trough that sits at background.

That second peak is the WTW motif reported by Chang et al., recovered here from the data
without being put in. Including it, the motif is 33 bp wide, running
-3 to +29 relative to the core, and carries 16.5 bits in
total (the column table above sums to the same figure, to rounding). Its consensus is

    WWWWTTTAYnTTTWKnnnnnnnnnnnnnnnWTn
       ^^^^^^^^^^^ core, reading WTTTAYnTTTW

## Training set

All 68 confirmed elements that can be placed in the assembly; none are
excluded. Dropping weak-scoring elements would bias the matrix towards its own consensus,
so every experimentally confirmed A element counts, including ODB-EL-0417 and ODB-EL-0108 —
the latter only 10 bp as stored, too short to carry a core by itself, but scorable
because the window is taken from the genome rather than from the stored string.

## Scoring

Each element's 33 bp window is scored as a log-odds sum against the matrix
(Laplace-smoothed frequencies over the background above), reported in bits as
`motif_score` and as a percentage of the achievable range for that matrix as
`motif_score_pct`. Scores order as the evidence does:

| class | n | median score |
|---|---:|---:|
| confirmed | 68 | 17.95 bits (90%) |
| predicted | 951 | 15.13 bits (88%) |

3 of 1,022 elements cannot be scored because they cannot be placed in sacCer3.

Elements are either `confirmed` (experimentally defined) or `predicted`. The predicted set
comes from three studies, published under their own names — proACS in PMID 16847347,
nimACS in PMID 17067396 — which are not reproduced as a status here; the study citation
carries that information.

## Alignment on the page

The logo is one shared SVG, written by the same build that writes the matrix, and the
element rows below it are laid out one logo column wide so that each base sits under its
own position in the motif. `logo_column_width` and `logo_left_pad` in `release.json` are
the single source of those numbers: `public/origin.php` reads them into CSS custom
properties rather than repeating them, and `bin/smoke_test.php` fails the build if the
page hard-codes a width or if the label column is narrower than the logo's left gutter.

Within each row the shaded run is the sequence as that study published it, which is 10 to
33 bp depending on the study; the rest of the row is genomic context added here to fill
the window. `published_start` and `published_end` in `elements.tsv` give that run as
1-based column numbers, and the build checks that the span reproduces the stored sequence
exactly for every scored element. The spans are: 10 bp at columns 4-13, 11 bp at 4-14,
15 bp at 2-16, 17 bp at 1-17 and 33 bp at 1-33; none falls outside the window. The 11 bp
core is marked on every row by a pair of vertical rules.

Stylesheet and logo are referenced through `Data::asset()`, which appends a short hash of
the file's contents to the URL. Without it a changed stylesheet or logo sits in browser
and edge caches while the HTML around it is current, which is exactly how the alignment
and the motif once appeared stale on the live site but correct on the static mirror. A
smoke test fails the build on any bare `assets/` reference.

## For curation

- ODB-EL-0417 — correctly placed, but a weak core (7 of 11).
- ODB-EL-0108 — stored as 10 bp, shorter than the core.
- ODB-EL-1020 — anchors ambiguously (chr7:999,470-999,485, inside ARS734).
- ODB-EL-1021, ODB-EL-1022 — cannot be placed. ODB-EL-1021 is a sacCer2 record whose
  flanks lift into ARS1006 but whose own start falls in an unalignable gap.
- The four corrected offsets above should be fixed at source if the v2 tables are ever
  reissued.
