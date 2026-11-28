<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once ORIDB_APP_DIR . '/elements_view.php';
$release = Data::release();
$title = 'About';
require ORIDB_APP_DIR . '/header.php';
?>
<h1>About this trial release</h1>
<p class="lede"><?= h($release['description']) ?></p>
<p class="small">
  This page explains how to read the database. How the site itself is being built, and what
  is still outstanding, is on <a href="development.php">Development</a>.
</p>

<h2>Scope</h2>
<p>
  Every origin OriDB&nbsp;2012 held is here, with its evidence records. What has changed is how
  well supported each one is said to be: the 2012 tiers were a judgement about the literature
  of the time, and v3 recomputes them from genome-wide datasets re-analysed on the current
  assemblies.
</p>
<p>
  Nothing from v2 was dropped, but the set is no longer only v2's.
  <?= h((string) $release['tiers']['novel_sites']['n']) ?> sites are new, each found by the same
  rule: <?= h($release['tiers']['novel_sites']['rule']) ?>. They were minted as ordinary origins and
  took the next free accessions for their species, and they carry no
  <span class="mono">legacy_id</span>, because there is no 2012 record to point at. They are
  listed with everything else in <a href="browse.php">Browse</a>.
</p>

<h3>The tiers</h3>
<dl class="facts">
  <dt><span class="tag confirmed">Confirmed</span></dt>
  <dd>A plasmid (ARS) assay or a 2D gel showed an origin at this site. This is direct
      experimental evidence and no amount of genome-wide data replaces it.</dd>
  <dt><span class="tag likely">Likely</span></dt>
  <dd>No such assay, but at least two modern datasets support the site and at least one of
      them measures <strong>licensing</strong> &mdash; where the replicative helicase is
      loaded. The licensing requirement is deliberate. Assays that measure firing share a
      blind spot: an origin that is licensed but rarely fires is invisible to all of them, so
      two firing datasets agreeing is weaker evidence than one firing and one licensing
      dataset agreeing. Requiring one of each would be worse still, because it would make
      <em>likely</em> mean <em>active</em> and exclude dormant origins by definition.</dd>
  <dt><span class="tag unsupported">Unsupported</span></dt>
  <dd>Those datasets cover this species and do not support this site. This is a statement
      about the evidence, not a claim that no origin is here.</dd>
  <dt><span class="tag unassessed">Not assessed</span></dt>
  <dd>No dataset in the <em>registry</em> covers that species yet, so no tier has been
      computed. Every <em>S.&nbsp;pombe</em> site that is not confirmed sits here. That is now a
      statement about the registry rather than about the literature: fission yeast profiles are
      served in the <a href="datahub.php">data hub</a> and drawn on the origin pages, but a
      profile is not evidence until it is reduced to a call set and registered, with its
      measured positional error.</dd>
</dl>
<p>
  A site that was cloned and failed to support replication carries a separate
  <span class="tag warn">ARS&#8209;negative</span> flag. It is not a tier, because the result
  describes the fragment that was tested rather than the site:
  <?= h((string) $release['tiers']['refuted']['n_fragments_without_a_site']) ?> of the
  <?= h((string) $release['tiers']['refuted']['n_fragments']) ?> such fragments contain no
  known origin at all, and a few contain an origin that a positive assay confirms.
  The flag is independent of the tier, so both can be filtered on separately in
  <a href="browse.php">Browse</a>.
</p>
<p class="small">
  Which datasets are used, how each threshold was set and what changing one would cost are
  written up in
  <a href="https://github.com/DNAReplicationLab/oridb-v3/blob/main/docs/tier-rebuild.md">the
  tier rebuild note</a>. The tier itself is stored nowhere: it is derived at build time from
  <span class="mono">evidence_matrix.tsv</span>, which records every dataset tested against
  every site.
</p>

<h2>Accessions</h2>
<p>
  Every origin carries a permanent accession, <span class="mono">ODB-SC-nnnn</span> for
  <em>S.&nbsp;cerevisiae</em> and <span class="mono">ODB-SP-nnnn</span> for <em>S.&nbsp;pombe</em>.
  The numbers were minted once, over the complete OriDB&nbsp;2012 origin set of each species in
  genome order. That ordering is a historical accident of the first minting, not a rule:
</p>
<ul>
  <li>An origin added in a later release takes the <strong>next unused number for its
      species</strong>, whatever its coordinates. Accession order therefore stops following
      genome order as soon as the set grows &mdash; sort on chromosome and start, never on the
      accession.</li>
  <li>Because the numbers were minted over the whole 2012 set at once, publishing the
      remaining tiers <strong>filled the gaps rather than creating identifiers</strong>: those
      sites already owned their accessions and nothing was renumbered when they appeared.</li>
  <li>An accession is <strong>never reused, reassigned or renumbered</strong>. Refined
      coordinates, a changed status or a new assembly leave it untouched; an origin that is
      withdrawn keeps its accession, marked as retired, rather than freeing it.</li>
  <li>Two origins that are later merged keep the earlier accession as the surviving record,
      with the other retired and redirected to it; one origin split in two keeps its accession
      for one part and mints a new number for the other.</li>
  <li>The OriDB&nbsp;2012 record id is kept on every origin as
      <span class="mono">legacy_id</span>, so citations and links made against the old site can
      still be resolved.</li>
</ul>
<p class="small">
  Elements carry their own accessions, <span class="mono">ODB-EL-nnnn</span>, minted on the
  same terms. An element accession identifies one study's report of one element, which is why
  the same piece of DNA reported by two studies has two of them.
</p>

<h2>Coordinates</h2>
<p>
  Coordinates are <?= h($release['coordinate_convention']) ?>. Each record carries its assembly
  explicitly, because OriDB v2 did not and that is how two assemblies came to coexist silently
  in one database.
</p>
<ul>
<?php foreach ($release['assemblies'] as $name => $a): ?>
  <li><span class="mono"><?= h($name) ?></span> &mdash; <em><?= h($a['species']) ?></em>,
      sequence names <?= h($a['sequence_naming']) ?>. <?= h($a['note']) ?></li>
<?php endforeach; ?>
</ul>

<h2 id="tiering">How a site is tiered</h2>
<p>
  A tier is a statement about evidence, not about importance, and it is recomputed from the
  evidence records rather than curated by hand. Every registered genome-wide dataset is
  tested against every site, and an origin page lists all of them, supporting or not: a
  dataset that looked and found nothing says something different from one that never looked.
</p>
<ul>
  <li><strong>Confirmed</strong> &mdash; direct experimental evidence at this site: an ARS
      assay or a 2D gel. No number of genome-wide datasets substitutes for it.</li>
  <li><strong>Likely</strong> &mdash; at least two registered datasets support the site, and
      at least one of them measures licensing.</li>
  <li><strong>Unsupported</strong> &mdash; the site is in the database, but current data do
      not support it: one dataset, or none, where datasets covering this species exist.</li>
  <li><strong>Not assessed</strong> &mdash; no registered dataset covers this species yet.
      That records missing coverage, not a negative result.</li>
</ul>
<p>
  A <strong>negative ARS assay is a flag, not a tier</strong>. It describes the fragment that
  was cloned, and a site can hold a positive and a negative result at once &mdash; the pages
  show the flag beside the tier rather than folding one into the other.
  <a href="https://github.com/DNAReplicationLab/oridb-v3/blob/main/docs/tier-rebuild.md">How
  the tiers are built</a>, including where each threshold came from.
</p>

<h2 id="dynamics">Replication dynamics values</h2>
<p>
  Two values are published per <em>S. cerevisiae</em> origin: a firing efficiency from
  Okazaki-fragment sequencing, and a median replication time from sync-seq. Both come from
  genome-wide datasets re-analysed on the current assembly, and both are attached to an
  origin only where the origin and the call are <strong>each other's nearest</strong> and lie
  within the measured positional error of that assay. The distance is published with the
  value, so the attachment can be judged rather than taken on trust.
</p>
<p>
  <strong>A blank is not a zero</strong>, and the two kinds of blank differ.
  <em>Not resolved</em> means the experiment produced no call here at all, which for both
  assays happens to origins that fire late or in few cells &mdash; those origins are
  unmeasured, not inactive. <em>Not assigned</em> means a call does lie within range, and so
  counts towards the tier, but it is not unambiguously this origin's; the page names the
  origin that holds it.
</p>
<p>
  Each value is also given as a <strong>rank</strong>, because the number on its own says
  little: an efficiency of 0.31 is a middling origin and a replication time of 31 minutes is
  an early one, and nothing in the figure itself tells a reader which. An origin is ranked
  against the origins <strong>of the same species that carry the same measurement</strong>
  &mdash; not against the release, since an origin the assay never resolved would otherwise
  be counted as a poor one &mdash; and the size of that population is shown beside the rank.
  Rank 1 is the most efficient origin, and the earliest replicating one; ties share a rank.
  The ranks are published as columns of <code>origins.tsv</code>, so a download says the same
  as the page.
</p>
<p>
  Two further qualifications. The efficiency is the <strong>raw OEM</strong>, without the
  deconvolution the original study applied, so values are under-estimates close to a
  termination zone. The replication time is a <strong>composite</strong> and not a firing
  time: DNA here is replicated either by this origin or by a fork arriving from a neighbour,
  and an early value can be produced by either.
  <a href="https://github.com/DNAReplicationLab/oridb-v3/blob/main/docs/origin_activity_timing.md">How
  these values are assigned</a>.
</p>

<h2 id="elements">Origin elements</h2>
<p>
  Origins are intervals; the elements inside them are the short sequences that make an origin
  work. Each origin page lists the elements that fall within it, draws where they sit, and
  aligns them under the motif they match. What follows is the explanation behind those panels.
  It is the same for every origin, so it is kept here rather than repeated on each one.
</p>

<h3>A, B1, B2 and B3</h3>
<ul>
  <li><strong>A element</strong> &mdash; the element the origin recognition complex binds, and
      the one the <strong>ACS</strong> (ARS consensus sequence) motif matches.
      &ldquo;ACS&rdquo; names the <em>motif</em>, not the element: the element is a piece of DNA
      in the genome, the motif is the matrix it is scored against.</li>
  <li><strong>B1 region</strong> &mdash; a second conserved signal a short distance 3' of the A
      element and inside the same window, which the matrix recovers as its own run of conserved
      columns after a trough at background. It is marked on the alignment rather than left
      implicit, but it is not a separate record: the <em>A and B1 elements</em> panel on an
      origin page shows one window that contains both.</li>
  <li><strong>B2 element</strong> &mdash; a distinct element further 3' of the A element,
      reported as a class of its own by the study it comes from and carried here as its own
      record, with its own matrix. B2 elements are <em>not</em> scored against the ACS motif,
      which does not describe them.</li>
  <li><strong>B3 element</strong> &mdash; the Abf1p-binding element, the furthest of the B
      elements from the A element at the origins where it has been defined; at ARS1 it lies
      89&nbsp;bp 3' of the A element, beyond B2 at 49&nbsp;bp. It is a protein-binding site for a sequence-specific
      factor rather than a degenerate consensus, so it has no matrix here and is carried from
      the literature with its coordinates and its citation. It is drawn and listed, but has no
      alignment panel: there is nothing to align it against.</li>
</ul>

<h3>How elements are located</h3>
<p>
  Every element is placed in the current assembly <strong>by its own stored sequence</strong>,
  not by the coordinates OriDB v2 recorded, because those coordinates belong to older assemblies
  and in places to a different frame. Two consequences are visible on the origin pages:
</p>
<ul>
  <li>The same element reported by two studies appears <strong>twice at the same
      position</strong>, once per study, rather than being silently merged. The study column
      says which record is whose.</li>
  <li>An element whose sequence does not occur in the assembly, or occurs several times with
      none near the position v2 recorded, is published <strong>without coordinates</strong>
      rather than placed on a guess. The placement column carries that outcome, and such a
      record is listed but not drawn or aligned.</li>
</ul>
<p>
  The placement column also gives how the element sits in the origin interval
  (<span class="mono">contained</span> or <span class="mono">overlapping</span>). An element may
  extend past the end of the interval, and the figure on the origin page draws it that way
  rather than trimming it to fit.
</p>

<h3>Evidence: confirmed or predicted</h3>
<p>
  <span class="mono">confirmed</span> elements are experimentally defined; the rest are
  <span class="mono">predicted</span> &mdash; computational calls, published under their own names
  by the studies they come from. This is evidence, not class: an element is A, B2 or B3
  <em>and</em> confirmed or predicted. Confirmed elements are set in bold in the alignment, labelled there,
  and drawn in a solid colour in the overview figure. The confirmed <em>A</em> elements are also the
  records the ACS matrix itself was built from; a confirmed B2 or B3 comes from the literature,
  trains nothing, and is not aligned under a logo either, for the reason given below.
</p>

<h3>What the motif panels show</h3>
<?php
$acs = $release['acs_motif'] ?? null;
if ($acs !== null):
    $acsRegions = motif_regions($acs);
    $acsCore = motif_region($acsRegions, 'core');
    $acsB1 = motif_region($acsRegions, 'b1');
    ?>
<p>
  The sequence logo is drawn from the position weight matrix, with the element rows laid out one
  logo column wide beneath it, so every base sits under its own position in the motif. The ACS
  matrix was built from the <?= h((string) $acs['training_elements']) ?> confirmed elements that
  can be placed, extended into the genome either side of the core; it is
  <?= h((string) $acs['width']) ?> columns wide (<?= h((string) $acs['window']) ?> relative to the
  core) and carries <?= h((string) $acs['information_content_bits']) ?> bits in total. Information
  is measured against the genome's own base composition rather than a uniform background, and the
  logo is drawn on the same scale.
</p>
<ul>
  <li>Columns <?= h((string) $acsCore['start']) ?>&ndash;<?= h((string) $acsCore['end']) ?> are the
      <strong>A element core</strong><?php if ($acsB1 !== null): ?>, and columns
      <?= h((string) $acsB1['start']) ?>&ndash;<?= h((string) $acsB1['end']) ?> the
      <strong>B1 region</strong><?php endif; ?>. Both spans are derived from the matrix in
      <span class="mono">release.json</span>, so a rebuilt matrix moves the marks with it.</li>
  <li>The <strong>shaded run</strong> in each row is the sequence as that study published it,
      which is between 10 bases and the full window depending on the study. Everything outside it
      is genomic context added here to fill the window, so a short published string can still be
      scored over the whole motif.</li>
  <li>Bases <strong>agreeing</strong> with the consensus are black, the rest grey. The consensus
      letters are IUPAC codes, so a <span class="mono">W</span> column counts both A and T as
      agreement, and a column with no dominant base counts everything.</li>
  <li>Elements are shown on the <strong>T-rich strand</strong>, the strand the motif is defined on.
      That is not always the genomic plus strand; the records table gives the strand each element
      was placed on.</li>
</ul>
<p>
  The <strong>score</strong> is a log-odds sum of the whole window against the matrix, in bits,
  with the percentage of the range that matrix can reach beside it. It measures match to the motif
  and nothing else: a high-scoring predicted element has not been shown to do anything, and a
  confirmed element with a weak score is still confirmed. B2 elements are scored against the B2
  matrix, so a B2 score and an A score are not comparable with each other.
  <a href="https://github.com/DNAReplicationLab/oridb-v3/blob/main/docs/acs-motif.md">How the ACS
  matrix was derived</a>.
</p>
<?php endif; ?>

<?php
$b2 = $release['b2_motif'] ?? null;
if ($b2 !== null):
    $b2Block = motif_region(motif_regions($b2), 'core');
    ?>
<h3>How the B2 motif was derived</h3>
<p>
  The B2 matrix is built by the same recipe as the ACS matrix but from different records, and
  the two are separate objects: a B2 score and an A score are not on the same scale and should
  not be compared. It was trained on the
  <?= h((string) $b2['training_elements']) ?> B2 elements of Reuter et&nbsp;al. 2024
  (PMID&nbsp;39181881) that can be placed in sacCer3. Those records are genome-wide motif calls
  of one fixed width, so they already share a frame; the window here is the published interval
  plus two bases of genomic context upstream, which puts the published interval in columns
  3&ndash;<?= h((string) $b2['width']) ?> of a <?= h((string) $b2['width']) ?> column matrix, read
  on the element's own strand. Every column is a base frequency with a quarter-count added,
  scored as log-odds against the sacCer3 base composition, and the logo is drawn on that same
  scale.
</p>
<ul>
  <?php if ($b2Block !== null): ?>
    <li>The matrix carries <?= h((string) $b2['information_content_bits']) ?> bits in total, most
        of it in the <strong>conserved block</strong> at columns
        <?= h((string) $b2Block['start']) ?>&ndash;<?= h((string) $b2Block['end']) ?>: an A-rich
        run that reads as a degenerate, inverted A element. Everything 5' of it is close to the
        genome's own base composition, which is why the consensus is almost all
        <span class="mono">n</span>. The block is derived from the matrix, so a rebuild moves
        it.</li>
  <?php endif; ?>
  <li>The published B2 logo of Reuter et&nbsp;al. 2024 is a WebLogo of the subset of calls at
      their class&nbsp;C1 origins, 58 sequences one base narrower than this window. That logo is
      the sharper of the two, as a logo over 58 sequences generally is: recomputed on this
      window it comes to 11.2 bits against 9.2 bits here, and its column profile agrees with
      this one once the extra upstream base is accounted for. The difference is the size of the
      training set, not a different motif.</li>
  <li>A <strong>confirmed</strong> B2 element is <em>not</em> part of this training set and is
      not aligned under the logo. These are functionally defined footprints read from the
      literature, between 7 and 23&nbsp;bp as published, and a footprint carries no fixed
      position relative to the window above: anchoring a 7&nbsp;bp element at its own start would
      put it in the flanking columns, nowhere near the conserved block. Such an element is
      placed, drawn in the overview figure and listed in the records table, and the origin page
      names it and says why it is not in the panel. The same holds for a B3, which has no matrix
      at all.</li>
</ul>
<p class="small">
  <a href="https://github.com/DNAReplicationLab/oridb-v3/blob/main/docs/b2-motif.md">How the B2
  matrix was derived</a>, in full, with the comparison against the published figure.
</p>
<?php endif; ?>

<h3>The overview figure</h3>
<p>
  The figure at the top of an origin's element section draws the origin interval as a box, with
  the A element above it and the B elements &mdash; B2, and B3 where one is defined &mdash;
  below, each class in its own colour. The two sides are separated because the elements lie close
  together: the B elements sit within about a hundred bases 3' of the A element, which at the
  scale of an origin is a few pixels. Marks that would collide are stacked onto
  further rows rather than drawn over one another. Marks are to scale, down to a floor of a few
  pixels so that a short element in a wide origin stays visible, and the pointed end of a mark is
  the 3' end of that element. Exact coordinates are always in the records table below it.
</p>

<h2>Provenance</h2>
<p class="small">
  Source: <?= h($release['provenance']['source']) ?>.
  Tables used: <span class="mono"><?= h(implode(', ', $release['provenance']['source_tables'])) ?></span>.
</p>
<p class="small">
  The questions a curator still has to answer about this release are listed under
  <a href="development.php#known-issues">known issues</a>.
</p>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
