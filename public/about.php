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
  Only the Confirmed tier is included. <?= h($release['inclusion_rule']) ?>
  The Likely and Dubious tiers rest on genome-wide mapping datasets, which are being
  rebuilt separately and will be added once their assemblies and thresholds are settled.
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
  <li>Numbers already spent on the Likely and Dubious tiers are <strong>not free</strong>.
      This release publishes the Confirmed tier only, so the numbering in these files is
      deliberately gappy; the missing numbers belong to origins that exist in OriDB&nbsp;2012 and
      are held for them.</li>
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

<h2 id="elements">Origin elements</h2>
<p>
  Origins are intervals; the elements inside them are the short sequences that make an origin
  work. Each origin page lists the elements that fall within it, draws where they sit, and
  aligns them under the motif they match. What follows is the explanation behind those panels.
  It is the same for every origin, so it is kept here rather than repeated on each one.
</p>

<h3>A, B1 and B2</h3>
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
  by the studies they come from. This is evidence, not class: an element is A or B2 <em>and</em>
  confirmed or predicted. Confirmed elements are set in bold in the alignment, labelled there,
  and drawn in a solid colour in the overview figure, because they are the records the ACS matrix
  itself was built from.
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

<h3>The overview figure</h3>
<p>
  The figure at the top of an origin's element section draws the origin interval as a box, with A
  elements above it and B2 elements below. The two classes are separated because they lie close
  together &mdash; B2 sits a few tens of bases 3' of the A element, which at the scale of an
  origin is a few pixels &mdash; and within each class, marks that would collide are stacked onto
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
