<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once ORIDB_APP_DIR . '/elements_view.php';

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
    <span class="tag <?= h(tier_class($origin['status'])) ?>"><?= h($origin['status']) ?></span>
    <?php if (($origin['ars_negative'] ?? 'no') === 'yes'): ?>
      <span class="tag warn">ARS&#8209;negative</span>
    <?php endif; ?></h1>

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

<?php
$support = Data::supportFor($accession);
$counted = array_filter($support, static fn (array $r): bool => $r['counts_towards_tier'] === 'yes');
$yes = array_filter($counted, static fn (array $r): bool => $r['supported'] === 'yes');
$kinds = array_unique(array_column($yes, 'measures'));
?>
<h2>How this site is tiered</h2>
<?php if ($support === []): ?>
  <p class="small">
    No modern dataset in the registry covers <em><?= h($origin['species']) ?></em> yet, so this
    site is shown as <strong>not assessed</strong>. That records missing coverage, not a
    negative result: nothing has looked here.
  </p>
<?php else: ?>
<p class="small">
  Every registered dataset is tested against every site, and all of them are listed below
  whether or not they support this one &mdash; a dataset that looked and found nothing says
  something different from a dataset that never looked. A site is <strong>confirmed</strong>
  on direct experimental evidence alone, and <strong>likely</strong> when at least two
  datasets support it and at least one of those measures licensing.
</p>
<table>
  <thead><tr><th>Dataset</th><th>Measures</th><th>Supports this site</th>
             <th>Nearest call</th><th class="num">Distance</th><th>Reference</th></tr></thead>
  <tbody>
  <?php foreach ($support as $r): ?>
    <tr>
      <td class="mono"><?= h($r['dataset_id']) ?></td>
      <td><?= h($r['measures']) ?><?= $r['counts_towards_tier'] === 'no'
            ? ' <span class="small">(not used for the tier)</span>' : '' ?></td>
      <td><?= $r['supported'] === 'yes'
            ? '<strong>yes</strong>'
            : '<span class="small">no</span>' ?></td>
      <td class="mono"><?= $r['call'] !== '' ? h($r['call']) : '<span class="small">&ndash;</span>' ?></td>
      <td class="num"><?= $r['distance_bp'] !== '' ? h(bp((int) $r['distance_bp'])) . ' bp'
            : '<span class="small">&ndash;</span>' ?></td>
      <td><?= cite_pmid($r['study_pmid']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="small">
  <?= h((string) count($yes)) ?> of <?= h((string) count($counted)) ?> datasets that count
  towards the tier support this site<?= $kinds !== [] ? ' (' . h(implode(', ', $kinds)) . ')' : '' ?>.
  <?php if (($origin['ars_negative'] ?? 'no') === 'yes'): ?>
    This site also lies inside a fragment that was cloned and did not support autonomous
    replication, listed with the evidence below. That result is carried as a flag and never
    as a tier: it describes the fragment that was tested, and a site can hold a positive and
    a negative assay at once.
  <?php endif; ?>
  <a href="https://github.com/DNAReplicationLab/oridb-v3/blob/main/docs/tier-rebuild.md">How
  the tiers are built</a>.
</p>
<?php endif; ?>

<h2>Supporting evidence</h2>
<p class="small">
  <?= h((string) count($evidence)) ?> experimental record<?= count($evidence) === 1 ? '' : 's' ?>
  overlap this interval. Each record is an individual assay reported by the cited study.
</p>
<table>
  <thead><tr><th>Method</th><th>Assayed interval</th><th class="num">Width</th><th>Detail</th><th>Reference</th></tr></thead>
  <tbody>
  <?php foreach ($evidence as $e): ?>
    <tr>
      <td><?= h($e['method']) ?></td>
      <td class="mono"><?= h($e['chromosome']) ?>:<?= h(bp($e['start'])) ?>&ndash;<?= h(bp($e['end'])) ?></td>
      <td class="num"><?= h(bp((int) $e['end'] - (int) $e['start'] + 1)) ?></td>
      <td><?= $e['detail'] !== '' ? h($e['detail']) : '<span class="small">&ndash;</span>' ?></td>
      <td><?= cite_pmid((string) $e['pmid']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php
// Per-origin values from the two modern genome-wide call sets. Both measure
// firing, both cover S. cerevisiae only, and both are assigned by the rule in
// docs/origin_activity_timing.md: mutually nearest call, within that assay's
// measured positional error. An origin with no qualifying call keeps an empty
// field, which records a call the experiment could not resolve - not a zero.
$dyn = [
    [
        'label'   => 'Firing efficiency',
        'unit'    => 'OEM, 0 to 1',
        'value'   => $origin['okseq_activity_oem'] ?? '',
        'format'  => static fn (string $v): string => number_format((float) $v, 2),
        'call'    => $origin['okseq_call'] ?? '',
        'dist'    => $origin['okseq_distance_bp'] ?? '',
        'what'    => 'the fraction of cells in which a fork starts here, from the '
                   . 'directionality of Okazaki fragments',
        'pmid'    => '23562327',
        'assay'   => 'Okazaki-fragment sequencing',
    ],
    [
        'label'   => 'Median replication time',
        'unit'    => 'minutes after release',
        'value'   => $origin['median_replication_time_composite_min'] ?? '',
        'format'  => static fn (string $v): string => number_format((float) $v, 1),
        'call'    => $origin['replication_time_call'] ?? '',
        'dist'    => $origin['replication_time_distance_bp'] ?? '',
        'what'    => 'the time by which half the cells have replicated this DNA, from '
                   . 'sequencing a synchronous culture',
        'pmid'    => '24089142',
        'assay'   => 'sync-seq replication profiling',
    ],
];
// The assays are cerevisiae-wide, so the section is shown for every cerevisiae
// origin, with or without values: a missing value is itself a result. No
// equivalent pombe dataset is loaded yet, so the section is omitted there.
$showDynamics = str_contains($origin['species'], 'cerevisiae');
?>
<?php if ($showDynamics): ?>
<h2>Replication dynamics</h2>
<p class="small">
  How often this origin fires, and when the surrounding DNA is replicated. Each value is
  read from a genome-wide dataset re-analysed on <?= h($origin['assembly']) ?> and is
  attached to this origin only where the origin and the call are each other&rsquo;s nearest
  neighbour and lie within the measured positional error of that assay, so the distance is
  published with the value.
</p>
<table>
  <thead><tr><th>Measurement</th><th class="num">Value</th><th>Assigned call</th>
             <th class="num">Distance</th><th>Assay</th></tr></thead>
  <tbody>
  <?php foreach ($dyn as $d): ?>
    <tr>
      <td><?= h($d['label']) ?>
          <span class="small"><?= h($d['unit']) ?></span></td>
      <td class="num">
        <?php if ($d['value'] !== ''): ?>
          <strong><?= h(($d['format'])((string) $d['value'])) ?></strong>
        <?php else: ?>
          <span class="small">not resolved</span>
        <?php endif; ?>
      </td>
      <td class="mono"><?= $d['call'] !== '' ? h($d['call']) : '<span class="small">&ndash;</span>' ?></td>
      <td class="num"><?= $d['dist'] !== '' ? h(bp((int) $d['dist'])) . ' bp' : '<span class="small">&ndash;</span>' ?></td>
      <td><?= h($d['assay']) ?><br><span class="small"><?= cite_pmid($d['pmid']) ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="small">
  <strong>Read these with their qualifications.</strong>
  A blank is not a zero: it records that the experiment resolved no call here, which for
  both of these assays happens to origins that fire late or in few cells, so the origins
  without a value are unmeasured rather than inactive.
  The efficiency is the raw OEM, without the deconvolution the original study applied, so
  values are under-estimates close to a termination zone.
  The replication time is a <em>composite</em> and not a firing time &mdash; DNA here is
  replicated either by this origin or by a fork arriving from a neighbour, and an early
  value can be produced by either.
  <a href="https://github.com/DNAReplicationLab/oridb-v3/blob/main/docs/origin_activity_timing.md">How
  these values are assigned</a>, and both datasets are browsable in the
  <a href="datahub.php">data hub</a>.
</p>
<?php endif; ?>

<h2>Origin elements</h2>
<?php
$elements = Data::elementsFor($accession);
// Placed elements only can be drawn or aligned; the records table lists them all.
$byClass = ['A' => [], 'B2' => []];
foreach ($elements as $e) {
    $class = ($e['element_class'] ?? 'A') === 'B2' ? 'B2' : 'A';
    if ($e['motif_window'] !== '' && $e['start'] !== '') {
        $byClass[$class][] = $e;
    }
}
$motifs = [
    'A' => Data::release()['acs_motif'] ?? null,
    'B2' => Data::release()['b2_motif'] ?? null,
];
?>
<?php if ($elements === []): ?>
  <p class="small">
    No element from the element studies falls within this origin. What these elements are
    and how they are located is described <a href="about.php#elements">on the About page</a>.
  </p>
<?php else: ?>
  <p class="small">
    <?= h((string) count($elements)) ?> element record<?= count($elements) === 1 ? '' : 's' ?>
    at this origin: <?= h((string) count($byClass['A'])) ?> A,
    <?= h((string) count($byClass['B2'])) ?> B2 (placed).
    <a href="about.php#elements">What A and B2 elements are, how they are located and what
    the scores mean</a>.
  </p>

  <?php
  $figure = element_overview_svg($origin, $elements, 'elfig-' . $accession);
  $unplacedCount = count($elements) - count($byClass['A']) - count($byClass['B2']);
  ?>
  <?php if ($figure !== ''): ?>
    <figure class="elfig-wrap">
      <?= $figure ?>
      <figcaption class="small">
        Elements drawn to scale across the origin interval, A above and B2 below. Dashed
        rules mark the ends of the origin, so an element that extends past them is drawn
        outside the box rather than trimmed to fit. Marks narrower than a few pixels are
        drawn at that minimum, so a very short element stays visible; exact coordinates are
        in the table below. Hovering a mark names the element.
      </figcaption>
    </figure>
  <?php endif; ?>
  <?php if ($unplacedCount > 0): ?>
    <p class="small"><?= h((string) $unplacedCount) ?> of these records could not be placed
    in the assembly from their sequence, so they are listed below but not drawn or aligned.</p>
  <?php endif; ?>

  <?php if ($motifs['A'] !== null && $byClass['A'] !== []):
      $motifA = $motifs['A'];
      $regionsA = motif_regions($motifA);
      $coreA = motif_region($regionsA, 'core');
      $b1A = motif_region($regionsA, 'b1'); ?>
    <h3>A and B1 elements</h3>
    <p class="small">
      The <?= h((string) $motifA['width']) ?> bp window below spans both elements: the A
      element &mdash; the ORC-binding element the ACS motif matches &mdash; at columns
      <?= h((string) $coreA['start']) ?>&ndash;<?= h((string) $coreA['end']) ?>, and
      <?php if ($b1A !== null): ?>
        the B1 region at columns <?= h((string) $b1A['start']) ?>&ndash;<?= h((string) $b1A['end']) ?>,
        which the matrix recovers as a separate run of conserved columns past a trough at
        background. Both are marked on the rows.
      <?php else: ?>
        no second conserved run is resolved in this matrix.
      <?php endif; ?>
      Each element is shown on its T-rich strand, aligned on the A core; the shaded run is
      the sequence as that study published it and the rest is genomic context. Bases
      agreeing with the consensus are in black, the rest grey.
      <strong>Confirmed elements are in bold</strong> and labelled
      <span class="ev">confirmed</span> beside the score; the others are predictions.
      <a href="about.php#elements">How the motif is built and scored</a>.
    </p>
    <?php motif_panel(
        $motifA,
        $byClass['A'],
        (int) $motifA['logo_column_width'],
        (int) $motifA['logo_left_pad'],
        'Sequence logo of the OriDB A-element (ACS) motif'
    ); ?>
  <?php endif; ?>

  <?php if ($motifs['B2'] !== null && $byClass['B2'] !== []):
      $motifB = $motifs['B2'];
      $regionsB = motif_regions($motifB);
      $blockB = motif_region($regionsB, 'core'); ?>
    <h3>B2 elements</h3>
    <p class="small">
      B2 is a distinct element 3' of the A element, with its own matrix: these rows are
      scored against the B2 motif, not against the ACS motif.
      <?php if ($blockB !== null): ?>
        Its conserved block falls at columns <?= h((string) $blockB['start']) ?>&ndash;<?= h((string) $blockB['end']) ?>
        of the <?= h((string) $motifB['width']) ?> bp window and is marked on the rows;
      <?php else: ?>
        The window is <?= h((string) $motifB['width']) ?> bp wide;
      <?php endif; ?>
      the matrix carries <?= h((string) $motifB['information_content_bits']) ?> bits in all,
      so agreement is weaker and more spread out than for the A element. Shading, tinting
      and scores read as in the panel above.
      <a href="about.php#elements">What the B2 element is</a>.
    </p>
    <?php motif_panel(
        $motifB,
        $byClass['B2'],
        (int) $motifB['logo_column_width'],
        (int) $motifB['logo_left_pad'],
        'Sequence logo of the OriDB B2-element motif'
    ); ?>
  <?php endif; ?>

  <h3>Element records</h3>
  <table>
    <thead><tr><th>Element</th><th>Position</th><th class="num">Length</th><th>Strand</th>
               <th>Class</th><th>Evidence</th><th>Study</th><th>Placement</th></tr></thead>
    <tbody>
    <?php foreach ($elements as $e): ?>
      <tr<?= $e['element_type'] === 'confirmed' ? ' class="conf"' : '' ?>>
        <td class="mono"><?= h($e['accession']) ?></td>
        <td class="mono"><?= $e['start'] !== ''
            ? h($e['chromosome']) . ':' . h(bp($e['start'])) . '&ndash;' . h(bp($e['end']))
            : '<span class="small">not placed</span>' ?></td>
        <td class="num"><?= h($e['length']) ?></td>
        <td class="mono"><?= h($e['strand']) ?></td>
        <td><?= h($e['element_class']) ?></td>
        <td><?= h($e['element_type']) ?></td>
        <td class="small"><?= cite_pmid((string) $e['study_pmid'], (string) $e['source_table']) ?></td>
        <td class="small">
          <?= h($e['relation']) ?><?= $e['anchor_status'] !== 'unique' ? ', ' . h($e['anchor_status']) : '' ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2>Machine-readable</h2>
<p class="small">
  <a href="<?= h(u('api/origin.php', ['id' => $accession])) ?>">JSON record</a> for this origin.
</p>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
