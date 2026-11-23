<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

// A publication reaches this database by one of three routes, and several take
// more than one: it reported an assay at a site, it defined an origin element,
// or it produced a genome-wide dataset that is tested against every site. The
// page counted only the first, so eighteen studies - every element paper, and
// every dataset behind the tiers - appeared to support nothing.
$counts = [];

$elementCounts = [];
foreach (Data::elements() as $el) {
    if ($el['study_pmid'] !== '') {
        $elementCounts[$el['study_pmid']] = ($elementCounts[$el['study_pmid']] ?? 0) + 1;
    }
}

// The dataset registry, which is the same list every origin page shows: a study
// can register more than one, and one that does not count towards the tier is
// still registered and still reported.
$datasetCounts = [];
foreach (Data::release()['tiers']['datasets'] ?? [] as $d) {
    if (($d['study_pmid'] ?? '') !== '') {
        $datasetCounts[$d['study_pmid']] = ($datasetCounts[$d['study_pmid']] ?? 0) + 1;
    }
}

// Which species a publication bears on, so the page can be sectioned the way the
// database is. A study is placed by the origins its assays support, by the species
// of the elements it defined, or by the species of the dataset it registered - in
// that order of directness. No study in this release bears on more than one, but
// one that did would appear in both sections rather than being forced into one.
$studySpecies = [];
$note = static function (string $pmid, string $species) use (&$studySpecies): void {
    if ($pmid !== '' && $species !== '') {
        $studySpecies[$pmid][$species] = true;
    }
};
$originSpecies = [];
foreach (Data::origins() as $o) {
    $originSpecies[$o['accession']] = $o['species'];
    foreach (Data::evidenceFor($o['accession']) as $e) {
        $counts[$e['pmid']][$o['accession']] = true;
        $note((string) $e['pmid'], $o['species']);
    }
}
foreach (Data::elements() as $el) {
    $note((string) $el['study_pmid'], (string) $el['species']);
}
foreach (Data::release()['tiers']['datasets'] ?? [] as $d) {
    $note((string) ($d['study_pmid'] ?? ''), (string) ($d['species'] ?? ''));
}

$studies = Data::studies();
usort($studies, static fn (array $a, array $b) => [$b['year'], $a['first_author']] <=> [$a['year'], $b['first_author']]);

// A study whose species cannot be established from any of the three routes is
// listed on its own rather than dropped: a missing section is a bug, and silently
// losing a citation is worse than showing it under a heading that says so.
$speciesList = Data::speciesList();
$sections = [];
foreach ($speciesList as $sp) {
    $sections[$sp] = array_values(array_filter(
        $studies,
        static fn (array $s): bool => isset($studySpecies[$s['pmid']][$sp])
    ));
}
$unplaced = array_values(array_filter(
    $studies,
    static fn (array $s): bool => !isset($studySpecies[$s['pmid']])
));

$title = 'Studies';
require ORIDB_APP_DIR . '/header.php';
?>
<h1>Source publications</h1>
<p class="lede">
  Every record in this release traces to one or more of these studies. A publication
  contributes along up to three routes, and several take more than one.
</p>
<ul class="small">
  <li><strong>Origins</strong> &mdash; sites at which this study reported an assay, positive or
      negative. Every Confirmed origin has at least one such record.</li>
  <li><strong>Elements</strong> &mdash; A, B2 or B3 element records this study defined or
      predicted. One element reported by two studies is one record each.</li>
  <li><strong>Datasets</strong> &mdash; genome-wide datasets from this study in the registry that
      is tested against every site. These decide the Likely and Unsupported tiers and contribute
      no individual assay record.</li>
</ul>
<nav class="sectionnav small" aria-label="Sections of this page">
  <?php foreach ($speciesList as $sp): ?>
    <a href="#<?= h(species_slug($sp)) ?>"><em><?= h(species_abbrev($sp)) ?></em></a>
  <?php endforeach; ?>
  <?php if ($unplaced !== []): ?><a href="#unplaced">Unplaced</a><?php endif; ?>
  <a class="top" href="#main">Top</a>
</nav>

<?php
/**
 * One table, rendered per section. The Elements column is dropped where no
 * element in the release belongs to that species, rather than printed as a
 * column of dashes: the element layer is cerevisiae-only, and a column that
 * cannot hold a value for this species says something untrue about the gap.
 */
$table = static function (array $rows, bool $withElements) use ($counts, $elementCounts, $datasetCounts): void { ?>
<table>
  <thead><tr><th>Reference</th><th>Title</th><th>Journal</th><th class="num">Origins</th>
             <?php if ($withElements): ?><th class="num">Elements</th><?php endif; ?>
             <th class="num">Datasets</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $s): ?>
    <tr>
      <td>
        <a href="https://pubmed.ncbi.nlm.nih.gov/<?= h($s['pmid']) ?>/" rel="noopener">
          <?= h(explode(',', $s['first_author'])[0]) ?><?= (int) $s['n_authors'] > 1 ? ' et al.' : '' ?>
          <?= h($s['year']) ?></a>
        <?php if ($s['doi'] !== ''): ?>
          <br><a class="small mono" href="https://doi.org/<?= h($s['doi']) ?>" rel="noopener"><?= h($s['doi']) ?></a>
        <?php endif; ?>
      </td>
      <td><?= h($s['title']) ?></td>
      <td class="small"><?= h($s['journal']) ?></td>
      <?php
      // A dash, not a zero: this study took a different route in, and a zero
      // would read as a study that looked and found nothing.
      $cells = [count($counts[$s['pmid']] ?? [])];
      if ($withElements) {
          $cells[] = $elementCounts[$s['pmid']] ?? 0;
      }
      $cells[] = $datasetCounts[$s['pmid']] ?? 0;
      foreach ($cells as $n): ?>
        <td class="num"><?= $n > 0 ? h(bp($n)) : '<span class="small">&ndash;</span>' ?></td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php };

$elementSpecies = [];
foreach (Data::elements() as $el) {
    $elementSpecies[$el['species']] = true;
}
?>

<?php foreach ($speciesList as $sp): $rows = $sections[$sp]; ?>
<h2 id="<?= h(species_slug($sp)) ?>"><em><?= h($abbrev = species_abbrev($sp)) ?></em></h2>
<p class="small">
  <?= h(bp(count($rows))) ?> publication<?= count($rows) === 1 ? '' : 's' ?>.
  <?php if (!isset($elementSpecies[$sp])): ?>
    No element in this release belongs to <em><?= h($abbrev) ?></em>, so there is no Elements
    column here: the element layer covers one species so far.
  <?php endif; ?>
</p>
<?php if ($rows === []): ?>
  <p class="small">No publication in the release bears on this species yet.</p>
<?php else: ?>
  <?php $table($rows, isset($elementSpecies[$sp])); ?>
<?php endif; ?>
<?php endforeach; ?>

<?php if ($unplaced !== []): ?>
<h2 id="unplaced">Species not established</h2>
<p class="small">
  These publications are cited by a record whose species could not be read from the origins,
  elements or datasets they contribute. That is a curation gap, not a property of the paper.
</p>
<?php $table($unplaced, true); ?>
<?php endif; ?>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
