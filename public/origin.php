<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

$accession = isset($_GET['id']) ? (string) $_GET['id'] : '';
$origin = $accession !== '' ? Data::origin($accession) : null;
if ($origin === null) {
    send_404('No origin with accession "' . $accession . '".');
}

$evidence = Data::evidenceFor($accession);
$width = (int) $origin['end'] - (int) $origin['start'] + 1;

$title = $origin['name'] !== '' ? $origin['name'] : $origin['accession'];
require ORIDB_APP_DIR . '/header.php';
?>
<h1><?= h($origin['name'] !== '' ? $origin['name'] : $origin['accession']) ?>
    <span class="tag"><?= h($origin['status']) ?></span></h1>

<dl class="facts">
  <dt>Accession</dt><dd class="mono"><?= h($origin['accession']) ?></dd>
  <dt>Systematic name</dt><dd class="mono"><?= h($origin['systematic']) ?></dd>
  <?php if ($origin['other_names'] !== ''): ?>
    <dt>Other names</dt><dd><?= h(str_replace(',', ', ', $origin['other_names'])) ?></dd>
  <?php endif; ?>
  <dt>Species</dt><dd><em><?= h($origin['species']) ?></em></dd>
  <dt>Assembly</dt><dd class="mono"><?= h($origin['assembly']) ?></dd>
  <dt>Position</dt>
  <dd class="mono"><?= h($origin['chromosome']) ?>:<?= h(bp($origin['start'])) ?>&ndash;<?= h(bp($origin['end'])) ?>
      <span class="small">(<?= h(bp($width)) ?> bp, 1-based inclusive)</span></dd>
  <dt>Genome browser</dt>
  <dd><a href="<?= h(Data::browserUrl($origin)) ?>" rel="noopener">View in browser (<?= h($origin['assembly']) ?>)</a></dd>
  <?php if (($origin['lift_status'] ?? '') !== '' && $origin['lift_status'] !== 'not-required'): ?>
    <dt>Original position</dt>
    <dd class="mono"><?= h($origin['source_chromosome']) ?>:<?= h(bp($origin['source_start'])) ?>&ndash;<?= h(bp($origin['source_end'])) ?>
        <span class="small">(<?= h($origin['source_assembly']) ?>, as recorded in OriDB v2)</span></dd>
    <dt>Lift check</dt>
    <dd>
      <?php
      $msg = [
          'identical' => 'sequence identical in both assemblies',
          'same-length-mismatch' => 'same length, but the assembly sequence was revised',
          'length-changed' => 'an insertion or deletion falls inside this interval, so its length changed',
          'unmapped' => 'no alignment in the chain file',
          'unverified' => 'lifted, but not checked against the sequence',
      ][$origin['lift_status']] ?? $origin['lift_status'];
      ?>
      <?= h($msg) ?>
      <?php if ($origin['lift_status'] === 'length-changed'): ?>
        <span class="small">(<?= h(bp((int) $origin['source_end'] - (int) $origin['source_start'] + 1)) ?> bp
        &rarr; <?= h(bp($width)) ?> bp)</span>
      <?php endif; ?>
    </dd>
  <?php endif; ?>
  <dt>OriDB v2 record</dt><dd class="mono"><?= h($origin['legacy_id']) ?></dd>
</dl>

<h2>Supporting evidence</h2>
<p class="small">
  <?= h((string) count($evidence)) ?> experimental record<?= count($evidence) === 1 ? '' : 's' ?>
  overlap this interval. Each record is an individual assay reported by the cited study.
</p>
<table>
  <thead><tr><th>Method</th><th>Assayed interval</th><th class="num">Width</th><th>Detail</th><th>Reference</th></tr></thead>
  <tbody>
  <?php foreach ($evidence as $e):
      $study = Data::study($e['pmid']); ?>
    <tr>
      <td><?= h($e['method']) ?></td>
      <td class="mono"><?= h($e['chromosome']) ?>:<?= h(bp($e['start'])) ?>&ndash;<?= h(bp($e['end'])) ?></td>
      <td class="num"><?= h(bp((int) $e['end'] - (int) $e['start'] + 1)) ?></td>
      <td><?= $e['detail'] !== '' ? h($e['detail']) : '<span class="small">&ndash;</span>' ?></td>
      <td>
        <?php if ($study !== null): ?>
          <a href="https://pubmed.ncbi.nlm.nih.gov/<?= h($study['pmid']) ?>/" rel="noopener">
            <?= h(explode(',', $study['first_author'])[0]) ?><?= (int) $study['n_authors'] > 1 ? ' et al.' : '' ?>
            <?= h($study['year']) ?></a>
        <?php else: ?>
          <span class="small">citation not recorded in v2</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2>Machine-readable</h2>
<p class="small">
  <a href="<?= h(u('api/origin.php', ['id' => $accession])) ?>">JSON record</a> for this origin.
</p>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
