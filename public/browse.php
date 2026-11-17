<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

$speciesList = Data::speciesList();
$species = isset($_GET['species']) && in_array($_GET['species'], $speciesList, true)
    ? (string) $_GET['species']
    : $speciesList[0];
$chromosomes = Data::chromosomes($species);
$chromosome = isset($_GET['chr']) && in_array($_GET['chr'], $chromosomes, true) ? (string) $_GET['chr'] : '';

$rows    = Data::query(species: $species, chromosome: $chromosome ?: null);
$perPage = 100;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$pages   = max(1, (int) ceil(count($rows) / $perPage));
$page    = min($page, $pages);
$slice   = array_slice($rows, ($page - 1) * $perPage, $perPage);

$title = 'Browse';
require ORIDB_ROOT . '/src/header.php';
?>
<h1>Browse origins</h1>

<form class="filters" method="get" action="browse.php">
  <div>
    <label for="species">Species</label>
    <select name="species" id="species">
      <?php foreach ($speciesList as $s): ?>
        <option value="<?= h($s) ?>"<?= $s === $species ? ' selected' : '' ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="chr">Chromosome</label>
    <select name="chr" id="chr">
      <option value="">all</option>
      <?php foreach ($chromosomes as $c): ?>
        <option value="<?= h($c) ?>"<?= $c === $chromosome ? ' selected' : '' ?>><?= h($c) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit">Apply</button>
</form>

<p class="small"><?= h((string) count($rows)) ?> origins match. Showing page <?= h((string) $page) ?> of <?= h((string) $pages) ?>.</p>

<table>
  <thead>
    <tr><th>Accession</th><th>Name</th><th>Systematic</th><th>Chromosome</th>
        <th class="num">Start</th><th class="num">End</th><th class="num">Width</th><th>Evidence</th></tr>
  </thead>
  <tbody>
  <?php foreach ($slice as $o): ?>
    <tr>
      <td class="mono"><a href="<?= h(u('origin.php', ['id' => $o['accession']])) ?>"><?= h($o['accession']) ?></a></td>
      <td><?= $o['name'] !== '' ? h($o['name']) : '<span class="small">unnamed</span>' ?></td>
      <td class="mono"><?= h($o['systematic']) ?></td>
      <td><?= h($o['chromosome']) ?></td>
      <td class="num"><?= h(bp($o['start'])) ?></td>
      <td class="num"><?= h(bp($o['end'])) ?></td>
      <td class="num"><?= h(bp((int) $o['end'] - (int) $o['start'] + 1)) ?></td>
      <td class="small"><?= h(str_replace(';', ', ', $o['evidence'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<div class="pager">
  <?php if ($page > 1): ?>
    <a href="<?= h(u('browse.php', ['species' => $species, 'chr' => $chromosome, 'page' => $page - 1])) ?>">&larr; Previous</a>
  <?php endif; ?>
  <?php if ($page < $pages): ?>
    <a href="<?= h(u('browse.php', ['species' => $species, 'chr' => $chromosome, 'page' => $page + 1])) ?>">Next &rarr;</a>
  <?php endif; ?>
</div>
<?php require ORIDB_ROOT . '/src/footer.php'; ?>
