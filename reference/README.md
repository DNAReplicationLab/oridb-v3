# Reference files

`sacCer1ToSacCer3.over.chain.gz`
: UCSC liftOver chain, sacCer1 (SGD Oct 2003) to sacCer3 (SGD R64, Apr 2011).
  Source: <https://hgdownload.soe.ucsc.edu/goldenPath/sacCer1/liftOver/sacCer1ToSacCer3.over.chain.gz>
  MD5 `c3aa03bde2422b6c083f478ac42d4cdc`. Committed so that `bin/lift_to_saccer3.py` is reproducible offline.

The chain declares sacCer1 chromosomes as `chr1`..`chr16` and sacCer3 as `chrI`..`chrXVI`.
Both naming schemes are UCSC's own for those assemblies; the change of convention between
the two builds is why the released data records `source_chromosome` separately.

`v2/`
: A frozen extract of the eleven OriDB v2 tables the pipeline reads — 4,049 rows, about
  300 KB — with a manifest the build verifies on every run. This is what makes the
  repository self-contained: the 48 MB MySQL dump is held offline because it carries live
  v2 credentials, and is needed only to recreate the extract, with `bin/extract_v2.py`.
  Immutable; a correction to a v2 record belongs in an overlay table such as
  `curated_ars_assays.tsv`, not in these files. See `v2/README.md`.

`genomes.tsv`
: The length and the md5 of the UPPERCASED, newline-stripped sequence of every chromosome
  of sacCer1 and sacCer3 — that is, of exactly the string the build reads. The assemblies
  themselves are not committed: `bin/fetch_genomes.py` downloads them from UCSC bigZips
  into `genomes/` (git-ignored) on first use and checks them against this file, and
  `--check` verifies what is already on disk without downloading. Verifying the sequence
  rather than the archive is deliberate — it is indifferent to how UCSC packs the files
  and to soft-masking, and it still catches a truncated download, a wrong assembly or a
  chromosome silently swapped. The sacCer1 md5s were taken from the copy shipped in the
  OriDB v2 archive (`cerevisiae/fasta/chr*.fa`), which matches the chain's declared source
  lengths on all 16 nuclear chromosomes.

`curated_ars_assays.tsv`
: Individual ARS assays published after the OriDB v2 dump closed, one row per fragment
  tested, on the assembly they were published on (sacCer1). Read by `bin/build_from_v2.py`
  and attached to sites as the v2 records are. Columns: `source` and `source_id` (the study
  table and its row, which together name the record), `pmid`, `assembly`, `chromosome`,
  `start`, `end`, `result` (positive or negative), `strength` (strong, weak, very weak, or
  blank), `reported_as` (the study's own wording), `site` (optional accession the row is
  assigned to, overriding overlap), `hold` (`yes` keeps the row out of the release) and
  `note` (the reason for either). First source: `Liachko2013_S3`, the 160 manual assays in
  Supplemental Table 3 of Liachko et al. 2013 (PMID 23241746), in table order. See
  `docs/liachko2013-review.md`.

`ars_assay_strength.tsv`
: The grade a publication reported for an ARS assay that OriDB v2 stored only as "+".
  Keyed by the v2 table and row id; the position and PMID are repeated so the build can
  refuse an annotation that no longer matches its record. Current content: 42 records from
  Additional file 10 of Xu et al. 2006 (PMID 17067396), where "+" is stored as `strong`.

`pubmed_cache.tsv`
: Citation records keyed by PMID, read by `bin/build_from_v2.py`, doing two jobs. A row with
  every field **stands in** for a study v2 never held, because v2's cached PubMed table stops
  in 2012. A row carrying only a `doi` **supplements** the v2 record, filling that one field
  and leaving the rest alone — v2 stored no DOI for the pre-2002 literature, where the
  journals have since registered one. The build only ever fills an empty field; the cache
  never overwrites v2. The `source` column says where each row came from and how it was
  checked, so an added citation can be audited rather than trusted. Current content: 6 full
  records and 26 DOI-only rows.

`sgd_saccer3_ars_features.bed`
: The SGD replication-origin annotation OriDB's intervals are compared against, extracted
  from UCSC's `sgdOther` table for sacCer3 by taking every feature whose name begins with
  `ARS` (389 of them, retrieved 2026-11-22). Committed so that `bin/compare_sgd.py` is
  reproducible offline; refresh it from UCSC when SGD's annotation changes. BED, so 0-based
  half-open, where the released tables are 1-based and fully closed.
