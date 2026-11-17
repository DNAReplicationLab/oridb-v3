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
