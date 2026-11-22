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

// Read once, at the top: the summary block states how much each section holds, so the
// counts have to exist before the first section is rendered.
$support = Data::supportFor($accession);
$elements = Data::elementsFor($accession);
$showDynamics = str_contains($origin['species'], 'cerevisiae');

// The browser panel is drawn only for an assembly the hub serves a sequence for;
// bin/build_jbrowse.php lists exactly those. The window is the origin plus a flank
// of its own width, and never less than 2 kb: interval widths run from 1 bp to
// 38.6 kb, so a fixed flank would frame a sixth of the database badly.
$jb = Data::jbrowse();
$jbAssembly = isset($jb['assemblies'][$origin['assembly']]) ? $origin['assembly'] : null;
$jbPad = max(2000, $width);
$jbLoc = sprintf('%s:%d..%d', $origin['chromosome'],
                 max(1, (int) $origin['start'] - $jbPad), (int) $origin['end'] + $jbPad);
$jbHighlight = sprintf('%s:%d..%d', $origin['chromosome'], (int) $origin['start'], (int) $origin['end']);
$jbDefaults = $jbAssembly !== null ? ($jb['defaults'][$jbAssembly] ?? []) : [];
// What the panel will show is read from the configuration, never named here: an
// assembly with no quantitative dataset yet says so, and gains the sentence back
// the moment one is added to the hub.
$jbDefaultTracks = array_values(array_filter(
    $jb['tracks'][$jbAssembly] ?? [],
    static fn (array $t): bool => in_array($t['trackId'], $jbDefaults, true)
));
$jbNames = array_column($jbDefaultTracks, 'name');
$jbHasSignal = array_filter($jbDefaultTracks,
    static fn (array $t): bool => $t['type'] === 'QuantitativeTrack') !== [];

$title = $origin['name'] !== '' ? $origin['name'] : $origin['accession'];
require ORIDB_APP_DIR . '/header.php';
?>
<h1><?= h($origin['name'] !== '' ? $origin['name'] : $origin['accession']) ?>
    <span class="tag <?= h(tier_class($origin['status'])) ?>"><?= h($origin['status']) ?></span>
    <?php if (($origin['ars_negative'] ?? 'no') === 'yes'): ?>
      <span class="tag warn">ARS&#8209;negative</span>
    <?php endif; ?></h1>

<?php
// The summary answers the question most visits come with - where is it, how well
// supported is it, how often does it fire - without scrolling. Everything in it is
// repeated in full further down; nothing is only here.
$fmt = static fn (string $v, int $dp): string => $v !== '' ? number_format((float) $v, $dp) : '';
$oem  = $fmt((string) ($origin['okseq_activity_oem'] ?? ''), 2);
$trep = $fmt((string) ($origin['median_replication_time_composite_min'] ?? ''), 1);
?>
<dl class="facts summary">
  <dt>Accession</dt><dd class="mono"><?= h($origin['accession']) ?></dd>
  <dt>Position</dt>
  <dd class="mono"><?= h($origin['chromosome']) ?>:<?= h(bp($origin['start'])) ?>&ndash;<?= h(bp($origin['end'])) ?>
      <span class="small">(<?= h(bp($width)) ?> bp)</span></dd>
  <dt>Assembly</dt>
  <dd><em><?= h($origin['species']) ?></em>, <span class="mono"><?= h($origin['assembly']) ?></span></dd>
  <dt>Evidence tier</dt>
  <dd><span class="tag <?= h(tier_class($origin['status'])) ?>"><?= h($origin['status']) ?></span>
      <span class="small">from <?= h((string) count($evidence)) ?> assay
      record<?= count($evidence) === 1 ? '' : 's' ?><?php
      if (($origin['ars_negative'] ?? 'no') === 'yes'): ?>; a fragment covering this site
      failed an ARS assay<?php endif; ?></span></dd>
  <?php if ($showDynamics): ?>
  <dt>Firing efficiency</dt>
  <dd><?= $oem !== '' ? '<strong>' . h($oem) . '</strong> <span class="small">OEM, 0 to 1</span>'
        : '<span class="small">not resolved by Ok-seq</span>' ?></dd>
  <dt>Replication time</dt>
  <dd><?= $trep !== '' ? '<strong>' . h($trep) . '</strong> <span class="small">min, composite</span>'
        : '<span class="small">not resolved by sync-seq</span>' ?></dd>
  <?php endif; ?>
  <dt>Elements</dt>
  <dd><?= $elements === [] ? '<span class="small">none recorded</span>'
        : h((string) count($elements)) . ' record' . (count($elements) === 1 ? '' : 's') ?></dd>
  <dt>Genome browser</dt>
  <dd><a href="<?= h(Data::browserUrl($origin)) ?>" rel="noopener">UCSC, <?= h($origin['assembly']) ?></a></dd>
</dl>

<nav class="sectionnav small" aria-label="Sections of this page">
  <?php if ($jbAssembly !== null): ?><a href="#genome">In the genome</a><?php endif; ?>
  <a href="#evidence">Evidence</a>
  <?php if ($showDynamics): ?><a href="#dynamics">Replication dynamics</a><?php endif; ?>
  <a href="#elements">Elements</a>
  <a href="#identifiers">Identifiers</a>
  <a href="#data">Data</a>
</nav>

<?php if ($jbAssembly !== null): ?>
<h2 id="genome">In the genome</h2>
<figure id="oridb-browser" class="jbpanel"
        data-config="<?= h(Data::asset('assets/jbrowse.json')) ?>"
        data-bundle="<?= h(Data::asset('assets/vendor/jbrowse-lgv-4.3.0.umd.min.js')) ?>"
        data-assembly="<?= h($jbAssembly) ?>"
        data-accession="<?= h($origin['accession']) ?>"
        data-loc="<?= h($jbLoc) ?>"
        data-highlight="<?= h($jbHighlight) ?>">
  <div class="jb-slot" hidden></div>
  <div class="jb-placeholder">
    <p class="small">
      <?= h((string) count($jbDefaults)) ?> track<?= count($jbDefaults) === 1 ? '' : 's' ?>
      over <span class="mono"><?= h($jbLoc) ?></span>, with this origin marked:
      <?= h(implode(', ', $jbNames)) ?><?= $jbHasSignal ? '' :
          ' &mdash; the OriDB records over the reference sequence, because no genome-wide'
          . ' dataset is served on this assembly yet' ?>.
      The browser code is 5.7&nbsp;MB, about 1.4&nbsp;MB if your connection is
      compressed, so it loads only when you ask for it.
    </p>
    <button type="button" class="jb-load" hidden>Show the data here</button>
    <noscript><p class="small">This panel needs JavaScript. The same tracks are in the
      <a href="<?= h(Data::browserUrl($origin)) ?>" rel="noopener">UCSC browser</a> and on the
      <a href="datahub.php">data hub</a> page.</p></noscript>
    <p class="jb-note small"></p>
  </div>
  <figcaption class="small">
    Drawn from the <a href="datahub.php">OriDB data hub</a>, the same files the UCSC link
    above loads; the panel reads them directly in your browser. It is a view of the data,
    not a workspace &mdash; for sessions, more tracks and your own files, open the hub in
    <a href="<?= h(Data::browserUrl($origin)) ?>" rel="noopener">UCSC</a>.
  </figcaption>
</figure>
<script src="<?= h(Data::asset('assets/origin-browser.js')) ?>" defer></script>
<?php endif; ?>

<?php
$counted = array_filter($support, static fn (array $r): bool => $r['counts_towards_tier'] === 'yes');
$yes = array_filter($counted, static fn (array $r): bool => $r['supported'] === 'yes');
$kinds = array_unique(array_column($yes, 'measures'));
?>
<h2 id="evidence">Evidence</h2>
<?php if ($support === []): ?>
  <p class="small">
    No modern dataset in the registry covers <em><?= h($origin['species']) ?></em> yet, so this
    site is shown as <strong>not assessed</strong>. That records missing coverage, not a
    negative result: nothing has looked here.
    <a href="about.php#tiering">How the tiers are assigned</a>.
  </p>
<?php else: ?>
<h3>Genome-wide datasets</h3>
<p class="small">
  All <?= h((string) count($support)) ?> registered datasets are listed, supporting or not:
  a dataset that looked and found nothing says something different from one that never looked.
  <a href="about.php#tiering">How a tier is assigned</a>.
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
    replication, listed with the assay records below; that is carried as a flag and never
    as a tier.
  <?php endif; ?>
</p>
<?php endif; ?>

<h3>Assay records</h3>
<p class="small">
  <?= h((string) count($evidence)) ?> experimental record<?= count($evidence) === 1 ? '' : 's' ?>
recorded for this site. Each record is an individual assay reported by the cited study.
</p>
<table>
  <thead><tr><th>Method</th><th>Assayed interval</th><th class="num">Width</th><th>Detail</th><th>Strength</th><th>Reference</th></tr></thead>
  <tbody>
  <?php foreach ($evidence as $e): ?>
    <tr>
      <td><?= h($e['method']) ?></td>
      <td class="mono"><?= h($e['chromosome']) ?>:<?= h(bp($e['start'])) ?>&ndash;<?= h(bp($e['end'])) ?></td>
      <td class="num"><?= h(bp((int) $e['end'] - (int) $e['start'] + 1)) ?></td>
      <td><?= $e['detail'] !== '' ? h($e['detail']) : '<span class="small">&ndash;</span>' ?></td>
      <td><?= ($e['strength'] ?? '') !== '' ? h($e['strength']) : '<span class="small">&ndash;</span>' ?></td>
      <td><?= cite_pmid((string) $e['pmid']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="small">
  Strength is the grade the cited study gave a positive ARS assay (strong, weak or very
  weak colony growth). A dash means the study reported no grade, not that the assay was weak.
</p>

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
        'dataset' => 'mcguffee2013_okseq',
        'column'  => 'okseq_call',
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
        'dataset' => 'muller2014_trep',
        'column'  => 'replication_time_call',
    ],
];
// $showDynamics is set at the top of the page: the assays are cerevisiae-wide, so
// the section is shown for every cerevisiae origin, with or without values - a
// missing value is itself a result - and omitted where no such dataset is loaded.
?>
<?php if ($showDynamics): ?>
<h2 id="dynamics">Replication dynamics</h2>
<p class="small">
  How often this origin fires, and when the surrounding DNA is replicated, read from
  genome-wide datasets re-analysed on <?= h($origin['assembly']) ?>.
  <a href="about.php#dynamics">How a value is attached to an origin, and why a blank is
  not a zero</a>.
</p>
<table>
  <thead><tr><th>Measurement</th><th class="num">Value</th><th>Assigned call</th>
             <th class="num">Distance</th><th>Assay</th></tr></thead>
  <tbody>
  <?php foreach ($dyn as $d): ?>
    <tr>
      <td><?= h($d['label']) ?>
          <span class="small"><?= h($d['unit']) ?></span></td>
      <?php
      // A value can be absent for two different reasons, and they are not the
      // same statement: the experiment resolved no call here at all, or it
      // resolved one that belongs to a neighbouring origin. The tier table
      // above only asks the first question, so without this the two tables
      // appear to contradict each other.
      $near = null;
      $claimedBy = null;
      if ($d['value'] === '') {
          foreach ($support as $r) {
              if ($r['dataset_id'] === $d['dataset'] && $r['supported'] === 'yes') {
                  $near = $r;
                  $claimedBy = Data::callOwner($d['column'], $r['call']);
                  break;
              }
          }
      }
      ?>
      <td class="num">
        <?php if ($d['value'] !== ''): ?>
          <strong><?= h(($d['format'])((string) $d['value'])) ?></strong>
        <?php elseif ($near !== null): ?>
          <span class="small">not assigned</span>
        <?php else: ?>
          <span class="small">not resolved</span>
        <?php endif; ?>
      </td>
      <td class="mono">
        <?php if ($d['call'] !== ''): ?>
          <?= h($d['call']) ?>
        <?php elseif ($near !== null): ?>
          <?= h($near['call']) ?>
          <span class="small">
            <?php if ($claimedBy !== null): ?>
              nearby, but assigned to
              <a href="<?= h(u('origin.php', ['id' => $claimedBy])) ?>"><?= h($claimedBy) ?></a>
            <?php else: ?>
              nearby, but nearer to another origin than that origin is to it
            <?php endif; ?>
          </span>
        <?php else: ?>
          <span class="small">&ndash;</span>
        <?php endif; ?>
      </td>
      <td class="num"><?= $d['dist'] !== ''
            ? h(bp((int) $d['dist'])) . ' bp'
            : ($near !== null ? '<span class="small">(' . h(bp((int) $near['distance_bp'])) . ' bp)</span>'
                              : '<span class="small">&ndash;</span>') ?></td>
      <td><?= h($d['assay']) ?><br><span class="small"><?= cite_pmid($d['pmid']) ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="small">
  A blank is not a zero, and the two blanks mean different things: <em>not resolved</em> is
  an experiment that produced no call here, <em>not assigned</em> is a call that lies in
  range but belongs to a neighbour.
  <a href="about.php#dynamics">What these values do and do not say</a>; both datasets are
  browsable in the <a href="datahub.php">data hub</a>.
</p>
<?php endif; ?>

<h2 id="elements">Origin elements</h2>
<?php
// $elements is read at the top of the page, for the summary block.
// Only an element with a motif window can be aligned under a logo, and only the
// two classes with a matrix have one to be aligned against. Everything else - a
// B3, or a literature element published without a scored window - is still
// placed, drawn and listed; it simply has no panel. Bucketing it with the A
// elements, which is what an "is it B2?" test did, drew a B3 as an A element.
$byClass = ['A' => [], 'B2' => []];
$classCounts = [];
foreach ($elements as $e) {
    $class = (string) ($e['element_class'] ?? 'A');
    $classCounts[$class] = ($classCounts[$class] ?? 0) + 1;
    if (isset($byClass[$class]) && $e['motif_window'] !== '' && $e['start'] !== '') {
        $byClass[$class][] = $e;
    }
}
ksort($classCounts);
$unplaced = array_filter($elements, static fn (array $e): bool => $e['start'] === '');
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
    at this origin:
    <?= h(implode(', ', array_map(
        static fn (string $c, int $n): string => $n . ' ' . $c,
        array_keys($classCounts),
        $classCounts
    ))) ?>.
    <a href="about.php#elements">What A and B elements are, how they are located and what
    the scores mean</a>.
  </p>

  <?php
  $figure = element_overview_svg($origin, $elements, 'elfig-' . $accession);
  $unplacedCount = count($unplaced);
  ?>
  <?php if ($figure !== ''): ?>
    <figure class="elfig-wrap">
      <?= $figure ?>
      <figcaption class="small">
        Elements drawn to scale across the origin interval, A above the line and the B
        elements below it, each class in its own colour. Dashed
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
      $b1A = motif_region(motif_regions($motifA), 'b1'); ?>
    <h3>A and B1 elements</h3>
    <p class="small">
      <?= h((string) count($byClass['A'])) ?> element<?= count($byClass['A']) === 1 ? '' : 's' ?>
      aligned on the A core in the <?= h((string) $motifA['width']) ?> bp window, which spans
      the A element<?= $b1A !== null ? ' and the B1 region' : '' ?>; confirmed elements are in
      bold.
      <a href="about.php#elements">What the panel shows, and how the score is computed</a>.
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
      $motifB = $motifs['B2']; ?>
    <h3>B2 elements</h3>
    <p class="small">
      <?= h((string) count($byClass['B2'])) ?> element<?= count($byClass['B2']) === 1 ? '' : 's' ?>
      scored against the B2 matrix, which is a different motif from the ACS and not
      comparable with it. Shading and scores read as in the panel above.
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

  <details class="longtail">
  <summary><strong>Element records</strong>
    <span class="small"><?= h((string) count($elements)) ?> record<?= count($elements) === 1 ? '' : 's' ?>,
    with coordinates, strand, study and placement</span></summary>
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
  </details>
<?php endif; ?>

<h2 id="identifiers">Identifiers and provenance</h2>
<dl class="facts">
  <dt>Systematic name</dt><dd class="mono"><?= h($origin['systematic']) ?></dd>
  <?php if ($origin['other_names'] !== ''): ?>
    <dt>Other names</dt><dd><?= h(str_replace(',', ', ', $origin['other_names'])) ?></dd>
  <?php endif; ?>
  <dt>OriDB v2 record</dt><dd class="mono"><?= h($origin['legacy_id']) ?></dd>
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
  <dt>Coordinates</dt>
  <dd class="small"><?= h(Data::release()['coordinate_convention']) ?>;
      the accession is permanent, <a href="about.php">as explained on About</a>.</dd>
</dl>

<h2 id="data">Data for this origin</h2>
<p class="small">
  <a href="<?= h(u('api/origin.php', ['id' => $accession])) ?>">JSON record</a> for this
  origin, the same fields the pages above are drawn from. The whole release is on the
  <a href="downloads.php">downloads page</a>, and OriDB's records are also a browser track
  in the <a href="datahub.php">data hub</a>.
</p>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
