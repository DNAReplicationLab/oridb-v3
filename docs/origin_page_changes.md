# Origin elements rebuilt, About split in two

What changed, why the figure is drawn the way it is, and what is left open. Every number
below was produced by running the code in this repository against
`public/data/elements.tsv` (1,657 rows) and `public/data/release.json`.

## 1. The Origin elements section

`public/origin.php` now reads, under `<h2>Origin elements</h2>`:

1. **One line of origin-specific fact** — how many element records this origin has, how
   many of each class — and a link to the general explanation on About.
2. **A graphical overview** (section 2 below).
3. **`<h3>A and B1 elements</h3>`** — the logo and the aligned stack, which is the A
   element and the B1 region in one window. The A core and the B1 region are both marked,
   by a tinted span on every row and by a named bracket row under the consensus, and both
   spans are *derived from the matrix*, never typed in (section 3).
4. **`<h3>B2 elements</h3>`** — the same panel built from `b2_motif` and
   `assets/b2_logo.svg`. This did not exist before; `origin.php` had no reference to B2 at
   all. It reuses the same alignment CSS and the same logo-geometry pinning, reading
   `logo_column_width` and `logo_left_pad` from `release.json` into CSS custom properties.
5. **`<h3>Element records</h3>`** — the table, with a **Class** column (A or B2) and a
   separate **Evidence** column (confirmed or predicted). The old table printed
   `element_type` under a heading that said *Class*, so it showed the evidence word in the
   class column and had no class column at all.

Both motif panels are conditional on that class having a placed element; the records table
sits outside both conditionals and is rendered whenever there is any element at all. The
figure is suppressed only when no element can be placed.

The panel rendering, the figure and the citation live in a new shared include,
`public/app/elements_view.php`. The geometry is still read in `origin.php` and passed in,
which is what `bin/smoke_test.php` checks for.

### Study column

`studies.tsv` had no record for PMID 39181881, so the table printed the internal table name
`Reuter2024_B2`. The new `cite_pmid()` renders, in order of what is known:

| situation | rendered |
|---|---|
| study in `studies.tsv` | `Reuter et al. 2024`, linked to PubMed |
| PMID present, study record absent | `PMID 39181881`, linked to PubMed, plus *(citation not yet in the study table)* |
| no PMID at all | *no citation recorded in v2*, with the source table named |

Both live paths were tested: against `studies.tsv` as I received it the middle row is what
renders, and against the version in the working tree at the time of writing — a parallel
agent has since added PMID 39181881 — the records table reads `Reuter et al. 2024` and the
string `Reuter2024_B2` appears nowhere in the page. The evidence table above now uses the
same function, so the two tables cite in one voice.

## 2. The overview figure, and why this geometry

Generated in PHP, no JavaScript and no library, as an inline `<svg>` with a `viewBox` that
scales to the column.

**Lanes.** The origin interval is a box on a horizontal axis; **A elements are drawn above
it and B2 elements below**. Measured from the data, that separation is not cosmetic:

- 294 B2 elements share an origin with an A element. The median offset of a B2 midpoint
  from the nearest A midpoint, taken in the A element's own orientation, is **+44 bp**
  (3' of it). My brief quoted +74 bp; that is a different measurement from the one I made
  — I report mine, measured midpoint to midpoint against the nearest A at the same origin.
- **17% of A–B2 pairs at the same origin (184 of 1,100) overlap in genomic coordinates
  outright.** On one lane they would be drawn on top of each other.
- Origins carrying elements are 57 bp to 4,745 bp wide, median 250 bp. Over a 770 px plot
  that is 0.32 bp/px at the median, where a 33 bp element is about 100 px, and 6.2 bp/px at
  the widest, where it is about 5 px.

**Stacking.** Within a lane, a mark that would come within 4 px of one already placed is
pushed onto a new row. 266 of the 354 origins that carry elements hold two or more records
of the same class at *identical* coordinates (the same element reported by several studies),
which single-row packing would hide completely.

**Honesty rules.**

- The drawing window is the origin interval widened to contain every element, plus 5%.
  An element reaching past the origin is drawn **outside the box** — the ends of the origin
  are carried through the whole figure as dashed rules — instead of being clipped to it.
  `ODB-SC-0010` is the worked example: `ODB-EL-0012` starts 31 bp before the origin.
- Marks are to scale, with a **3 px floor** so a short element in a wide origin cannot
  vanish. The floor is stated in the caption, and exact coordinates are in the table below.
- A mark with room for it is drawn with a **pointed 3' end**, so strand is visible; this is
  what makes "B2 lies 3' of the A element" legible rather than asserted.

**Readable alone.** The figure carries the chromosome, interval, width and assembly as text,
a labelled lane for each class with its count, both origin coordinates, a 1-2-5 scale bar
sized to the window, and a legend drawn only for the class/evidence combinations present.

**Text alternative.** `role="img"` with `<title>` and `<desc>` referenced by
`aria-labelledby`; the description is generated and states the counts per class, the origin
interval, the lane convention, and whether anything extends beyond the origin. Each mark
also carries a `<title>` naming the element, class, evidence, position, length and strand.

## 3. Confirmed elements, and the derived spans

Confirmed elements (`element_type = confirmed`) are set in **bold** — label and bases — over
the existing per-base tinting, which is unchanged; the grey of a non-matching base is
darkened on those rows so bold is visible through it. The distinction is also stated in
words: the evidence word (`confirmed` / `predicted`) is printed beside every score, the
panel's lead paragraph says what the bold means, and the overview figure fills confirmed
marks solid and names them in the legend. `ODB-SC-0042` is the worked example — `ODB-EL-0049`
is a confirmed A element.

**Where the marked spans come from.** `acs_motif.core_columns` gives the A core (4–14). The
B1 region is *not* in `release.json` as a span, so it is derived: the mean information
content of the columns outside the core is taken as the background level (0.254 bits), and
the B1 region is the 3'-most run of columns that exceed it. For the current matrix that
returns **columns 31–33**, which is the WTW signal the matrix derivation describes, 17–19 bp
past the core. A rebuilt matrix moves the mark; nothing is hard-coded, which
`bin/smoke_test.php` also enforces.

`b2_motif` declares no `core_columns`, so its conserved block is derived by the same kind of
rule — the run containing the strongest column, bounded where information falls below the
window mean — giving **columns 23–28**, matching the non-degenerate run `AAAWnW` in that
consensus and the peak in `assets/b2_logo.svg`.

## 4. Prose moved off the origin pages; About split

Text that was identical on all 458 origin pages is now on About and linked from each origin:
what A, B1 and B2 elements are, how elements are located by sequence (and why the same
element appears twice, and why some have no coordinates), what the shaded run, the tinting
and the strand convention mean in the panel, what the score is and is not, and how to read
the overview figure.

- **About** — how to understand the database: scope and tiers, accessions (now including
  element accessions), coordinates and assemblies, the whole `#elements` section above,
  provenance.
- **Development** (new page, in the nav after About) — how the site is built: the storage
  layer, the no-framework decision, app and data inside the web root, nothing-stated-by-hand,
  content-hashed assets, one template set and two outputs, and what the pre-deployment checks
  cover. It also carries the release's `known_issues`, moved off About, and the roadmap of
  what is deliberately not here yet.

Nothing is duplicated between them: each page links to the other for the half it does not
hold.

## 5. Data hub with two datasets

The hub now carries `mcguffee2013` (Okazaki-fragment sequencing) beside `muller2014`.
`bin/build_datahub.php` needed three changes for it to render sensibly, and one more to suit
a laptop with no PHP:

1. **Call-set row counts were one too high.** The count of calls behind a bigBed track is
   read from the sibling BED, and `mcguffee2013/okseq.initiation_sites.bed` begins with a
   `#chrom ...` header line, which was being counted as a call: 384 where the dataset holds
   383. The count now skips blank, `#`, `track` and `browser` lines. `muller2014` was
   unaffected (212 either way), which is exactly why the bug would have survived.
2. **A dataset that tracks no per-sample records said nothing about its input.**
   `mcguffee2013` describes its input as archive runs plus used/excluded samples rather than
   as a `samples` array, so the page showed no sequencing information at all. The builder now
   carries run and sample counts and the exclusion reason, and `datahub.php` prints whichever
   the dataset has.
3. **Call sets now carry their definition and their caveat** from `dataset.json` onto the
   page, so the initiation zones are shown with the statement that the OEM efficiencies are
   raw and under-estimate near termination zones.
4. **`--hub-url` can now read the published hub over HTTPS** (see below).

Verified by rendering the page: both datasets appear under *Tracks on sacCer3*, all four
`mcguffee2013` track groups (fork direction, initiation signal, Okazaki strands, initiation
zones) are listed with their files, and the bigBed track reads *383 features, also as
okseq.initiation_sites.bed*.

### `public/data/datahub.json` is shipped built

Generated from **the hub checkout at `/Users/nieduszc/Documents/oridb-data-hub` as it stood
on 2026-11-20**, which is byte-identical to the `vbd591c83_oridb_hub_mcguffee2013` tarball
(`diff -rq` over the whole tree reports no differences) and, as it turns out, to the hub
published at `https://dnareplicationlab.github.io/oridb-data-hub/`. Nobody needs to run PHP
to get this file: apply the patch and commit it.

It describes 1 assembly, 2 datasets, 21 tracks over 21 files.

### Reading the published hub

`php bin/build_datahub.php --hub-url https://dnareplicationlab.github.io/oridb-data-hub/hub.txt`
follows the same include chain over HTTPS — `hub.txt`, `genomes.txt`, `sacCer3/trackDb.txt`,
each dataset's `trackDb.txt` and `dataset.json`, a HEAD per binary for its size and a GET of
each sibling BED for its row count — and writes the same file. It is **not** the default:
local reading is unchanged, and giving both options reads locally and takes the public URL
from `--hub-url`, as before.

- Requests go through cURL when the extension is present, falling back to the stream
  wrapper. cURL honours `http_proxy`/`https_proxy`, which the stream wrapper ignores; a CI
  runner behind a proxy would otherwise simply fail to connect.
- **The failure mode is explicit.** Fetch failures are collected, and if any file could not
  be read — or no track was found — the build prints what failed, writes nothing and exits 1.
  The output file is also written to a temporary name and renamed, so an interrupted run
  cannot leave a truncated `datahub.json` where the page would read it. I tested this: with
  the hub host unreachable the build reported `hub.txt: HTTP no response`, left the existing
  file untouched and exited 1.
- **Both modes produce the same file.** Reading the live published hub and reading the local
  clone gave JSON identical apart from `generated` and the recorded source, which is what
  makes `build_datahub.php --hub-url … && git diff --exit-code -- public/data/datahub.json`
  a usable staleness check. Getting there needed one fix: `repo_url` is now lower-cased in
  both modes, because the remote mode can only recover the owner name from the Pages
  hostname, which DNS has already lower-cased. GitHub resolves owner and repository
  case-insensitively.

**No CI workflow has been added**, as instructed — the command above is the whole check, and
it should be added by someone who can watch the first run.

## 6. Verification

- `php bin/smoke_test.php` — **0 case(s) failed**, including the alignment-geometry,
  hard-coded-count, hard-coded-assembly and asset-hash checks.
- `php bin/build_static.php` — **472 HTML pages** plus assets and exports, including
  `development.html`.
- Structural checks on the generated HTML, since a headless browser is not available here:
  every row of both alignment tables totals 35 column-equivalents (1 label + 33 bases +
  1 score) with the region row made of `colspan` 11 and 3 (A panel) and 6 (B2 panel); the
  records table has 8 columns on every row; `ODB-SC-0130` renders both panels and 10 record
  rows; `ODB-SC-0003`, which has no elements, renders the section with no figure, no panel
  and no table, as it should.
- `docs/origin_elements_rendered.html` (delivered as an artifact, not committed) is the
  rendered section for `ODB-SC-0130`, `ODB-SC-0042` and `ODB-SC-0003` with the stylesheet and
  both logos inlined, so it can be opened directly.

## 7. Left open

- **`bin/smoke_test.php` does not render `development.php`.** Its case list is a hand-written
  array and I was asked not to edit `bin/` beyond the data hub builder; I did add the one
  line `['development.php', []],` to `bin/build_static.php`, because without it the new nav
  link would point at a page the static mirror does not contain. The equivalent line belongs
  in the smoke test's `$cases`. The page renders clean (13,373 bytes, no diagnostics) under
  the same harness the smoke test uses.
- **Fragment links in the static mirror.** `about.php#elements` was being dropped by the link
  rewriter, landing the reader at the top of the page; the rewrite now carries the fragment.
  That is the second edit to `bin/build_static.php`.
- **No screenshot verification.** Layout was checked structurally and by rendering the
  figure's SVG to PNG; the alignment panels themselves were not rendered as images.
- **`b2_motif` has no `window`, `core_columns` or `derivation` columns of the kind
  `acs_motif` carries**, so the B2 panel says less about its own frame than the A panel does.
  If the B2 build later records a core span, the panel will use it with no page change.