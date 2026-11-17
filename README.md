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

`bin/build_from_v2.py` regenerates `public/data/*.tsv` from the OriDB v2 MySQL dump. The dump
is not in this repository. Checksums of the generated files are recorded in
`public/data/release.json` so a deposited copy can be proved identical to the served one.

## What is deliberately not here yet

- Lift-over of the *S. cerevisiae* coordinates from sacCer1 (2003) to sacCer3.
- The Likely and Dubious tiers, and the genome-wide datasets behind them.
- Genome-browser tracks (bigBed/bigWig) and a UCSC track hub.
- Any database. The trial is flat files on purpose.

## Licence

Code: MIT. Data: CC BY 4.0, with the original publications cited per record.
