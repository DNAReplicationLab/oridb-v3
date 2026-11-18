<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
$release = Data::release();
$title = 'About';
require ORIDB_APP_DIR . '/header.php';
?>
<h1>About this trial release</h1>
<p class="lede"><?= h($release['description']) ?></p>

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

<h2>Known issues in this release</h2>
<p class="lede">
  Recorded openly rather than quietly: each entry below is a question a curator still has to
  answer, and the records it affects are linked so they can be inspected directly.
</p>
<ul>
<?php foreach ($release['known_issues'] as $issue): ?>
  <?php
  // Entries are objects carrying affected accessions; tolerate a plain string too.
  $text = is_array($issue) ? ($issue['text'] ?? '') : $issue;
  $accessions = is_array($issue) ? ($issue['accessions'] ?? []) : [];
  ?>
  <li>
    <?= h($text) ?>
    <?php if ($accessions !== []): ?>
      <div class="small" style="margin-top:.35rem">
        <?php foreach ($accessions as $i => $acc): ?>
          <a class="mono" href="<?= h(u('origin.php', ['id' => $acc])) ?>"><?= h($acc) ?></a><?php
          $o = Data::origin($acc);
          if ($o !== null && $o['name'] !== '') { echo ' (' . h($o['name']) . ')'; }
          echo $i < count($accessions) - 1 ? ', ' : '';
        ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>

<h2>Provenance</h2>
<p class="small">
  Source: <?= h($release['provenance']['source']) ?>.
  Tables used: <span class="mono"><?= h(implode(', ', $release['provenance']['source_tables'])) ?></span>.
</p>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
