<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

// A publication reaches this database by one of three routes, and several take
// more than one: it reported an assay at a site, it defined an origin element,
// or it produced a genome-wide dataset that is tested against every site. The
// page counted only the first, so eighteen studies - every element paper, and
// every dataset behind the tiers - appeared to support nothing.
$counts = [];
foreach (Data::origins() as $o) {
    foreach (Data::evidenceFor($o['accession']) as $e) {
        $counts[$e['pmid']][$o['accession']] = true;
    }
}

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

$studies = Data::studies();
usort($studies, static fn (array $a, array $b) => [$b['year'], $a['first_author']] <=> [$a['year'], $b['first_author']]);

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
<table>
  <thead><tr><th>Reference</th><th>Title</th><th>Journal</th><th class="num">Origins</th>
             <th class="num">Elements</th><th class="num">Datasets</th></tr></thead>
  <tbody>
  <?php foreach ($studies as $s): ?>
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
      $cells = [
          count($counts[$s['pmid']] ?? []),
          $elementCounts[$s['pmid']] ?? 0,
          $datasetCounts[$s['pmid']] ?? 0,
      ];
      foreach ($cells as $n): ?>
        <td class="num"><?= $n > 0 ? h(bp($n)) : '<span class="small">&ndash;</span>' ?></td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
