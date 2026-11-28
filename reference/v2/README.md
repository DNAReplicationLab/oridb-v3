# The frozen OriDB v2 extract

The eleven OriDB v2 tables the v3 pipeline reads, as TSV: 4,049 rows, about 300 KB.
Written once by [`bin/extract_v2.py`](../../bin/extract_v2.py) from the v2 MySQL dump,
and **immutable** thereafter. `manifest.json` records each file's md5, row count and
column list, along with the sha256 of the dump they came from; the build verifies every
file against it and stops on a mismatch.

| file | rows | what it holds |
|---|---|---|
| `sc_ori.tsv`, `sp_ori.tsv` | 829, 741 | the origin records themselves |
| `sc_cloned_ori.tsv` | 421 | plasmid (ARS) assay positives |
| `sc_cloned_no_ori.tsv` | 225 | cloned fragments that did not replicate |
| `sc_2D_gel.tsv`, `sp_2D_gel.tsv` | 49, 48 | 2D gel electrophoresis records |
| `local_pubmed.tsv` | 714 | v2's cached PubMed records, six columns kept |
| `sc_confirmed_ACS.tsv` | 68 | experimentally confirmed ACS elements |
| `Nieduszynski2006_ACS.tsv`, `Eaton2010_ACS.tsv`, `Xu2006_ACS.tsv` | 196, 253, 505 | predicted ACS element sets |

## Why the extract and not the dump

The dump is 48 MB, carries live v2 credentials, and cannot be committed — which meant a
clean clone could not be rebuilt by anyone who did not already have a copy. It is also a
fixed historical object: it will never gain a row. Freezing the part the build reads
makes the repository self-contained and loses nothing, because every released record
still names the v2 row it came from in its `source_table` and `source_id` columns.

The dump remains the archival source of record and is kept offline. Its sha256 is in
`manifest.json`, so a future curator can prove which dump this extract came from.

## What is preserved, exactly

The **raw** value of every field as the dump's `INSERT` statements carry it: MySQL
escape sequences unresolved, and the latin1-decoded bytes of the mis-encoded UTF-8 in
`local_pubmed` left as they are. `build_from_v2.unescape()` resolves both downstream and
must see the same strings it saw when reading the dump directly, or 81 author names and
titles would change. Row order is preserved too, because several downstream steps sort
stably and dump order is therefore visible in the released files.

`local_pubmed` is the one table trimmed, to the six columns the build reads
(`pubmed_id`, `doi`, `date`, `journal`, `author_list`, `article_title`). That takes it
from 600 KB to 127 KB; the dropped columns are abstract text, PMC id, volume, issue and
page, and `manifest.json` lists them.

## Correcting a v2 record

Not here. These files are what OriDB v2 held, and `v2_status`, the provenance block in
`release.json` and the `known_issues` list all depend on that still being true. A
correction goes in an overlay table under `reference/`, the way
`curated_ars_assays.tsv` and `ars_assay_strength.tsv` already work: the current belief
and the historical record then sit side by side and the difference between them is
visible rather than lost in a diff.

Editing a file here makes every build fail with an md5 mismatch until `manifest.json` is
regenerated — which is the point.
