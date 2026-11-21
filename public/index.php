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
  Version 3.0 carries every origin the 2012 database held, each with the experimental records
  that justify it, and recomputes how well supported it is from modern genome-wide data.
</p>

<ul class="stats">
<?php foreach ($bySpecies as $species => $n): ?>
  <li><span class="n"><?= h((string) $n) ?></span><span class="k"><em><?= h($species) ?></em></span></li>
<?php endforeach; ?>
<?php foreach (Data::tierCounts() as $tier => $n): ?>
  <li><span class="n"><?= h((string) $n) ?></span><span class="k"><?= h(strtolower($tier)) ?></span></li>
<?php endforeach; ?>
  <li><span class="n"><?= h((string) $release['files']['evidence.tsv']['rows']) ?></span><span class="k">evidence records</span></li>
  <li><span class="n"><?= h((string) $release['files']['elements.tsv']['rows']) ?></span><span class="k">origin elements</span></li>
  <li><span class="n"><?= h((string) $release['files']['studies.tsv']['rows']) ?></span><span class="k">source publications</span></li>
</ul>

<div class="note">
  <strong>What the tiers mean.</strong>
  <strong>Confirmed</strong>, an ARS assay or a 2D gel showed an origin at this site.
  <strong>Likely</strong>, no such assay, but two or more modern datasets support it and at
  least one of them measures origin licensing.
  <strong>Unsupported</strong>, those datasets cover the species and do not support this site.
  <strong>Not assessed</strong>, no modern dataset has yet been applied to that species.
  A site that was cloned and failed to replicate carries a separate
  <span class="tag warn">ARS&#8209;negative</span> flag rather than a tier of its own, because
  that result describes the fragment tested and a site can hold both a positive and a negative
  assay. <a href="about.php">How a site is placed in a tier</a>.
</div>

<h2>Start here</h2>
<ul>
  <li><a href="browse.php">Browse</a> the origin list by species and chromosome.</li>
  <li><a href="search.php">Search</a> by name, accession or genomic interval.</li>
  <li><a href="downloads.php">Download</a> the release as TSV, BED or JSON.</li>
  <li><a href="about.php">About</a> the rebuild, its scope and its known gaps.</li>
</ul>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
