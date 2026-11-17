<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

$release = Data::release();
$title = 'Downloads';
require ORIDB_APP_DIR . '/header.php';
?>
<h1>Downloads</h1>
<p class="lede">
  Release <strong><?= h($release['release']) ?></strong>. Checksums are published so that a copy
  deposited elsewhere can be proved identical to the one served here.
</p>

<h2>Generated exports</h2>
<table>
  <thead><tr><th>Format</th><th>Scope</th><th>Notes</th></tr></thead>
  <tbody>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'bed', 'species' => 'Saccharomyces cerevisiae'])) ?>">BED</a></td>
        <td><em>S. cerevisiae</em>, sacCer1</td><td class="small">0-based half-open, accession as feature name</td></tr>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'bed', 'species' => 'Schizosaccharomyces pombe'])) ?>">BED</a></td>
        <td><em>S. pombe</em>, ASM294v2</td><td class="small">0-based half-open, accession as feature name</td></tr>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'tsv'])) ?>">TSV</a></td>
        <td>all origins</td><td class="small">as served, 1-based inclusive</td></tr>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'json'])) ?>">JSON</a></td>
        <td>origins with nested evidence</td><td class="small">one object per origin</td></tr>
  </tbody>
</table>

<h2>Release files</h2>
<table>
  <thead><tr><th>File</th><th class="num">Rows</th><th>MD5</th></tr></thead>
  <tbody>
  <?php foreach ($release['files'] as $name => $meta): ?>
    <tr>
      <td class="mono"><?= h($name) ?></td>
      <td class="num"><?= h((string) $meta['rows']) ?></td>
      <td class="mono small"><?= h($meta['md5']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2>Citation</h2>
<p class="small">
  Cite the original OriDB papers plus this release identifier. A DOI will be minted when the
  trial release is deposited.
</p>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
