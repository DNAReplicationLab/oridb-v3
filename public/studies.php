<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

$counts = [];
foreach (Data::origins() as $o) {
    foreach (Data::evidenceFor($o['accession']) as $e) {
        $counts[$e['pmid']][$o['accession']] = true;
    }
}

$studies = Data::studies();
usort($studies, static fn (array $a, array $b) => [$b['year'], $a['first_author']] <=> [$a['year'], $b['first_author']]);

$title = 'Studies';
require ORIDB_APP_DIR . '/header.php';
?>
<h1>Source publications</h1>
<p class="lede">Every Confirmed origin in this release traces to one or more of these studies.</p>
<table>
  <thead><tr><th>Reference</th><th>Title</th><th>Journal</th><th class="num">Origins</th></tr></thead>
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
      <td class="num"><?= h((string) count($counts[$s['pmid']] ?? [])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
