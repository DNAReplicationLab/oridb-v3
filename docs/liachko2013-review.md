# Liachko et al. 2013 as a source of evidence for OriDB v3

A review of one paper against the database, what it supports, and what was done about it.
Written 21 November 2026 against commit `db489e8`. Every count below was computed from
the paper's supplementary tables and the built data files; the supporting tables are in
[`liachko2013/`](liachko2013/).

**Paper.** Liachko I, Youngblood RA, Keich U, Dunham MJ. High-resolution mapping,
characterization, and optimization of autonomously replicating sequences in yeast.
*Genome Res* 2013; 23:698-704. DOI [10.1101/gr.144659.112](https://doi.org/10.1101/gr.144659.112),
PMID 23241746, PMC3613586. Raw reads: SRA051407 (ARS-seq), SRA051408 (miniARS-seq),
SRA051406 (mutARS-seq).

**Verdict.** The paper supplies four separable kinds of evidence. It is also the only
independent experimental replication available for a large part of the ARS-assay layer,
and it contradicts 26 records that OriDB held as plain positives.

## Status

| Component | Status |
|---|---|
| Manual ARS assays (Table S3) | **Incorporated.** 160 assays in [`reference/curated_ars_assays.tsv`](../reference/curated_ars_assays.tsv); see section 1 |
| Assay strength | **Incorporated.** New `strength` column in `evidence.tsv`, filled for Liachko 2013 and for Xu 2006; see section 2 |
| ARS-seq contigs (Table S1) | Not yet. Ruling recorded: its own dimension in the tier system; see section 3 |
| miniARS-seq cores (Tables S2, S4) | Not yet; see section 4 |
| mutARS-seq of ARS1 | Not yet; see section 5 |
| Whether a Confirmed origin survives a failed re-test | **Deferred.** Both results are recorded and the site is flagged; see section 1 |
| The positive at chrXIV:443,299-443,974 | **Held back** until the locus is reviewed; no site created |

## What the paper contains

| Component | Supplement | Content | Paper's own error estimate |
|---|---|---|---|
| ARS-seq | Table S1 | 720 genomic fragments (median 702 bp) from a >12x four-cutter library, selected for plasmid replication; 366 contigs | about 12.5% false positive; 12.5-17% false negative |
| miniARS-seq | Table S2 | 12,338 sub-fragments of 100-200 bp, screened twice; 181 contigs, inferred cores of median 92 bp | 3.9% false positive (1.7% at read count >= 10) |
| Manual ARS assays | Table S3 | 160 individually cloned, Sanger-verified fragments, graded strong / weak / very weak / non-functional | individual assays |
| Combined table | Table S4 | 576 OriDB sites (351 confirmed, 225 likely, as of 2012) with contigs, cores, GIMSAN ACS matches and validation results | - |
| mutARS-seq | Figures only | all 300 single substitutions (plus deletions and doubles) across a 100 bp ARS1 fragment | two replicates, r = 0.92 |

All coordinates are sacCer1. Library DNA was S288C; the host was W303-1A; the vectors are
pRS406 derivatives with no centromere.

Re-assembling Tables S1 and S2 reproduces the paper: 366 ARS-seq contigs with the
published fragments-per-contig distribution (139, 121, 88, 15, 3) and 181 miniARS contigs.

## 1. Manual ARS assays (Table S3): incorporated

These are the same kind of experiment as the ARS-assay records OriDB v2 held, so they are
loaded the same way, by `bin/build_from_v2.py`, from
[`reference/curated_ars_assays.tsv`](../reference/curated_ars_assays.tsv). Of the 160,
88 are positive (47 strong, 18 weak, 23 very weak) and 72 are non-functional.

| What happened to it | assays |
|---|---:|
| Positive record added to a site that was already Confirmed | 76 |
| Positive that promoted a site to Confirmed | 11 |
| Positive held back, no site created | 1 |
| Negative on a fragment covering a whole site: recorded, ARS-negative flag set | 26 |
| Negative on part of a site: recorded, no flag | 10 |
| Negative at no site: published as an ARS-negative fragment | 36 |

Per-assay detail: [`liachko2013/manual_assays_vs_oridb.tsv`](liachko2013/manual_assays_vs_oridb.tsv).

### Effect on the tiers

| *S. cerevisiae* | Confirmed | Likely | Unsupported |
|---|---:|---:|---:|
| before | 413 | 40 | 386 |
| after | 424 | 35 | 380 |

Eleven sites became Confirmed. Any positive grade confirms, which is the paper's own
reading and matches the existing tier, where 22 Xu 2006 records graded weak or very weak
already confirm their sites.

| Site | Fragment (sacCer3) | Strength | Tier before | OriDB 2012 |
|---|---|---|---|---|
| ODB-SC-0021 | chrI:229,387-229,699 | strong | Unsupported | Likely |
| ODB-SC-0231 | chrV:316,199-317,313 | strong | Likely | Likely |
| ODB-SC-0269 | chrVII:693-757 | strong | Unsupported | Dubious |
| ODB-SC-0502 | chrXI:665,934-665,998 | weak | Unsupported | Likely |
| ODB-SC-0506 | chrXII:31,699-31,778 | strong | Likely | Dubious |
| ODB-SC-0512 | chrXII:139,565-139,947 | strong | Likely | Dubious |
| ODB-SC-0572 | chrXII:1,070,547-1,071,698 | strong | Unsupported | Likely |
| ODB-SC-0688 | chrXV:18,202-19,480 | strong | Likely | Likely |
| ODB-SC-0708 | chrXV:347,824-348,624 | weak | Likely | Likely |
| ODB-SC-0781 | chrXVI:317,114-317,612 | strong | Unsupported | Likely |
| ODB-SC-0791 | chrXVI:427,657-427,991 | strong | Unsupported | Likely |

No site was created or retired, no accession moved, and the evidence matrix is unchanged.

### Curator decisions recorded in the source file

- **chrV:6,377-6,453** (weak positive) lies 11 bp outside the interval of ARS503
  (ODB-SC-0212). Table S4 of the paper lists it as the miniARS-seq core of ARS503, so it is
  assigned to ARS503. The interval of ARS503 needs review: its functional core appears to
  lie just outside it.
- **chrIV:1,502,157-1,504,038** (strong) contains ARS447 and its ACS and touches the edge
  of ODB-SC-0205 by 147 bp. It is assigned to ARS447 only; ODB-SC-0205 is not promoted.
- **chrXVI:5,798-7,700** (strong) contains the ACS of ARS1601 and spans the locus of the
  novel site ODB-SC-0838. It is assigned to ARS1601 only.
- **chrXIV:443,299-443,974** (strong) is at no site, 5.3 kb from the nearest. It has no
  ARS-seq contig and no Ok-seq peak, and its best Foss MCM rank within 500 bp is 1,727.
  It is held back: no site, no accession. It is listed in `release.json` under
  `known_issues` so that it is not forgotten.

### Conflict with the Confirmed tier

67 of the 160 fragments re-test the identical fragment that OriDB records as a positive
ARS assay (the same sacCer1 coordinates). Outcome: 26 non-functional, 20 very weak,
12 weak, 9 strong. This is the paper's own account of the 67 OriDB-confirmed ARSs that
ARS-seq missed.

By source of the earlier positive record. This table counts evidence records, not
fragments: there are 435 positive ARS-assay records from v2, and the 67 re-tested
fragments appear in it as 68 records, because one fragment (chrVI:18,682-19,864, very
weak on re-test) is recorded against two origins, ARS600.3 and ARS600.4. That is why the
very weak row totals 21 where the fragment count above is 20.

| Liachko 2013 outcome | Nieduszynski 2006 | Mueller & Nieduszynski 2012 | Xu 2006 | uncited | other | all |
|---|---:|---:|---:|---:|---:|---:|
| ARS-seq contig overlaps | 189 | 30 | 13 | 15 | 68 | 315 |
| ARS-seq contig, manually negative | 2 | 0 | 0 | 0 | 2 | 4 |
| overlapping fragment positive | 2 | 0 | 0 | 0 | 0 | 2 |
| identical fragment: strong | 4 | 0 | 1 | 1 | 3 | 9 |
| identical fragment: weak | 5 | 0 | 2 | 0 | 5 | 12 |
| identical fragment: very weak | 3 | 0 | 8 | 0 | 10 | 21 |
| identical fragment: non-functional | 0 | 0 | 15 | 5 | 6 | 26 |
| not covered | 0 | 32 | 5 | 0 | 9 | 46 |
| total | 205 | 62 | 44 | 21 | 103 | 435 |

Per-record detail: [`liachko2013/v2_ars_records_replication.tsv`](liachko2013/v2_ars_records_replication.tsv).

- **Nieduszynski 2006 is independently reproduced almost completely**: 203 of 205 records
  are covered by an ARS-seq contig that was not manually refuted, or by a positive manual
  assay, and none failed an identical-fragment re-test. This is replication of the result.
  It is not a check of the OriDB records against the 2006 supplementary table, which
  remains outstanding.
- **Mueller & Nieduszynski 2012 is only half covered**: 30 of 62 records overlap an
  ARS-seq contig, 32 are not covered, and none was re-tested.
- **Xu 2006 (PMID 17067396) is where the disagreement concentrates**: of 26 records
  re-tested, 15 were non-functional and 8 very weak. Section 2 shows why this is less
  stark than it looks.

26 Confirmed origins now carry a Liachko negative on a fragment covering the whole site.
For 25 of them the single earlier positive is the only basis for Confirmed (no 2D gel, no
second positive, no ARS-seq contig). **13 of those also have no support from any
tier-counting dataset**: ARS107.5, ARS113, ARS207.1, ARS413.5, ARS427.5, ARS502, ARS504, ARS511.5, ARS519, ARS711, ARS911.5, ARS1009.5, ARS1018.5.

**The tier is unchanged for all 26.** Both results are recorded, the site carries the
ARS-negative flag, and the question of whether such a site should remain Confirmed is
deferred. The negative is not decisive on its own: the assay differs from the original
ones (non-centromeric vector, W303 host, 3-day colony formation), and 32 of the 41
positive re-tests were themselves only weak or very weak. The list is
[`liachko2013/retested_confirmed_origins.tsv`](liachko2013/retested_confirmed_origins.tsv)
and in `release.json` under `curated_assays.sites_retested_negative`.

A negative flags a site only when the fragment tested covers the whole site interval.
Ten negatives cover part of a site (a 32 bp ACS-only construct inside ARS913, 158 bp
inside an 8 kb interval, and so on). They are recorded against the site, marked as
partial, and do not flag it: a failed sub-fragment is not a failed origin. Negatives
carried from v2 flag on any overlap, as before.

## 2. Assay strength: incorporated

An ARS assay does not only come out yes or no. Liachko 2013 grades function as strong,
weak or very weak, and Xu et al. 2006 (*BMC Genomics* 7:276, PMID 17067396) graded theirs
"+" (robust colonies in two days), "weak" (small) or "very weak" (pinpoint) in Additional
file 10. OriDB v2 stored every Xu record as "+".

`evidence.tsv` now has a `strength` column with the values `strong`, `weak` and
`very weak`. It is filled for 133 records: the 91 Liachko positives, and 42 of the 44 Xu
2006 records (20 strong, 14 weak, 8 very weak), supplied by
[`reference/ars_assay_strength.tsv`](../reference/ars_assay_strength.tsv). Xu's "+" is
stored as `strong`; the file keeps the original wording. Blank means the study reported
no grade. The two Xu-cited records with no row in Additional file 10 are ARS105
(ODB-SC-0007) and ARS404 (ODB-SC-0121).

The grade explains much of the disagreement. For the 26 Xu records re-tested on the
identical fragment:

| Xu 2006 grade | Liachko: strong | weak | very weak | non-functional | total |
|---|---:|---:|---:|---:|---:|
| strong ("+") | 1 | 1 | 3 | 4 | 9 |
| weak | 0 | 1 | 3 | 7 | 11 |
| very weak | 0 | 0 | 2 | 4 | 6 |

Eleven of the 15 Xu records that Liachko found non-functional had been graded weak or
very weak by Xu. (Xu also scored the ARS207.1 fragment "+"; OriDB cites that record to
PMID 16965179, and Liachko found it non-functional.)

## 3. ARS-seq contigs (Table S1): not yet incorporated

A pooled screen with a roughly 12.5% false-positive rate should not confirm a site on its
own. **Ruling (C.A.N., 21 Nov 2026):** ARS-seq is to count as its own dimension in the
tier system, a high-throughput ARS assay. It ranks below a manual ARS assay, alongside
the other modern assays, and is most analogous to a licensing assay. In the registry that
means a new `measures` value and a dataset that counts towards the tier.

Overlap of the 366 contigs with the sites as they now stand (any overlap, no window):

| Tier of best overlapping site | contigs | manually positive | manually negative | untested | with miniARS contig |
|---|---:|---:|---:|---:|---:|
| Confirmed | 303 | 42 | 4 | 257 | 166 |
| Likely | 6 | 0 | 0 | 6 | 3 |
| Unsupported | 14 | 0 | 3 | 11 | 6 |
| no site | 43 | 0 | 32 | 11 | 1 |

- 309 of 424 Confirmed sites (73%) overlap a contig, against 17 of 35 Likely and 20 of
  380 Unsupported. The screen knew nothing of OriDB, so this is an independent ordering of
  the tiers.
- The 43 contigs at no site are almost all the paper's own false positives: 32 verified
  non-functional, 11 untested. The dataset should be loaded with the validation applied
  (the 39 manually negative contigs dropped) and must not mint sites.
- 4 of the 10 novel sites overlap a contig: ODB-SC-0831, 0834 and 0835 (Likely) and
  ODB-SC-0838 (Unsupported).
- **Projected tier effect**, if a contig counts as a licensing-kind dataset: 8
  Unsupported sites that already have one other counting dataset would become Likely.
  A further 9 Unsupported sites have a contig and nothing else. This is a projection
  from overlap alone; no support window has been calibrated for ARS-seq.

Per-contig detail: [`liachko2013/arsseq_contigs_vs_oridb.tsv`](liachko2013/arsseq_contigs_vs_oridb.tsv).

## 4. miniARS-seq cores (Tables S2, S4): not yet incorporated

172 OriDB sites in Table S4 have an inferred core (median 91.5 bp after lifting), against
a median Confirmed interval of 295.5 bp. Checked against the element layer at the 171
origins that have both a core and an A element:

| A-element source | inside the core | of | % |
|---|---:|---:|---:|
| sc_confirmed_ACS (confirmed) | 36 | 37 | 97.3 |
| Eaton2010_ACS (predicted) | 145 | 153 | 94.8 |
| Nieduszynski2006_ACS (predicted) | 103 | 107 | 96.3 |
| Reuter2024_A (predicted) | 149 | 157 | 94.9 |
| Xu2006_ACS (predicted) | 138 | 165 | 83.6 |

The core contains at least one listed A element at 169 of 171 origins. Where an origin
lists A elements at more than one distinct locus (41 origins), the core contains exactly
one of them at 24. The cores are therefore an experimental filter on the predicted
elements and an independent check on the confirmed ones. They would sit naturally as a
new element class, or as two columns on the origin.

The paper's finding that essential flanking sequence lies 3' of the T-rich strand of the
ACS is the plasmid-level counterpart of the B-element layer.

## 5. mutARS-seq of ARS1: not yet incorporated

The 100 bp fragment is chr4:462,510-462,609 in sacCer1, which lifts to
chrIV:462,514-462,613. The paper numbers it along the T-rich strand, so position p is
chrIV:(462,614 - p). Checked against the stored element sequences: the 33 bp A-element
records (ODB-EL-0185, 0186, 1145; chrIV:462,577-462,609, minus strand) are exactly
positions 5-37, the paper's extended ACS, with A at position 5 and G at position 6 as the
paper describes.

| Paper element | positions | sacCer3 | OriDB record |
|---|---|---|---|
| core ACS (17 bp) | 5-21 | chrIV:462,593-462,609 | contains ODB-EL-0187 (462,596-462,606) |
| B1 (TTT) | 35-37 | chrIV:462,577-462,579 | none; there is no B1 class |
| B2 | 67-77 | chrIV:462,537-462,547 | identical to ODB-EL-1665 |

ODB-EL-1665 was placed by reading a 1992 figure, and an unrelated saturation-mutagenesis
experiment lands on the same 11 bases. The two substitutions the paper reports as
beneficial (positions 69 and 72) are the two mismatches of the stored sequence
AATACTTAAAT to the reverse-complement ACS consensus.

Usable now: a citation on ODB-EL-0187 and ODB-EL-1665, and a first B1 record. Not usable
yet: the per-substitution enrichment values, which are in no supplementary table. They
would have to be recomputed from SRA051406 or requested from the authors.

## 6. Not recommended for import

- **The GIMSAN ACS17/ACS33 matches in Table S4.** The matrices were trained on 334 OriDB
  confirmed ARSs, so they are circular with respect to OriDB, and the element layer
  already carries four predicted A-element sources.
- **ARS1max and the ARS1 2D gels.** These describe a synthetic allele, not a genomic
  origin.

## Limits of this review

- "Overlap" for the ARS-seq and miniARS-seq comparisons means any shared base with the
  site interval, on coordinates lifted by end points with the repository chain file. The
  manual assays are lifted by the pipeline itself, with its base-for-base sequence check:
  118 of the 128 attached records are identical in sacCer3, 5 have a substitution and 5
  change length.
- The Supplemental Figures PDF was not inspected. The main text, the Supplemental
  Analysis and Tables S1-S5 were.
- Strength is recorded only where a study's own table gives it per fragment. Other
  studies may report grades in their text; none has been looked for.

## Files

- [`../reference/curated_ars_assays.tsv`](../reference/curated_ars_assays.tsv) - the 160 assays as published, with any explicit assignment, hold and curator note. This is the source the build reads.
- [`../reference/ars_assay_strength.tsv`](../reference/ars_assay_strength.tsv) - the Xu 2006 grades for 42 v2 records.
- [`liachko2013/manual_assays_vs_oridb.tsv`](liachko2013/manual_assays_vs_oridb.tsv) - each assay with its site, the tier before and after, and what was done with it.
- [`liachko2013/retested_confirmed_origins.tsv`](liachko2013/retested_confirmed_origins.tsv) - the 26 Confirmed origins that failed a whole-site re-test, with their other support.
- [`liachko2013/v2_ars_records_replication.tsv`](liachko2013/v2_ars_records_replication.tsv) - every positive ARS-assay record from v2 with its Liachko 2013 outcome.
- [`liachko2013/arsseq_contigs_vs_oridb.tsv`](liachko2013/arsseq_contigs_vs_oridb.tsv) - the 366 ARS-seq contigs with cores, read depth, validation status and site.
