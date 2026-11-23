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

<?php
// Sectioned by species, because that is the axis that grows: a third species adds a
// section and its own strip entry rather than four more rows to one table. The
// element exports are cerevisiae-only and sit in that species' section, not in the
// whole-release section, because they are not whole-release.
$speciesList = Data::speciesList();
$elementAssembly = Data::elementAssembly();
?>
<nav class="sectionnav small" aria-label="Sections of this page">
  <?php foreach ($speciesList as $sp): ?>
    <a href="#<?= h(species_slug($sp)) ?>"><em><?= h(species_abbrev($sp)) ?></em></a>
  <?php endforeach; ?>
  <a href="#whole-release">Whole release</a>
  <a href="#release-files">Release files</a>
  <a class="top" href="#main">Top</a>
</nav>

<?php foreach ($speciesList as $sp):
    $rows = Data::query(species: $sp);
    $build = $rows === [] ? '' : $rows[0]['assembly'];
    $abbrev = species_abbrev($sp);
    // The element layer is built on one assembly; a species it does not cover gets no
    // element rows rather than a link to an empty file.
    $hasElements = $build !== '' && $build === $elementAssembly; ?>
<h2 id="<?= h(species_slug($sp)) ?>"><em><?= h($abbrev) ?></em></h2>
<p class="small">
  <?= h(bp(count($rows))) ?> origins on <span class="mono"><?= h($build) ?></span>.
</p>
<table>
  <thead><tr><th>Format</th><th>Scope</th><th>Notes</th></tr></thead>
  <tbody>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'bed', 'species' => $sp])) ?>">BED</a></td>
        <td>origins</td>
        <td class="small">0-based half-open, accession as feature name, shaded by tier</td></tr>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'bed', 'species' => $sp, 'names' => 'legacy'])) ?>">BED</a></td>
        <td>origins, legacy names</td>
        <td class="small">same intervals, column 4 is the historical OriDB name
          (<a href="<?= h(u('export.php', ['format' => 'bed', 'species' => $sp, 'names' => 'both'])) ?>">accession|name</a>
          also available)</td></tr>
    <?php if ($hasElements): ?>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'elements'])) ?>">BED</a></td>
        <td>origin elements</td>
        <td class="small">A, B2 and B3 elements from every element study. Column 4 is the
          accession with the class appended (<span class="mono">ODB-EL-0187_A</span>) and the
          score is 1000 for a confirmed element, 500 for a predicted one, so a browser shades
          the two apart. Records with no position in this assembly are omitted.</td></tr>
    <?php endif; ?>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'tsv', 'species' => $sp])) ?>">TSV</a></td>
        <td>origins, every column</td>
        <td class="small">as served, 1-based inclusive; the only export that carries the legacy
          layer &mdash; historical and systematic names, other names, the OriDB&nbsp;2012 record id
          (<span class="mono">legacy_id</span>) and the original source-assembly coordinates with
          their lift status</td></tr>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'json', 'species' => $sp])) ?>">JSON</a></td>
        <td>origins with nested evidence</td>
        <td class="small">one object per origin, with its evidence records and elements</td></tr>
  </tbody>
</table>
<?php endforeach; ?>

<h2 id="whole-release">Every species at once</h2>
<table>
  <thead><tr><th>Format</th><th>Scope</th><th>Notes</th></tr></thead>
  <tbody>
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
        <td class="small">every species in one file, same columns as the per-species TSV</td></tr>
    <tr><td><a href="<?= h(u('export.php', ['format' => 'json'])) ?>">JSON</a></td>
        <td>all origins with nested evidence</td><td class="small">one object per origin</td></tr>
  </tbody>
</table>

<h2 id="release-files">Release files</h2>
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
