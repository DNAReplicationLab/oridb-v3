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

<h2>ACS elements</h2>
<?php $elements = Data::elementsFor($accession); ?>
<?php if ($elements === []): ?>
  <p class="small">No ACS element from the four element studies falls within this origin.</p>
<?php else: ?>
  <p class="small">
    <?= h((string) count($elements)) ?> element record<?= count($elements) === 1 ? '' : 's' ?>.
    Each is located in <?= h($origin['assembly']) ?> by its own sequence rather than by the
    coordinates OriDB v2 recorded, so the same element reported by two studies appears twice
    at the same position.
  </p>
  <table>
    <thead><tr><th>Element</th><th>Position</th><th class="num">Length</th><th>Strand</th>
               <th>Class</th><th>Study</th><th>Placement</th></tr></thead>
    <tbody>
    <?php foreach ($elements as $e):
        $study = Data::study($e['study_pmid']); ?>
      <tr>
        <td class="mono"><?= h($e['accession']) ?></td>
        <td class="mono"><?= h($e['chromosome']) ?>:<?= h(bp($e['start'])) ?>&ndash;<?= h(bp($e['end'])) ?></td>
        <td class="num"><?= h($e['length']) ?></td>
        <td class="mono"><?= h($e['strand']) ?></td>
        <td><?= h($e['element_type']) ?></td>
        <td class="small">
          <?php if ($study !== null): ?>
            <a href="https://pubmed.ncbi.nlm.nih.gov/<?= h($study['pmid']) ?>/" rel="noopener">
              <?= h(explode(',', $study['first_author'])[0]) ?><?= (int) $study['n_authors'] > 1 ? ' et al.' : '' ?>
              <?= h($study['year']) ?></a>
          <?php else: ?>
            <?= h($e['source_table']) ?>
          <?php endif; ?>
        </td>
        <td class="small">
          <?= h($e['relation']) ?><?= $e['anchor_status'] !== 'unique' ? ', ' . h($e['anchor_status']) : '' ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php
  $motif = Data::release()['acs_motif'] ?? null;
  $aligned = array_values(array_filter($elements, static fn (array $e): bool => $e['motif_window'] !== ''));
  ?>
  <?php if ($motif !== null && $aligned !== []): ?>
    <h3>Match to the ACS motif</h3>
    <p class="small">
      The motif below was derived from the <?= h((string) $motif['training_elements']) ?>
      experimentally confirmed ACS elements, extended into the genome either side of the
      11 bp core; columns <?= h((string) $motif['core_columns'][0]) ?>&ndash;<?= h((string) $motif['core_columns'][1]) ?>
      are the core, marked by the rules below. Each element is shown on its T-rich strand,
      aligned on that core. The shaded run is the sequence as published by that study,
      which is 10 to 33 bp depending on the study; everything outside it is genomic
      context added here to fill the window. Bases matching the consensus are in black,
      the rest grey. The score is the log-odds of the whole
      <?= h((string) $motif['width']) ?> bp window against the matrix.
      <a href="https://github.com/DNAReplicationLab/oridb-v3/blob/main/docs/acs-motif.md">How this motif was derived</a>.
    </p>
    <div class="alnbox" style="--colw: <?= h((string) $motif['logo_column_width']) ?>px;
                               --padl: <?= h((string) $motif['logo_left_pad']) ?>px">
    <img src="<?= h(Data::asset($motif['logo'])) ?>" alt="Sequence logo of the OriDB ACS motif" class="logo">
    <table class="aln">
      <tbody>
        <tr class="cons">
          <td class="lbl">consensus</td>
          <?php foreach (str_split($motif['consensus']) as $i => $c):
              $col = $i + 1; ?>
            <td class="b core<?= $col === $motif['core_columns'][0] ? ' cs' : '' ?><?= $col === $motif['core_columns'][1] ? ' ce' : '' ?>"><?= h($c) ?></td>
          <?php endforeach; ?>
          <td class="sc">score</td>
        </tr>
        <?php foreach ($aligned as $e): ?>
          <tr>
            <td class="lbl mono"><?= h($e['accession']) ?> <span class="small"><?= h($e['length']) ?> bp</span></td>
            <?php foreach (str_split($e['motif_window']) as $i => $b):
                $col = $i + 1;
                $code = $motif['consensus'][$i];
                $ok = str_contains(['W' => 'AT', 'Y' => 'CT', 'R' => 'AG', 'S' => 'CG',
                                    'M' => 'AC', 'K' => 'GT', 'n' => 'ACGT'][$code] ?? $code, $b);
                $cls = 'b ' . ($ok ? 'm' : 'x')
                     . ($col >= (int) $e['published_start'] && $col <= (int) $e['published_end'] ? ' pub' : '')
                     . ($col === $motif['core_columns'][0] ? ' cs' : '')
                     . ($col === $motif['core_columns'][1] ? ' ce' : ''); ?>
              <td class="<?= h($cls) ?>"><?= h($b) ?></td>
            <?php endforeach; ?>
            <td class="sc"><?= h($e['motif_score']) ?> bits<br><span class="small"><?= h($e['motif_score_pct']) ?>%</span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php $unaligned = count($elements) - count($aligned); ?>
    <?php if ($unaligned > 0): ?>
      <p class="small"><?= h((string) $unaligned) ?> element<?= $unaligned === 1 ? '' : 's' ?>
      could not be placed in the assembly and so cannot be aligned.</p>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<h2>Machine-readable</h2>
<p class="small">
  <a href="<?= h(u('api/origin.php', ['id' => $accession])) ?>">JSON record</a> for this origin.
</p>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
