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
