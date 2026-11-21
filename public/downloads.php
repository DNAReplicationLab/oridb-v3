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
    <?php foreach (Data::speciesList() as $sp):
        $rows = Data::query(species: $sp);
        $build = $rows === [] ? '' : $rows[0]['assembly'];
        $parts = explode(' ', $sp);
        $abbrev = count($parts) > 1 ? substr($parts[0], 0, 1) . '. ' . $parts[1] : $sp; ?>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'bed', 'species' => $sp])) ?>">BED</a></td>
        <td><em><?= h($abbrev) ?></em>, <?= h($build) ?></td>
        <td class="small">0-based half-open, accession as feature name</td></tr>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'bed', 'species' => $sp, 'names' => 'legacy'])) ?>">BED</a></td>
        <td><em><?= h($abbrev) ?></em>, <?= h($build) ?>, legacy names</td>
        <td class="small">same intervals, column 4 is the historical OriDB name
          (<a href="<?= h(u('export.php', ['format' => 'bed', 'species' => $sp, 'names' => 'both'])) ?>">accession|name</a>
          also available)</td></tr>
    <?php endforeach; ?>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'elements'])) ?>">BED</a></td>
        <td>origin elements, <?= h(Data::elementAssembly()) ?></td>
        <td class="small">A and B2 elements from every element study; records with no position in that assembly are omitted</td></tr>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'matrix'])) ?>">TSV</a></td>
        <td>evidence matrix</td>
        <td class="small">one row per site per dataset, supported or not, with the nearest call
          and its distance &mdash; the table the tier is computed from</td></tr>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'refuted'])) ?>">TSV</a></td>
        <td>ARS-negative fragments</td>
        <td class="small">every fragment cloned that did not support replication, including
          those containing no known origin</td></tr>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'tsv'])) ?>">TSV</a></td>
        <td>all origins</td>
        <td class="small">as served, 1-based inclusive; the only export that carries the legacy
          layer &mdash; historical and systematic names, other names, the OriDB&nbsp;2012 record id
          (<span class="mono">legacy_id</span>) and the original source-assembly coordinates with
          their lift status</td></tr>
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
