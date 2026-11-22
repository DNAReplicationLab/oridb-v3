# OriDB v3 site review — 22 November 2026

Scope: every page in `public/` (live at <https://v3.oridb.org/>), the templates in
`public/app/`, `README.md` and the method notes in `docs/`, read against
`public/data/release.json`, `origins.tsv`, `elements.tsv`, `evidence.tsv`,
`studies.tsv` and the hub description in `datahub.json` / `assets/jbrowse.json`.

The live site was checked and is byte-for-byte the same release as the working copy
(`3.0.0-trial.1`, generated 2026-11-21), so everything below applies to both.

Reference values used throughout, all read from the release files:

| | |
|---|---|
| Origins | 1,580 — 839 *S. cerevisiae*, 741 *S. pombe* |
| Tiers | Confirmed 472, Not assessed 693, Unsupported 380, Likely 35 |
| Confirmed *S. cerevisiae* | 424 |
| Evidence records | 822 |
| Source publications | 56 |
| Elements | 1,667 — A 1,354, B2 312, B3 1 |
| Confirmed elements | 78 — A 68, B2 9, B3 1 |

---

## 1. Fixed

Seven files changed. `php bin/smoke_test.php` reports 0 failures (8 known issues, none
duplicated) and `php bin/build_static.php` writes 1,605 HTML pages.

1. **Element figure, lower lane label — `public/app/elements_view.php`.**
   The lane was labelled with the classes drawn in it, so it read `B2 (5)`, or
   `B2, B3 (3)` where a B3 is present, while the legend beneath the same figure said
   `B confirmed` / `B predicted`. The lane is now labelled by the side it draws, `B (5)`,
   matching the legend and the colour convention — colour and lane carry the side of the
   origin, the class comes from the tooltip and the records table. Verified on
   ODB-SC-0144 (ARS1, A 4 / B 3) and ODB-SC-0130 (A 5 / B 5).

2. **Development → "What is deliberately not here yet" — the Likely and Dubious tiers.**
   The page said both tiers "rest on genome-wide mapping data … and are being rebuilt".
   They have been: Likely is a live tier with 35 sites, and Dubious no longer exists as a
   tier at all — it survives only as the `v2_status` column (361 rows), which no page
   displays. Bullet removed and replaced with the gap that is real: no genome-wide dataset
   in the registry covers *S. pombe*, which is why 693 fission yeast sites read
   *not assessed*.

3. **Development → "OriDB's own origin intervals as a browser track".**
   The page said the origin records "are not in" the data hub. They are: the hub serves
   `sacCer3/oridb_v3/origins.bb`, `elements.bb` and `refuted_fragments.bb`, plus
   `ASM294v2/oridb_v3/origins.bb`, and both `origin.php` ("OriDB's records are also a
   browser track in the data hub") and the JBrowse panel on every origin page already rely
   on that. Bullet removed.

4. **Search → the "nothing found" note.**
   It said "The trial holds only the **Confirmed** tier: 1,580 origins, those supported by
   a plasmid (ARS) assay or a 2D gel. OriDB v2 also listed Likely and Dubious origins, and
   those are *not* here yet." Three errors in two sentences: the release is not
   Confirmed-only, 1,580 is the total and not the Confirmed count (472), and every v2 site
   is present. Rewritten to say what the release holds and how matching works.

5. **"A and B2 elements" → "A, B2 and B3"** in three places that enumerate the element
   layer: the Downloads table row for the elements BED, the `#` header line of that BED in
   `export.php`, and the `Data::elements()` docblock. The layer has carried a B3 record
   since the literature B elements were added (ODB-EL-1664, chrIV:462,490–462,507 at ARS1,
   Marahrens & Stillman 1992).

6. **About → "Confirmed elements … are the records the ACS matrix itself was built from".**
   True of the 68 confirmed A elements only. The 9 confirmed B2 elements and the 1 B3 are
   literature records and train nothing — the B2 matrix is built from the 303 Reuter 2024
   predictions, and B3 has no matrix. Sentence qualified.

---

## 2. Needs your decision

**a. `README.md` is a release behind, throughout.** It still describes the repository as
"the **first increment**: the *Confirmed* origin tier", and its summary table reads
410 + 48 = 458 origins, 536 evidence records, 37 publications — against 1,580, 822 and 56.
The Coordinates section quotes a lift check over "896 intervals … 845 sequence-identical,
26 substitutions, 25 indel", where `release.json` now records 1,705 identical, 84
same-length mismatch, 108 length-changed. "Runtime | PHP 8.1+ — no database, no Composer,
**no JavaScript**" is no longer true of the origin page. Its own "What is deliberately not
here yet" repeats both items corrected above. Separately, the Deployment section says
"`data/` and `src/` stay above the web root", which contradicts the Layout section, the
deny-all `.htaccess` design and the Development page — there is no `src/`, and `data/` is
deliberately *inside* the web root.
→ Shall I rewrite the README against the current release? It is a substantial rewrite of
the opening sections rather than a numbers patch, which is why I have not done it.

**b. The SGD known issue quotes a superseded origin count.** On the Development page it
opens "Of the **410** Confirmed cerevisiae origins, 293 overlap a replication-origin
feature in UCSC's `sgdOther` table". There are 424. Every number in that entry — 293, 258,
244, 14, 194, 117, 81 — came from the comparison run over the 410-origin set, so the count
cannot simply be changed to 424 without the rest going stale with it.
→ Rerun the SGD comparison over the current Confirmed set, or date-stamp the entry as a
comparison made against an earlier build?

**c. The Studies page undercounts 18 of its 56 publications.** Its lede says "Every
Confirmed origin in this release traces to one or more of these studies", and the Origins
column counts assay records only. So Marahrens & Stillman 1992, Celniker 1984, the four
Theis papers, Huang 1996 and the others that contributed *elements* show 0, and so do the
genome-wide datasets that decide the tiers — McGuffee 2013, Müller 2014, Foss 2024,
Reuter 2024, Eaton 2010 — which contribute no assay record by design. A reader sees
eighteen rows asserting a publication supports nothing.
→ Add a "contributes" column (assays / elements / genome-wide dataset) and count each
kind, or split the table into sections?

**d. Nothing on the site says the rebuild added sites.** `release.json` records 10 novel
sites, ODB-SC-0830 to ODB-SC-0839, with their rule, and `docs/tier-rebuild.md` explains
them. But the home page says "carries every origin the 2012 database held" and About →
Scope says "Every origin OriDB 2012 held is here", and neither mentions that ten sites are
new. The About → Accessions section even explains how a later-added origin takes the next
free number, without saying that ten already have.
→ One sentence on About → Scope, and a clause on the home page?

**e. The genome-browser element track contradicts the site's own colour convention.**
`bin/build_oridb_tracks.py` writes A as orange (166,54,3), B2 as purple (94,60,153) and B3
as a second, lighter purple (140,107,177); the origin-page figure uses blue for A and one
shared brown for every B element, precisely so that a B3 does not read as a near-A. The
track description in `jbrowse.json` says "A (**ACS match**) in orange; B2 and B3 in
purple" — which also reinstates the ACS-means-the-element usage dropped elsewhere.
→ Repoint the track colours and description at the site convention (A blue, all B brown,
"A element" not "ACS match")? That needs `build_oridb_tracks.py` rerun into the hub and
`build_jbrowse.php` rerun after it, so it is a hub change as much as a site change.

**f. Development → "No framework, no JavaScript, no build step for the front end".** The
origin page now ships `origin-browser.js` and loads a 5.7 MB JBrowse bundle on demand. The
bullet goes on to anticipate exactly this ("a genome browser will want JavaScript"), so the
principle is intact and only the headline claim is now false.
→ Reword to something like "no framework, no build step, and no JavaScript on any page
that does not need it"?

**g. Development → method notes, two descriptions that have been overtaken.**
`docs/origin-page-viewer.md` is introduced as "a design note rather than a method: what
embedding a limited JBrowse view **would** require" — it has been built and is on every
cerevisiae origin page. And `docs/tier-evidence-proposal.md` is a dated proposal
("A proposal for discussion, not a decision", 2026-11-18) whose scheme was not adopted as
written: it proposes four tiers ending in **refuted**, where what shipped keeps refutation
as a flag and makes the fourth tier **not assessed**. Both are listed under a heading that
says each note records "how [a layer] was produced".
→ Mark the first as implemented and the second as superseded by `tier-rebuild.md`?

---

## 3. Minor, listed for completeness

- `docs/page-structure.md` still designs the site around "adding the Likely and Dubious
  tiers, and later the genome-wide datasets" — both done.
- `docs/origin_page_changes.md` states it was written against `elements.tsv` (1,657 rows);
  the file now has 1,667.
- `release.json` → `elements.description` reads "A and B2 elements from the four OriDB v2
  element tables and from Reuter et al. 2024", and `elements.studies` lists only the four
  v2 studies. Neither mentions the literature B elements or the B3 class.
- `public/app/elements_view.php`, code comment: "B2 sits a median 74 bp from the A
  element". Measured on the current table the median midpoint-to-midpoint separation is
  45 bp (n = 312 B2 records with an A element in the same origin). Comment only — nothing
  on a page states it.
- The banner on every page reads "every OriDB origin, with its tier recomputed". No tier
  was recomputed for *S. pombe*: 693 of its 741 sites are *not assessed* because no
  registered dataset covers the species.
- The home page's "Start here" list offers Browse, Search, Downloads and About, omitting
  Data hub and Studies, which are both in the nav.
