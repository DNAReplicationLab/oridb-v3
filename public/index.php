<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

$release  = Data::release();
$origins  = Data::origins();
$bySpecies = [];
foreach ($origins as $o) {
    $bySpecies[$o['species']] = ($bySpecies[$o['species']] ?? 0) + 1;
}

$title = 'Home';
require ORIDB_APP_DIR . '/header.php';
?>
<h1>DNA replication origin database</h1>
<p class="lede">
  OriDB collects the mapped and curated replication origins of budding and fission yeast.
  This is the first increment of version 3.0: the <strong>Confirmed</strong> tier, rebuilt so that
  every origin carries the experimental records that justify it.
</p>

<ul class="stats">
<?php foreach ($bySpecies as $species => $n): ?>
  <li><span class="n"><?= h((string) $n) ?></span><span class="k"><em><?= h($species) ?></em></span></li>
<?php endforeach; ?>
  <li><span class="n"><?= h((string) $release['files']['evidence.tsv']['rows']) ?></span><span class="k">evidence records</span></li>
  <li><span class="n"><?= h((string) $release['files']['elements.tsv']['rows']) ?></span><span class="k">ACS elements</span></li>
  <li><span class="n"><?= h((string) $release['files']['studies.tsv']['rows']) ?></span><span class="k">source publications</span></li>
</ul>

<div class="note">
  <strong>What "Confirmed" means here.</strong>
  <?= h($release['inclusion_rule']) ?>
  Every origin below links to the individual assay records, each with its citation.
</div>

<h2>Start here</h2>
<ul>
  <li><a href="browse.php">Browse</a> the origin list by species and chromosome.</li>
  <li><a href="search.php">Search</a> by name, accession or genomic interval.</li>
  <li><a href="downloads.php">Download</a> the release as TSV, BED or JSON.</li>
  <li><a href="about.php">About</a> the rebuild, its scope and its known gaps.</li>
</ul>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
