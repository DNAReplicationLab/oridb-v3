# The OriDB B2 motif

How the B2-element motif shown on origin pages was derived, how each element is scored
against it, which records are deliberately left out of it, and how it compares with the
published figure it descends from.

Throughout, **B2** names both the element class and its matrix. The element layer also
holds **A elements**, which carry a separate matrix documented in
[`acs-motif.md`](acs-motif.md). The two matrices are different objects trained on
different records: a B2 score and an A score are not on the same scale and must not be
compared.

Everything here is produced by `bin/build_elements.py`; the numbers are read back from
`public/data/release.json` and `public/data/elements.tsv` after a build.

## What the B2 element is

B2 is a distinct origin element 3' of the A element, reported as a class of its own by the
study it comes from. It is not described by the ACS motif, so scoring a B2 against the ACS
matrix would be meaningless; it gets its own.

## The training set

The matrix is trained on the **303 B2 elements of Reuter et al. 2024
(PMID 39181881)** that can be placed in sacCer3 by their own sequence
(`reference/reuter2024_B2_elements.tsv`). These are genome-wide motif calls of one fixed
published width, so unlike a set of functionally defined footprints they already share a
frame, which is what makes a column-by-column matrix possible at all.

Confirmed B2 elements (`reference/literature_B_elements.tsv`) are **not** in the training
set. See *Why confirmed B2 elements are not aligned* below.

## The frame and the window

Every element is located in sacCer3 by its own sequence, then extended into the genome.
For B2 the extension is fixed rather than chosen: the window is the published interval plus
**two bases of genomic context upstream**, read on the element's own strand, giving a
**33 column** window in which the published interval occupies columns
3&ndash;33. The window is taken from the assembly and verified against the
sequence the study supplied rather than trusting the supplied string; a disagreement is
recorded on the record.

## The matrix

Each column is a base frequency with a quarter-count added, converted to log-odds against
the **sacCer3 base composition** (A 0.309, C 0.1917,
G 0.1913, T 0.308) rather than a uniform background. The
genome is 62% AT, so an all-A column carries about 1.70 bits here, not 2. The same measure
drives the reported total, the per-column table below and the letter heights in
`public/assets/b2_logo.svg`, so the logo and the numbers cannot drift apart.

- Consensus: `nnnnnnnnnnnnnnnnnnnnnnAAAWnWnnnnn`
- Total information: **9.24 bits** over 33 columns
- Conserved block: columns 23&ndash;28, an A-rich run reminiscent of a degenerate, inverted
  A element. The block is derived from the matrix at render time, not hard-coded, so a
  rebuild moves it.

Almost all of the information sits in that block; the 5' two-thirds of the window is close
to the genome's own composition, which is why the consensus is nearly all `n`.

## Scoring

An element's score is the log-odds sum of its whole window against the matrix, in bits,
reported with the percentage of the range the matrix can reach (worst possible window = 0%,
best = 100%). It measures match to the motif and nothing else: a high-scoring predicted
element has not been shown to do anything.

## Why confirmed B2 elements are not aligned

Nine confirmed B2 records and one B3 are carried from the literature. They are placed in
the assembly, drawn in the overview figure on the origin page and listed in the records
table, but they appear in **no alignment panel**, and the origin page now names them and
says so rather than leaving a silent gap.

The reason is the frame. A confirmed B2 is a functionally defined footprint, between 7 and
23 bp as published, and nothing in that definition fixes where it sits inside the 33 column
window above. Anchoring a 7 bp footprint at its own start would place its bases in columns
3&ndash;9, nowhere near the conserved block at 23&ndash;28, and would draw a confirmed
element as a poor match to a motif it was never framed against. Only 5 of the 9 overlap a
Reuter call that could lend them a frame, and one of those overlaps two, so borrowing a
frame is not available either. The alternative &mdash; sliding the matrix over each
footprint and keeping the best-scoring placement &mdash; chooses the alignment that
maximises its own score, and was rejected for that reason.

A B3 element has no matrix at all: it is an Abf1p-binding site, a protein recognition
sequence rather than a degenerate consensus.

## Known defect: a one-base off-by-one in the B2 intervals

**The matrix described above is built on a misregistered training set and will be
rebuilt.** The B2 intervals of Reuter et al. 2024 are 1-based inclusive and **32 bp**
wide; the ingest that produced `reference/reuter2024_B2_elements.tsv` read them as
half-open and added one to the start, storing a **31 bp** element. Dropping the first
base of a genomic interval removes the 5' flank of a plus-strand element but the 3' base
of a minus-strand one, so the two strands end up one base apart in any window anchored on
the element.

The evidence, measured on the conserved T of the B2 motif:

| frame | n | T at element position 26 | at 27 |
|---|---:|---:|---:|
| as stored, 31 bp, plus-strand calls | 161 | 0.78 | 0.11 |
| as stored, 31 bp, minus-strand calls | 142 | 0.04 | 0.70 |
| published interval read as 1-based 32 bp, plus | 154 | 0.01 | 0.77 |
| published interval read as 1-based 32 bp, minus | 138 | 0.03 | 0.69 |

Read as a 1-based inclusive 32-mer and reverse-complemented on the minus strand, the two
strands agree: the conserved T is at position 27 on both. The sequences the author
exported for the published figure are these 32-mers, and their coordinates resolve to
them directly in the UCSC browser.

Consequences, all of them fixable by correcting the interval at ingest:

- every B2 element in `elements.tsv` is stored one base short, missing the first base of
  the published interval (the 5' base on a plus-strand call, the 3' base on a minus-strand
  one);
- the matrix above is trained on two frames one base apart, which splits the conserved T
  between columns 28 and 29 (A 0.47 / T 0.43 where the true column is ~0.75 T) and costs
  about 1.3 bits;
- recomputed from sacCer3 on the corrected 32 bp intervals, the matrix comes to **10.88
  bits** over 32 columns from the 292 records whose published interval is 32 bp wide, with
  the T restored at column 27 (0.75 bits).

Eleven records need individual attention: ten carry a published interval of 31 bp and one
of 33 bp, and in the four of those that appear in the author's exported set the published
start is one base high, i.e. the same off-by-one applied a stage earlier.

## Comparison with the published figure

The B2 logo in Reuter et al. 2024 was made in WebLogo from the FIMO-identified sequences at
the class C1 origins of their Figure 2 &mdash; **58 sequences**, supplied by the author as
32-mers &mdash; with a yeast base composition as background. The two were checked against
each other directly:

- **Same records.** 55 of the 58 published sequences match a record in
  `reuter2024_B2_elements.tsv` at exactly the same coordinates and in the same orientation,
  base for base; all 58 are 32 bp and their headers are 1-based inclusive 32 bp intervals.
  The other 3 sit within a few bp of a record in the larger set.
- **The visible difference is the off-by-one above.** The published logo has a prominent T
  at its column 27 which the OriDB logo does not show. That is not a sampling effect: it is
  the conserved T split across two columns by the strand misregistration. Realigning the
  minus-strand calls by one base restores it (0.43 to 0.74 of records carrying T) and lifts
  the matrix from 8.76 to 10.11 bits over the 31 shared columns.
- **The residual difference is sample size.** Once realigned, the full set gives 10.11 bits
  against 10.70 for the 58 over the same columns. Drawing 58 of the 303 at random 200 times
  gives 9.83 bits on average (sd 0.48, range 8.42&ndash;11.08), so a set of 58 is expected
  to look sharper; the C1 subset sits at the sharp end of that distribution, consistent with
  C1 being the better-defined origin class.
- **The A elements are unaffected.** The Reuter A elements show no strand asymmetry: both
  strands peak at the same columns (5, 12, 13). The defect is specific to the B2 table.
- **Different palette.** OriDB draws A green, C blue, G orange, T red; the WebLogo default
  in the published figure draws A red and T green. The same column can therefore look quite
  different in the two pictures.

### Information by column

Both columns use the same measure, relative entropy against the sacCer3 base composition.
The OriDB column is the matrix **as currently built**, i.e. before the off-by-one above is
corrected; the published column is blank where the 32-mer does not reach.

| Column | Consensus | OriDB (n=303) | Published 58 |
|---:|:--:|---:|---:|
| 1 | n | 0.05 | &mdash; |
| 2 | n | 0.17 | 0.14 |
| 3 | n | 0.33 | 0.36 |
| 4 | n | 0.35 | 0.32 |
| 5 | n | 0.30 | 0.30 |
| 6 | n | 0.27 | 0.11 |
| 7 | n | 0.09 | 0.06 |
| 8 | n | 0.13 | 0.25 |
| 9 | n | 0.07 | 0.21 |
| 10 | n | 0.09 | 0.08 |
| 11 | n | 0.07 | 0.02 |
| 12 | n | 0.17 | 0.02 |
| 13 | n | 0.12 | 0.04 |
| 14 | n | 0.09 | 0.06 |
| 15 | n | 0.11 | 0.02 |
| 16 | n | 0.10 | 0.16 |
| 17 | n | 0.14 | 0.05 |
| 18 | n | 0.16 | 0.31 |
| 19 | n | 0.27 | 0.40 |
| 20 | n | 0.22 | 0.15 |
| 21 | n | 0.18 | 0.29 |
| 22 | n | 0.20 | 0.39 |
| 23 | A | 0.80 | 1.09 |
| 24 | A | 1.04 | 1.14 |
| 25 | A | 0.94 | 0.82 |
| 26 | W | 0.54 | 0.53 |
| 27 | n | 0.53 | 0.67 |
| 28 | W | 0.30 | 0.92 |
| 29 | n | 0.17 | 0.53 |
| 30 | n | 0.26 | 0.37 |
| 31 | n | 0.30 | 0.46 |
| 32 | n | 0.25 | 0.45 |
| 33 | n | 0.43 | 0.47 |
| **total** | | **9.24** | **11.18** |
