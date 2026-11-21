# Reference files

`sacCer1ToSacCer3.over.chain.gz`
: UCSC liftOver chain, sacCer1 (SGD Oct 2003) to sacCer3 (SGD R64, Apr 2011).
  Source: <https://hgdownload.soe.ucsc.edu/goldenPath/sacCer1/liftOver/sacCer1ToSacCer3.over.chain.gz>
  MD5 `c3aa03bde2422b6c083f478ac42d4cdc`. Committed so that `bin/lift_to_saccer3.py` is reproducible offline.

The chain declares sacCer1 chromosomes as `chr1`..`chr16` and sacCer3 as `chrI`..`chrXVI`.
Both naming schemes are UCSC's own for those assemblies; the change of convention between
the two builds is why the released data records `source_chromosome` separately.

Assembly FASTA files are not committed. To re-run the sequence verification, fetch
`https://hgdownload.soe.ucsc.edu/goldenPath/sacCer3/bigZips/chromFa.tar.gz` and pass the
unpacked directory to `--sacCer3`; the sacCer1 sequences ship with the OriDB v2 archive
(`cerevisiae/fasta/chr*.fa`) and match the chain's declared source lengths on all 16
nuclear chromosomes.

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
