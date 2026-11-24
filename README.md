# OriDB v3.0 — trial release

`github.com/DNAReplicationLab/oridb-v3`

A rebuild of [oridb.org](http://oridb.org). This repository is the whole of it: the released
data files, the code that generates them from the OriDB v2 dump, and the small
dependency-free PHP application that serves them. Trial site:
[v3.oridb.org](https://v3.oridb.org/).

| | |
|---|---|
| Release | `3.0.0-trial.1` |
| Origins | 839 *S. cerevisiae* + 741 *S. pombe* = 1,580 |
| Tiers | 472 Confirmed, 35 Likely, 380 Unsupported, 693 Not assessed |
| Evidence records | 822 individual assays |
| Origin elements | 1,667 — 1,354 A, 312 B2, 1 B3 |
| Source publications | 56 |
| Runtime | PHP 8.1+ — no database, no Composer; JavaScript only in the optional browser panel |

Every count above is generated rather than asserted: the pages read them from
`public/data/release.json`, and a page that hard-codes one fails the test suite.

## What v3 changes

**Every v2 origin is here**, with its accession, its identifiers and its evidence records.
Nothing was dropped and nothing was renumbered.

**The tiers are recomputed, not carried across.** The 2012 tiers were a judgement about the
literature of the time. v3 derives a tier at build time from `evidence_matrix.tsv` — one row
per site per dataset, supporting or not — using genome-wide datasets re-analysed on the
current assemblies. *Confirmed* still means direct experimental evidence at the site, an ARS
assay or a 2D gel, and no quantity of genome-wide data substitutes for it. *Likely* means two
or more registered datasets support the site and at least one of them measures licensing.
*Dubious* is gone: a site that current data do not support is *Unsupported*, which is a
statement about the evidence rather than about the site, and a species no registered dataset
covers is *Not assessed*. A negative ARS assay is a flag, never a tier — it describes the
fragment that was cloned, and a site can hold a positive and a negative result at once.

**Ten sites are new** (`ODB-SC-0830`–`ODB-SC-0839`): clusters of calls that two or more
datasets support, one of them licensing, matching no site carried from v2.

**Coordinates are the current assemblies.** *S. cerevisiae* records are lifted from sacCer1
to sacCer3 and checked base for base; *S. pombe* is ASM294v2 already. Every lifted record
keeps its original position and the outcome of that check.

**There is an element layer.** A, B2 and B3 elements, each located in the assembly by its own
stored sequence rather than by the coordinates v2 recorded, scored against a motif derived
from the confirmed elements, and drawn on the origin page.

**Two per-origin values** are published for each *S. cerevisiae* origin, a firing efficiency
from Ok-seq and a median replication time from sync-seq, attached only where the origin and
the call are each other's nearest and lie within that assay's measured positional error.

**The data are browsable.** `oridb-data-hub` is a UCSC track hub of the genome-wide profiles
behind the calls, with OriDB's own origins, elements and ARS-negative fragments rendered into
it as bigBed, and an origin page can draw that window inline.

## Layout

```
public/           web root — one PHP file per page, plus assets/ and api/
  app/            application code: bootstrap, Data accessor, page templates (HTTP-denied)
  data/           released flat files: the TSVs and release.json (HTTP-denied)
bin/              build and test scripts
reference/        the UCSC liftOver chain, the dataset registry, and curated source tables (see reference/README.md)
docs/             method notes, design notes and deployment notes
build/            generated; not committed
```

## Method notes

How each derived layer was produced, and what it does and does not claim:

- [`docs/acs-motif.md`](docs/acs-motif.md) — the ACS matrix: training set, window, scoring.
- [`docs/origin_activity_timing.md`](docs/origin_activity_timing.md) — per-origin Ok-seq
  activity and median replication time: the mutual-nearest rule, its distance caps, and
  why an empty value is not a zero.
- [`docs/tier-rebuild.md`](docs/tier-rebuild.md) — how every site is placed in a tier:
  the dataset registry, where the Foss rank cut came from, why refutation is a flag, and
  what changing a decision later would cost.
- [`docs/tier-evidence-proposal.md`](docs/tier-evidence-proposal.md) — the proposal the
  rebuild was argued from, kept for its evidence axes and its measured per-study resolution.
  **Superseded** by `tier-rebuild.md`: its fourth tier was *refuted*, where what shipped
  carries refutation as a flag and makes the fourth tier *not assessed*.
- [`docs/origin_page_changes.md`](docs/origin_page_changes.md) — the origin page layout.
- [`docs/origin-page-viewer.md`](docs/origin-page-viewer.md) — the design note the embedded
  JBrowse panel was built from, **now implemented**: what each route cost in bytes, and how
  the page is sectioned so that adding it did not make the page unreadable.
- [`docs/liachko2013-review.md`](docs/liachko2013-review.md) — Liachko et al. 2013 reviewed
  as a source of evidence: what was incorporated (manual ARS assays, assay strength) and
  what is still to come (ARS-seq, miniARS-seq cores, mutARS-seq).

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

Serving it from the hosting account:
[`docs/deployment-ukhost4u.md`](docs/deployment-ukhost4u.md). In short: a `v3` subdomain
whose document root points at this repository's `public/` directory, kept up to date by
cPanel's Git Version Control. HTTP access to `app/` and `data/` is denied by their own
`.htaccess`, so nothing has to live above the web root.

`build/static/` is a complete no-PHP mirror of the site, suitable for GitHub Pages
while the PHP version is under review. It is produced by the same templates, so the
preview cannot drift from the live site.

## Rebuilding the data

Five steps, in order. The dump is not in this repository. Each step silently produces a
complete-looking set of files from the wrong input, so run the script rather than the
individual commands:

```bash
bin/build_all.sh /path/to/nieduszy_oridb.sql SACCER1_FASTA_DIR SACCER3_FASTA_DIR
```

1. `build_from_v2.py` extracts the v2 records, which are sacCer1.
2. `lift_to_saccer3.py` rewrites the cerevisiae coordinates onto sacCer3 using the committed
   UCSC chain and — when both assembly FASTA directories are supplied — checks every lifted
   interval base for base, recording the outcome per record in `lift_status`. *S. pombe* is
   ASM294v2 already and is passed through untouched.
3. `build_elements.py` locates every element by its stored sequence, derives the motifs,
   scores each record, and assigns the per-origin activity and replication time. It then
   calls `build_origin_ranks.py`, which ranks each origin against the others carrying the
   same measurement; that script also runs on its own (`--check` reports stale ranks
   without writing), which is how the ranks are refreshed without rebuilding the element
   layer.
4. `build_tiers.py` evaluates every registered dataset against every site and derives the
   tier.
5. `compare_sgd.py` compares the Confirmed cerevisiae intervals with SGD's ARS features
   (`reference/sgd_saccer3_ars_features.bed`, a committed snapshot of UCSC's `sgdOther`) and
   rewrites that known issue with the numbers it measures. It runs last because the
   comparison is over the Confirmed tier, which step 4 decides.

Checksums of the generated files are recorded in `public/data/release.json`, so a deposited
copy can be proved identical to the served one.

The **Data hub** page is built separately, because the hub is a different repository with
its own release cycle:

```bash
php bin/build_datahub.php --hub-root /path/to/oridb-data-hub      # a checkout on disk
php bin/build_datahub.php --hub-url https://dnareplicationlab.github.io/oridb-data-hub/hub.txt
```

That reads the hub's own `hub.txt`, trackDb chain and per-dataset `dataset.json` and
writes `public/data/datahub.json`. The page states nothing about the hub that this file
does not carry, so a track added to the hub appears on the page at the next build and
nothing on the page can contradict what the hub serves.

Two more steps hang off the hub, both of which must be rerun when it changes:

```bash
php bin/build_jbrowse.php --hub-root /path/to/oridb-data-hub   # after build_datahub.php
python3 bin/build_oridb_tracks.py --assembly sacCer3 \
    --chrom-sizes /path/to/oridb-data-hub/sacCer3.chrom.sizes \
    --out /path/to/oridb-data-hub/sacCer3/oridb_v3                # needs bedToBigBed
```

`build_jbrowse.php` turns the hub description into `public/assets/jbrowse.json`, which the
browser panel on an origin page reads: the assemblies, their sequence files, every track,
and which handful to draw by default — the last taken from the hub's own *overview*
section rather than chosen here. How a profile is drawn is also the hub's decision, not
this repository's: `negateValues` turns the y-axis over so an early replication time reads
as a peak, the lower bound of `viewLimits` becomes the floor of that axis so a profile that
never approaches zero is not drawn from zero, and `graphTypeDefault points` turns the fill
off, leaving a trace rather than a block of colour. The upper bound is deliberately left to
autoscale, so a window still fills the panel. The fill is turned off on the XY plot rather
than by selecting JBrowse's `line` rendering, which in 4.3.0 ignores `inverted` and so
draws an upside-down profile against a correctly labelled axis. `build_oridb_tracks.py` goes the other way, rendering
this database's origins, elements and ARS-negative fragments as bigBed **into the hub**,
so the records can be drawn beside the data they were called from. Run it once per
assembly, then regenerate the hub's trackDb with the hub's own `bin/make_trackhub.py`.

The second form reads the **published** hub over HTTPS and needs no second checkout, so it
can run in CI as a staleness check — the same shape of check the hub repository runs on its
own `trackDb.txt`:

```bash
php bin/build_datahub.php --hub-url https://dnareplicationlab.github.io/oridb-data-hub/hub.txt \
  && git diff --exit-code -- public/data/datahub.json
```

Both forms produce the same file (`generated` and the recorded source aside). The network is
never the default, and a hub that cannot be read fails the build with a message and writes
nothing, so the page can never be served a half-described hub.

## Coordinates

Coordinates are 1-based and fully closed. *S. cerevisiae* records are **sacCer3**, lifted
from the sacCer1 coordinates OriDB v2 stored; *S. pombe* records are **ASM294v2** and need no
lift. Every lifted record keeps its original position in `source_chromosome` /
`source_start` / `source_end` and the result of a sequence check in `lift_status`: of the
1,897 intervals lifted — 829 origins, 772 evidence records and 296 ARS-negative fragments —
1,705 are sequence-identical in sacCer3, 84 have substitutions only, and 108 contain an indel
that changes the interval length. None failed to map. Where the length changed it is the 2003
assembly sequence that was revised rather than the mapping, and those records are worth a
curator review; they are published as a known issue.

Systematic names (`I-1`, `X-212`) are **not** recomputed. They are coordinate-derived labels
from the v2 build that appear in the literature, so they stay fixed, and a name need not match
the lifted midpoint.

## Comments and corrections

The point of a trial release is to be argued with, so the footer of every page points at
[Discussions](https://github.com/DNAReplicationLab/oridb-v3/discussions): a correction to a
record, an argument about a tier, or anything on the site that reads wrongly. A reviewer
needs a free GitHub account and nothing else — no clone, no checkout. Quote the accession
where there is one; the accession is permanent, the URL is not. A thread that turns out to
be a real correction converts to an issue from its own menu. Turning Discussions on is step
11 of [`docs/github-setup.md`](docs/github-setup.md), with the categories the footer assumes.

The questions a curator still has to answer are published as *known issues* on the
Development page, generated from `release.json`, so they can be checked rather than taken on
trust.

## What is deliberately not here yet

- **Genome-wide data for *S. pombe*.** Every registered dataset covers budding yeast, so
  every fission yeast site that is not Confirmed is *Not assessed*. Adding one fission yeast
  dataset is the change that would move the largest number of records.
- **Any database.** The trial is flat files on purpose: it keeps the release reproducible and
  checksummed while the schema is still moving.
- **Stable URLs.** Accessions are permanent; the paths that serve them are not settled.

## Licence

Code: MIT. Data: CC BY 4.0, with the original publications cited per record.
