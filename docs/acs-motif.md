# The OriDB ACS motif

How the ARS consensus sequence (ACS) motif shown on origin pages was derived, how each
element is scored against it, and which records are flagged for curation.

Everything here is produced by `bin/build_elements.py`; the numbers below are read back
from `public/data/release.json` and `public/data/elements.tsv` after a build.

## Why a matrix was needed

OriDB v2 stored each ACS element as a short string (10, 11 or 15 bp) plus an integer
`offset`, and displayed an alignment. The strings alone are too short to show the motif:
the 11 bp consensus `WTTTAYRTTTW` is a weak match criterion, and nothing in the stored
data says where the core sits inside a 15 bp string. The matrix is derived instead from
genomic sequence, with the stored strings used only to locate each element.

## Anchoring and the frame

Every element is located in sacCer3 by its own sequence, then extended into the genome
either side of the core. Placing the core inside the stored string needs one number, the
v2 `offset`, which is a coordinate in the alignment frame of Figure 6 of Chang, Theis and
Newlon (PMID 18573888), where every stored element begins at position -9. So

    core index inside the stored string = 9 - offset

For the 68 confirmed elements the `offset` is recorded per record and this rule agrees
with the best consensus match in 62 of them. The three study-level tables carry one
offset each: Nieduszynski2006_ACS 7, Eaton2010_ACS 6, Xu2006_ACS 6. Under their declared
offsets the Eaton and Xu 33-mers occupy exactly the window derived below, which is an
independent check that the frame is the same one in all four studies.

## Choosing the window

The window was not assumed. Information content was computed column by column over a wide
span around the core, from the confirmed elements extended into sacCer3, against a
background of the sacCer3 base composition
(A 0.309, C 0.192, G 0.191, T 0.308).

| column | offset from core start | information (bits) |
|---:|---:|---:|
| 1 | -3 | 0.33 |
| 2 | -2 | 0.31 |
| 3 | -1 | 0.88 |
| 4 | +0 | 0.53 |
| 5 | +1 | 1.50 |
| 6 | +2 | 1.07 |
| 7 | +3 | 1.01 |
| 8 | +4 | 1.21 |
| 9 | +5 | 0.73 |
| 10 | +6 | 0.43 |
| 11 | +7 | 1.58 |
| 12 | +8 | 1.59 |
| 13 | +9 | 1.58 |
| 14 | +10 | 0.71 |
| 15 | +11 | 1.09 |
| 16 | +12 | 0.46 |
| 17 | +13 | 0.49 |
| 18 | +14 | 0.01 |
| 19 | +15 | 0.09 |
| 20 | +16 | 0.04 |
| 21 | +17 | 0.08 |
| 22 | +18 | 0.03 |
| 23 | +19 | 0.23 |
| 24 | +20 | 0.08 |
| 25 | +21 | 0.02 |
| 26 | +22 | 0.02 |
| 27 | +23 | 0.03 |
| 28 | +24 | 0.01 |
| 29 | +25 | 0.04 |
| 30 | +26 | 0.06 |
| 31 | +27 | 0.33 |
| 32 | +28 | 0.82 |
| 33 | +29 | 0.32 |

Three features decide the window:

1. background below column 1 (offset -4 and lower), so the motif starts at -3;
2. the 11 bp core at columns 4-14, peaking at columns 11-13;
3. a second, separate peak at columns 31-33 — 17 to 19 bp past the core — after a
   13 column trough that sits at background.

That second peak is the WTW motif reported by Chang, Theis and Newlon, recovered here
from the data without being put in. Including it, the motif is 33 bp wide,
running -3 to +29 relative to the core, and carries
21.7 bits in total. Its consensus is

    WWWWTTTAYRTTTWKnnnnnnnnnnnnnnnWTn
       ^^^^^^^^^^^ core, reading WTTTAYRTTTW

## Training set

63 of the 68 confirmed elements. Excluded: ODB-EL-0417, ODB-EL-0590, ODB-EL-0929, ODB-EL-0938, ODB-EL-1001.
In each of those five the stored `offset` puts the core where the sequence does not
support it, and a shifted window scores far better (for ODB-EL-1001, 6 bits against 11).
All five are 15 bp records from the same study, PMID 16847347, which also contributes
records whose offsets are consistent — so the discrepancy is within one study, not
between studies. They are excluded from the matrix but still scored and displayed, so the
disagreement stays visible rather than being hidden or silently corrected.

Note that ODB-EL-0108 is only 10 bp as stored, too short to carry a core by itself; it is
in the training set because the window is taken from the genome, not from the stored
string.

## Scoring

Each element's 33 bp window is scored as a log-odds sum against the matrix
(Laplace-smoothed frequencies over the background above), reported in bits as
`motif_score` and as a percentage of the achievable range for that matrix as
`motif_score_pct`. Scores order as the evidence does:

| class | n | median score |
|---|---:|---:|
| confirmed | 68 | 17.55 bits (90%) |
| proposed | 195 | 15.86 bits (89%) |
| predicted | 756 | 14.86 bits (88%) |

3 of 1,022 elements cannot be scored because they cannot be placed in sacCer3.

## For curation

- ODB-EL-0417, ODB-EL-0590, ODB-EL-0929, ODB-EL-0938, ODB-EL-1001 — stored offset
  disagrees with the sequence; see above.
- ODB-EL-0108 — stored as 10 bp, shorter than the core.
- ODB-EL-1020 — anchors ambiguously (chr7:999,470-999,485, inside ARS734).
- ODB-EL-1021, ODB-EL-1022 — cannot be placed. ODB-EL-1021 is a sacCer2 record whose
  flanks lift into ARS1006 but whose own start falls in an unalignable gap.
