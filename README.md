# OriDB v3.0 — trial release

`github.com/DNAReplicationLab/oridb-v3`

A rebuild of [oridb.org](http://oridb.org). This repository holds the **first increment**:
the *Confirmed* origin tier of *Saccharomyces cerevisiae* and *Schizosaccharomyces pombe*,
served by a small, dependency-free PHP application.

| | |
|---|---|
| Release | `3.0.0-trial.1` |
| Origins | 410 *S. cerevisiae* + 48 *S. pombe* = 458 |
| Evidence records | 536 individual assays |
| Source publications | 37 |
| Runtime | PHP 8.1+ — no database, no Composer, no JavaScript |

## Why start with the Confirmed tier

Every Confirmed origin in OriDB v2 is Confirmed because a **plasmid (ARS) assay** or a
**2D gel** put it there. That was verified against the v2 database, not assumed:
all 458 Confirmed origins overlap at least one such record, and only three non-Confirmed
origins do. The tier is therefore a self-contained, individually-cited dataset that can
move to v3.0 without touching the genome-wide mapping data, whose assemblies and
thresholds are a much larger problem.

## Layout

```
public/           web root — one PHP file per page, plus assets/ and api/
  app/            application code: bootstrap, Data accessor, page templates (HTTP-denied)
  data/           released flat files: origins.tsv, evidence.tsv, studies.tsv, release.json (HTTP-denied)
bin/              build and test scripts
reference/        the UCSC liftOver chain, committed so the lift is reproducible offline
docs/             page structure and deployment notes
build/            generated; not committed
```

`public/app/Data.php` is the only file that knows where the data physically lives. When
the MariaDB back end arrives, re-implement its methods against PDO; no page changes.

`app/` and `data/` sit *inside* the web root, each with a deny-all `.htaccess`. That is
deliberate: shared hosts commonly jail PHP to the document root, where files above it do
not merely fail a permission check but do not exist at all. Keeping everything inside the
web root and blocking HTTP access to it works on every host.

## Running it locally

```bash
php -S localhost:8080 -t public
php bin/smoke_test.php      # renders every page, fails on any PHP diagnostic
php bin/build_static.php    # renders the whole site to build/static/ as flat HTML
```

## Deployment

Putting this repository on GitHub, from a machine with nothing set up yet:
[`docs/github-setup.md`](docs/github-setup.md).

Serving it from the hosting account: [`docs/deployment-ukhost4u.md`](docs/deployment-ukhost4u.md). In short: a `v3`
subdomain whose document root points at this repository's `public/` directory, kept
up to date by cPanel's Git Version Control. `data/` and `src/` stay above the web root.

`build/static/` is a complete no-PHP mirror of the site, suitable for GitHub Pages
while the PHP version is under review. It is produced by the same templates, so the
preview cannot drift from the live site.

## Rebuilding the data

Two steps, in order. The dump is not in this repository.

```bash
python3 bin/build_from_v2.py /path/to/nieduszy_oridb.sql          # extract (sacCer1)
python3 bin/lift_to_saccer3.py --sacCer1 DIR --sacCer3 DIR        # lift to sacCer3
```

The **Data hub** page is built separately, because the hub is a different repository with
its own release cycle:

```bash
php bin/build_datahub.php --hub-root /path/to/oridb-data-hub
```

That reads the hub's own `hub.txt`, trackDb chain and per-dataset `dataset.json` and
writes `public/data/datahub.json`. The page states nothing about the hub that this file
does not carry, so a track added to the hub appears on the page at the next build and
nothing on the page can contradict what the hub serves.

`build_from_v2.py` extracts the v2 records, which are sacCer1. `lift_to_saccer3.py` rewrites
the cerevisiae coordinates onto sacCer3 using the committed UCSC chain, and — when both
assembly FASTA directories are supplied — checks every lifted interval base for base,
recording the outcome per record in `lift_status`. *S. pombe* is ASM294v2 already and is
passed through untouched. Checksums of the generated files are recorded in
`public/data/release.json` so a deposited copy can be proved identical to the served one.

## Coordinates

*S. cerevisiae* records are **sacCer3**, lifted from the sacCer1 coordinates OriDB v2 stored.
Every record keeps its original position in `source_chromosome` / `source_start` /
`source_end` and the result of a sequence check in `lift_status`: of 896 intervals (458
origins and 536 evidence records, less the 48 *S. pombe* origins and 50 pombe evidence rows
that need no lift), 845 are sequence-identical in sacCer3, 26 have substitutions only, and
25 contain an indel that changes the interval length. None failed to map.

Systematic names (`I-1`, `X-212`) are **not** recomputed. They are coordinate-derived labels
from the v2 build that appear in the literature, so they stay fixed; 12 of the 410 cerevisiae
names would otherwise have changed.

## What is deliberately not here yet

- The Likely and Dubious tiers, and the genome-wide datasets behind them.
- The origin records themselves as a genome-browser track (bigBed). The *data* hub
  exists — `oridb-data-hub`, a UCSC track hub of genome-wide replication profiles,
  described on the **Data hub** page — but OriDB's own origin intervals are not in it yet.
- Any database. The trial is flat files on purpose.

## Licence

Code: MIT. Data: CC BY 4.0, with the original publications cited per record.
