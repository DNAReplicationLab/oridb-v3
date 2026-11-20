# Page structure for the trial site

One file per page, each doing one thing. The structure is chosen so that adding the Likely
and Dubious tiers, and later the genome-wide datasets, extends it rather than replaces it.

## Pages

| URL | Purpose | Grows into |
|---|---|---|
| `index.php` | What the release is, counts, entry points | Unchanged; counts come from the data |
| `browse.php` | Filterable, paged origin table | Gains a status filter and a dataset filter |
| `origin.php?id=…` | One origin: coordinates, assembly, evidence table, elements (overview figure, motif panels, records) | Gains a genome-browser panel and per-dataset evidence |
| `search.php` | Name/accession search and region search | Gains fuzzy matching and a sequence search |
| `studies.php` | The source publications, with origin counts | Becomes the dataset registry view |
| `downloads.php` | Exports and release checksums | Gains track-hub and bulk-archive links |
| `datahub.php` | The UCSC track hub: how to load it, what it carries, where the data came from | Grows with the hub; the track list is generated from the hub repository, not written here |
| `about.php` | How to read the database: scope, accessions, coordinates, elements and motifs | Becomes the curation policy and the data dictionary |
| `development.php` | How the site is built and what is outstanding: mechanics, known issues, roadmap | Becomes the release notes |
| `export.php` | Generates BED / TSV / JSON on request | Gains GFF3 and bigBed |
| `api/origins.php` | JSON list endpoint with filters | Versioned under `/api/v1/` |
| `api/origin.php?id=…` | JSON record for one origin | Versioned under `/api/v1/` |
| `404.php` | Error page | Unchanged |

Shared includes live in `public/app/`: `bootstrap.php`, `Data.php`, `header.php`,
`footer.php`, and `elements_view.php` — the element overview figure, the motif/alignment
panel and the study citation, which the origin page and About both draw on.

**Explanation belongs on About, not on 458 copies of it.** Prose that is identical on every
origin page — what an A or B2 element is, how elements are located, what the motif panel
and the scores mean — lives on `about.php#elements` and is linked from each origin. The
origin page carries only what is needed to read *that* origin.

## Decisions worth keeping

**Stable accessions.** Origins are identified by `ODB-SC-0001` / `ODB-SP-0001`, minted over
the whole v2 set in genome order so that adding the Likely and Dubious tiers does not
renumber anything. v2 had no usable identifier: its systematic names are computed from
coordinates, change under lift-over for 27 of 828 cerevisiae origins, and are not unique
(`III-15` and `VI-33` each denote two origins). The v2 numeric id is kept on every record as
`legacy_id` so old links can be redirected.

**Assembly on every record.** Each origin carries its assembly explicitly. v2 did not, which
is how sacCer1 and sacCer2 coordinates came to coexist in one database undetected. The
external genome-browser link is built *from* that field, so it cannot point at the wrong
build — v2's detail pages linked to sacCer3 while holding sacCer1 coordinates.

**Evidence is a first-class table, not a status letter.** A status of "Confirmed" is a
conclusion; the assay records are the data. Each evidence row carries its own interval,
method, detail (plasmid result or restriction enzyme) and citation, so the status can be
recomputed when the rule changes rather than re-curated by hand.

**Application code and data live inside the web root, with HTTP access denied.**
`public/app/` and `public/data/` carry a deny-all `.htaccess` rather than sitting one
level above the document root. The tidier arrangement is to keep them outside it, but
shared hosts commonly jail PHP to the document root — on the OriDB host, `scandir()` of
the parent directory returns only `public`, so files above it do not exist for PHP at all,
`open_basedir` notwithstanding. A layout that cannot be deployed is not the better layout.
`bin/smoke_test.php` renders the home page with `open_basedir` confined to the web root so
that a reintroduced outside-the-root read fails in CI rather than on the server.

**One template set, two outputs.** `bin/build_static.php` renders the same PHP templates to
flat HTML, so a GitHub Pages preview and the live PHP site cannot disagree.

**No framework, no build step for the front end.** One stylesheet, no JavaScript. The 2012
site is unusable today largely because of its dependencies: a Flash shim, Highcharts 2.1.7,
jQuery from a CDN, IE conditional comments. Anything added later should be added because a
page needs it — a genome browser will need JavaScript; a table of 458 rows does not.

## Checks that run before deployment

`php bin/smoke_test.php` renders every page with normal and hostile parameters
(`<script>`, `' OR 1=1 --`, `../../etc/passwd`, negative page numbers) and fails on any PHP
diagnostic, empty response, or reflected unescaped HTML. v2's `search.php` interpolated
`$_GET` straight into SQL and echoed the result unescaped; this test exists so that class of
defect cannot reappear unnoticed.
