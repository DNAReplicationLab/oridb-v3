<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

$speciesList = Data::speciesList();
$species = isset($_GET['species']) && in_array($_GET['species'], $speciesList, true)
    ? (string) $_GET['species']
    : $speciesList[0];
$chromosomes = Data::chromosomes($species);
$chromosome = isset($_GET['chr']) && in_array($_GET['chr'], $chromosomes, true) ? (string) $_GET['chr'] : '';

$tiers   = array_keys(Data::tierCounts($species));
$tier    = isset($_GET['tier']) && in_array($_GET['tier'], $tiers, true) ? (string) $_GET['tier'] : '';
// The refuted flag is orthogonal to the tier: a site can be Confirmed and ARS-negative.
$refuted = in_array($_GET['refuted'] ?? '', ['yes', 'no'], true) ? (string) $_GET['refuted'] : '';

$rows    = Data::query(species: $species, chromosome: $chromosome ?: null,
                       tier: $tier ?: null, arsNegative: $refuted ?: null);
$perPage = 100;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$pages   = max(1, (int) ceil(count($rows) / $perPage));
$page    = min($page, $pages);
$slice   = array_slice($rows, ($page - 1) * $perPage, $perPage);

$title = 'Browse';
require ORIDB_APP_DIR . '/header.php';
?>
<h1>Browse origins</h1>

<?php if (defined('ORIDB_STATIC')): ?>
  <p class="small">
    Static preview: filtering needs the live site. Showing <em><?= h($species) ?></em>.
    <?php foreach ($speciesList as $alt): if ($alt === $species) { continue; } ?>
      Switch to <a href="<?= h(u('browse.php', ['species' => $alt, 'page' => 1])) ?>"><em><?= h($alt) ?></em></a>.
    <?php endforeach; ?>
  </p>
<?php else: ?>
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
  <div>
    <label for="tier">Tier</label>
    <select name="tier" id="tier">
      <option value="">all</option>
      <?php foreach ($tiers as $t): ?>
        <option value="<?= h($t) ?>"<?= $t === $tier ? ' selected' : '' ?>><?= h($t) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="refuted">ARS assay</label>
    <select name="refuted" id="refuted">
      <option value="">any result</option>
      <option value="yes"<?= $refuted === 'yes' ? ' selected' : '' ?>>tested, did not replicate</option>
      <option value="no"<?= $refuted === 'no' ? ' selected' : '' ?>>no negative result</option>
    </select>
  </div>
  <button type="submit">Apply</button>
</form>
<?php endif; ?>

<p class="small"><?= h((string) count($rows)) ?> sites match. Showing page <?= h((string) $page) ?> of <?= h((string) $pages) ?>.</p>

<table>
  <thead>
    <tr><th>Accession</th><th>Name</th><th>Tier</th><th>Systematic</th><th>Chromosome</th>
        <th class="num">Start</th><th class="num">End</th><th class="num">Width</th><th>Evidence</th></tr>
  </thead>
  <tbody>
  <?php foreach ($slice as $o): ?>
    <tr>
      <td class="mono"><a href="<?= h(u('origin.php', ['id' => $o['accession']])) ?>"><?= h($o['accession']) ?></a></td>
      <td><?= $o['name'] !== '' ? h($o['name']) : '<span class="small">unnamed</span>' ?></td>
      <td><span class="tag <?= h(tier_class($o['status'])) ?>"><?= h($o['status']) ?></span>
          <?php if (($o['ars_negative'] ?? 'no') === 'yes'): ?>
            <span class="tag warn" title="cloned and did not support replication">ARS&#8209;negative</span>
          <?php endif; ?></td>
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
    <a href="<?= h(u('browse.php', ['species' => $species, 'chr' => $chromosome, 'tier' => $tier, 'refuted' => $refuted, 'page' => $page - 1])) ?>">&larr; Previous</a>
  <?php endif; ?>
  <?php if ($page < $pages): ?>
    <a href="<?= h(u('browse.php', ['species' => $species, 'chr' => $chromosome, 'tier' => $tier, 'refuted' => $refuted, 'page' => $page + 1])) ?>">Next &rarr;</a>
  <?php endif; ?>
</div>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
