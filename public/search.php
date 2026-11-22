<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

$q      = trim((string) ($_GET['q'] ?? ''));
$region = trim((string) ($_GET['region'] ?? ''));
$results = [];
$error   = '';
$total   = count(Data::origins());

if ($region !== '') {
    // Accept "chrIV:100000-200000", "chrIV 100000 200000" or bare "chrIV".
    if (preg_match('/^\s*([A-Za-z0-9_.]+)\s*(?::|\s)?\s*([\d,]*)\s*(?:-|\.\.|\s)?\s*([\d,]*)\s*$/', $region, $m) === 1) {
        $chr  = $m[1];
        $from = $m[2] !== '' ? (int) str_replace(',', '', $m[2]) : null;
        $to   = $m[3] !== '' ? (int) str_replace(',', '', $m[3]) : null;
        $results = Data::query(chromosome: $chr, from: $from, to: $to);
    } else {
        $error = 'Could not read that as a region. Try chrIV:100000-200000.';
    }
} elseif ($q !== '') {
    $results = Data::query(text: $q);
}

$title = 'Search';
require ORIDB_APP_DIR . '/header.php';
?>
<h1>Search</h1>

<?php if (defined('ORIDB_STATIC')): ?>
  <div class="note">
    <strong>Search needs the live site.</strong>
    This is a static preview served from GitHub Pages, which runs no code, so queries
    cannot be answered here. Use the search on
    <a href="https://v3.oridb.org/search.php">v3.oridb.org</a>, or
    <a href="browse.html">browse</a> the <?= h((string) $total) ?> origins — every one has its own page, and your
    browser's own find-in-page works on the browse tables.
  </div>
<?php else: ?>

<form class="filters" method="get" action="search.php">
  <div>
    <label for="q">Name or accession</label>
    <input type="text" name="q" id="q" value="<?= h($q) ?>" placeholder="ARS305, ODB-SC-0042, IV-62" size="28">
  </div>
  <button type="submit">Search</button>
</form>

<form class="filters" method="get" action="search.php">
  <div>
    <label for="region">Genomic region</label>
    <input type="text" name="region" id="region" value="<?= h($region) ?>" placeholder="chrIV:100000-200000" size="28">
  </div>
  <button type="submit">Search</button>
</form>

<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="note"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($q !== '' || $region !== ''): ?>
  <p class="small"><?= h((string) count($results)) ?> match<?= count($results) === 1 ? '' : 'es' ?>.</p>
  <?php if ($results === [] && $error === ''): ?>
    <div class="note">
      <strong>Nothing found.</strong>
      This release holds <?= h((string) $total) ?> origins &mdash; every site OriDB&nbsp;2012 listed,
      whatever its tier, plus the sites the rebuild added &mdash; so a name from v2 should match.
      Names are matched as substrings and case is ignored; try a shorter
      fragment such as <span class="mono">ARS30</span>, an accession such as
      <span class="mono">ODB-SC-0042</span>, or search by
      <a href="browse.php">browsing a chromosome</a>.
    </div>
  <?php endif; ?>
  <?php if ($results !== []): ?>
  <table>
    <thead><tr><th>Accession</th><th>Name</th><th>Species</th><th>Position</th><th class="num">Width</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($results, 0, 200) as $o): ?>
      <tr>
        <td class="mono"><a href="<?= h(u('origin.php', ['id' => $o['accession']])) ?>"><?= h($o['accession']) ?></a></td>
        <td><?= $o['name'] !== '' ? h($o['name']) : '<span class="small">unnamed</span>' ?></td>
        <td class="small"><em><?= h($o['species']) ?></em></td>
        <td class="mono"><?= h($o['chromosome']) ?>:<?= h(bp($o['start'])) ?>&ndash;<?= h(bp($o['end'])) ?></td>
        <td class="num"><?= h(bp((int) $o['end'] - (int) $o['start'] + 1)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
<?php endif; ?>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
