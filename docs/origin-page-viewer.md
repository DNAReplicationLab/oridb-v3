# A genome-browser panel on the origin page, and how the page should be laid out

Research note, 21 Nov 2026. Two questions: what it would take to put a small JBrowse view
inside `origin.php`, and how the page should be organised so that 1,580 origin records
stay readable. Nothing here is implemented yet; every number below was measured, and the
measurement is named where it matters.

## 1. What the panel is for

The origin page already states conclusions — a tier, an efficiency, a replication time, a
set of elements. Each of those is a call made from a genome-wide profile. The panel's job
is to show **the data the call was made from, at this locus**, so that a reader can see
whether the Okazaki-fragment directionality really does cross zero here, and how far the
assigned call sits from the origin interval.

That makes it a *figure*, not a workspace. The scope that follows from it:

- one locus, framed on this origin, with the origin interval marked;
- a fixed, curated handful of tracks — the ones the page's numbers come from;
- no session, no saving, no track-hub browsing, no file opening;
- one obvious exit to a full browser (the UCSC hub link already on `datahub.php`) for
  anyone who wants more.

Anything beyond that belongs on the data-hub page, not on 1,580 copies of it.

## 2. Routes, with sizes measured rather than estimated

Sizes are of the file the browser would actually download, taken from the npm tarballs on
21 Nov 2026 (`gzip -6`, which is what a web server transfers):

| Route | Current version | Transfer | Build step | Notes |
|---|---|---|---|---|
| `@jbrowse/react-linear-genome-view2`, UMD script tag | 4.3.0 (2026-05-21) | **1.36 MB gz** (5.72 MB raw) | none | Bundle carries its own React; one `<script>` and a `<div>` |
| same, bundled into a React app | 4.3.0 | similar, tree-shaken a little | npm + bundler | Only worth it if the site gains a front-end build, which `docs/page-structure.md` rules out |
| `@jbrowse/react-app2` | 4.3.0 | larger (30 MB unpacked) | none/bundler | The whole app as a component — more than this page needs |
| `jbrowse-web` in an `<iframe>` | — | heavy, but off our page | deploy a JBrowse instance | Needs a hosted config and a second thing to maintain |
| `@jbrowse/img` static SVG | 4.3.0 (CLI, 50 kB) | ~tens of kB per page | render at build time | Zero client JS; produces a picture, not a view |
| `igv.js` | 3.8.10 (2026-10-14) | **0.43 MB gz** (1.50 MB raw) | none | Self-contained, no React, reads a UCSC hub by `hubURL` |

Both JBrowse and igv.js can consume a UCSC track hub directly — the string
`UCSCTrackHub` appears as a connection type in the JBrowse UMD bundle, and `hubURL`
appears in `igv.min.js` — so the hub we already publish can drive either.

**Recommendation: the JBrowse UMD script tag, loaded on demand**, which is the project's
stated direction and keeps one configuration vocabulary across the embedded panel, a
possible future full JBrowse instance, and plugins. The honest caveat is that igv.js is
three times lighter for this particular job, and if the panel ever feels slow on a phone
that is the fallback, not a redesign — both read the same bigWig/bigBed files from the
same hub.

### A version trap worth knowing before any code is written

The embedding tutorial on jbrowse.org currently documents a `<LinearGenomeView>` component
taking `assembly`, `tracks` and `view` props, and an imperative `createLinearGenomeView`.
Neither exists in the published `latest` (4.3.0): its exports are `createViewState` and
`JBrowseLinearGenomeView`, and `createLinearGenomeView` does not appear anywhere in the
4.3.0 UMD bundle. The documented API is the `next` tag, 5.0.0-beta.22. So: pin an exact
version, write against the API that version actually exports, and treat the website
examples as describing the next major release.

The 4.3.0 `createViewState` options are, in full: `assembly`, `tracks`, `internetAccounts`,
`aggregateTextSearchAdapters`, `configuration`, `plugins`, `location`, `highlight`,
`defaultSession`, `disableAddTracks`, `onChange`, `makeWorkerInstance`. Two of those matter
here — `highlight` takes a list of locstrings and marks them in the view, which is exactly
the "this is the origin" band, with no plugin needed; and `disableAddTracks` removes the
add-track route. The view itself supports `hideHeader`, `hideHeaderOverview`,
`hideNoTracksActive` and `trackSelectorType`, which is how the chrome is cut back to a
figure.

## 3. The real work is in the data layer, not the JavaScript

The embedding is a day. These are the gaps:

**3.1 No reference sequence anywhere.** Neither `oridb-v3` nor `oridb-data-hub` contains a
FASTA or a 2bit. A JBrowse assembly requires a sequence adapter. The cheapest fix that
serves everything at once is `sacCer3.2bit` (~3.8 MB) in the hub: JBrowse reads it with
`TwoBitAdapter`, igv.js with `twoBitURL`, and it costs nothing at page load because only
the requested range is fetched. A bgzipped FASTA with `.fai` and `.gzi` is the alternative.
Add a `refNameAliases` file at the same time (`chrI` ↔ `I` ↔ `NC_001133.9`), so that
coordinates from any source resolve.

**3.2 OriDB's own records are not tracks.** The panel is only *about this origin* if the
origin interval, its ACS/B2 elements and the ARS-negative fragments are drawn in it. Those
exist as TSVs and as `export.php` BED output; they need to be built into bigBeds — say
`oridb/origins.bb` (coloured by tier), `oridb/elements.bb`, `oridb/refuted.bb` — and added
to the hub by the existing `bin/make_trackhub.py` route. This is worth doing even if the
panel is never built: it is what a UCSC-hub user is missing today.

**3.3 Half the records have no tracks at all.** 839 origins are sacCer3; **741 are
*S. pombe* on ASM294v2**, an assembly the hub does not serve. The panel must therefore be
conditional, and its absence must be explained in one line rather than left as a blank
rectangle. Adding ASM294v2 to the hub (sequence + the OriDB feature tracks, even with no
quantitative data yet) would give pombe pages a view of the origin and its elements, which
is better than nothing.

**3.4 A curated track list per page, not the hub's whole list.** The hub currently holds
two datasets and will hold many; the default set on an origin page should be three or four
— fork directionality, Trep, initiation zones, plus the OriDB origins track — matching the
numbers the page reports. One generated config, built once into `public/data/jbrowse.json`
by the same script that builds `datahub.json`, keeps that list from being typed by hand.

**3.5 Hosting is already settled.** GitHub Pages serves the hub with byte ranges and
`access-control-allow-origin: *`, which is what an in-browser viewer needs; Zenodo does
not send CORS headers and is the archival layer only. The JBrowse bundle itself should be
self-hosted next to the site, not pulled from a CDN — `docs/page-structure.md` is explicit
that CDN dependencies are much of why the 2012 site rotted.

## 4. How the view gets framed

Origin intervals are narrow but not uniformly so: median **974 bp**, 90th percentile
7.0 kb, 99th 17.0 kb, maximum 38.6 kb; 95 of 1,580 are wider than 10 kb. A fixed ±5 kb
window would therefore be wrong for 16% of records. The rule that holds across the
distribution:

```
pad   = max(2000, width)          # at least 2 kb of flank, at least the origin's own width
start = max(1, origin.start - pad)
end   = min(chrom_end, origin.end + pad)
```

which gives ~5 kb of view for a typical origin and ~115 kb for the widest, both of which
read sensibly for 1–10 kb-resolution profiles. The origin interval itself goes into
`highlight` so it stays visible when the user pans away from it.

Sketch, against the 4.3.0 API:

```html
<div id="jb" data-loc="chrI:2,000..8,000" data-highlight="chrI:649..1791"></div>
<!-- loaded only when the panel is opened -->
<script src="/assets/jbrowse-lgv-4.3.0.umd.min.js"></script>
<script>
  const { createViewState, JBrowseLinearGenomeView } = JBrowseReactLinearGenomeView
  const state = createViewState({
    assembly,                      // from data/jbrowse.json
    tracks,                        // likewise
    location: el.dataset.loc,
    highlight: [el.dataset.highlight],
    disableAddTracks: true,
    defaultSession: { name: 'origin', view: { type: 'LinearGenomeView',
      hideHeader: false, hideHeaderOverview: true, tracks: defaults } },
  })
</script>
```

## 5. Loading behaviour

1.36 MB on every origin page view, for a figure most readers will not interact with, is not
acceptable on a page that currently ships one 7 kB stylesheet and no JavaScript. The panel
should render server-side as a labelled placeholder with a **Show the data here** button,
and the bundle should be fetched on that click (or on first scroll into view). Readers who
never press it pay nothing; the page keeps working with JavaScript off; `bin/build_static.php`
needs no change, since everything is client-side.

Three failure modes need a written-out behaviour rather than a spinner that never stops:
the bundle fails to load, the hub is unreachable, and the assembly has no tracks (every
pombe origin today). In each case the placeholder should say so and keep the UCSC link.

## 6. Page structure: where the overload actually is

The current page runs: identity and coordinates → tier and how it was derived → supporting
evidence table → replication dynamics (two values, with a paragraph of qualifications) →
origin elements (overview figure, A/B1 motif panel, B2 motif panel, records table) →
machine-readable links. The evidence table alone can run to dozens of rows, and the
elements section is three sub-sections deep. A browser panel adds a fourth heavyweight
block. It is a long page already.

### Against reinstating v2's tabs

v2 split the detail page into tabs. Four costs, all of which the v3 decisions already push
against:

1. **Content inside an inactive tab is invisible to find-in-page and to printing.** For a
   reference database whose users arrive looking for one fact, that is the wrong default.
2. **It does not save any loading.** In a static build every tab's HTML is in the page
   anyway; tabs only hide it. The one thing that *is* expensive — the browser bundle —
   is better handled by an explicit button than by a tab that may be clicked idly.
3. **Citability.** "Origin ODB-SC-0001, evidence tab" is only a reference if the tab has a
   URL. Tabs that do not change the address bar break deep links and the back button.
4. **It is another JavaScript dependency on a page that currently has none**, and v2's
   dependence on client-side machinery is much of why it is broken today.

### What to do instead

**A summary header that answers the common question without scrolling.** Accession, name
and synonyms, `chrI:649–1,791` with width, assembly, tier chip, ARS-negative badge where it
applies, firing efficiency, replication time, element count — as a compact key/value block.
Most visits end there, and today they do not: the reader has to assemble those facts from
four sections.

**One scrolling page below it, with a section nav.** A short list of in-page links
(Browser · Evidence · Dynamics · Elements · Data) pinned under the header gives the
navigation tabs give, while leaving everything findable, printable and linkable. Pure
anchors, no JavaScript.

**Collapse the long tails with `<details>`, not tabs.** Native HTML, no JavaScript, prints
and deep-links, and modern browsers expand a closed `<details>` when find-in-page matches
inside it (worth re-checking in Firefox before relying on it). The candidates: evidence
rows beyond the first eight; the element records table; the paragraph of qualifications
under replication dynamics; the machine-readable block. Each `<details>` summary should
carry the count, so a closed section still states how much it holds — "Supporting
evidence: 31 records".

**Keep per-origin prose to one line per section.** `docs/page-structure.md` already decided
that explanation lives on About rather than on 1,580 copies; the dynamics section is
currently the exception, with a long qualifications paragraph on every page. Moving the
general part to About and leaving the origin-specific sentence is most of the length
problem solved.

**Where the panel goes.** Directly under the summary header, before the evidence table:
position is the first thing a reader checks, and the panel is the one element that gives
context rather than detail. It does not replace the element overview SVG — that is a
zoomed, motif-level drawing of a few hundred bases and is server-rendered, free, and
useful without JavaScript. Two figures at two scales, with the panel labelled as the
genome-context one.

**If tabs are wanted anyway**, build them as progressive enhancement over this structure:
render every section in the HTML, give each an id, let the tab bar be a script that hides
the others and sets `location.hash`, and make sure `#evidence` opens the right tab on load.
Then the page degrades to the scrolling version with jump links when the script does not
run, and nothing becomes unfindable.

## 7. Suggested order, and what is worth deciding first

1. **Hub additions, useful on their own** — `sacCer3.2bit`, `refNameAliases`, and OriDB's
   origins / elements / refuted-fragment bigBeds. UCSC-hub users benefit immediately, with
   no JavaScript written. (~1 day)
2. **Page restructure, no viewer** — summary header, section nav, `<details>` on the long
   tails, qualifications moved to About. Independent of the browser decision and the
   larger readability win. (~1 day)
3. **Panel prototype on one page** — pinned 4.3.0 UMD, generated `jbrowse.json`, lazy load,
   placeholder and failure text. (~1–2 days)
4. **Roll out and measure** — page weight with and without the panel opened, on a phone.
5. **ASM294v2 in the hub**, so pombe pages get a panel too.

Open questions for the project lead:

- Is the panel meant to replace the outbound UCSC link on the origin page, or sit beside it?
- Self-host the 5.7 MB bundle in `oridb-v3` (versioned in Git, simplest) or in the hub repo
  alongside the data?
- Is a pombe panel showing only sequence and OriDB features worth having before any
  quantitative pombe dataset is loaded?
