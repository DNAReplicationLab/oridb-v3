<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

/**
 * Everything on this page comes from public/data/datahub.json, which
 * bin/build_datahub.php generates from the hub repository's own hub.txt, trackDb chain
 * and dataset.json. Nothing about the hub is written here by hand: a track added to the
 * hub appears here on the next build, and one removed disappears.
 */
$hub = Data::hub();
$title = 'Data hub';
require ORIDB_APP_DIR . '/header.php';

if ($hub === []):
    ?>
<h1>Data hub</h1>
<p class="lede">The hub description has not been built in this checkout.</p>
<p>The hub lives in its own repository. Run
  <span class="mono">php bin/build_datahub.php --hub-root /path/to/oridb-data-hub</span>
  to generate <span class="mono">public/data/datahub.json</span>, and this page will
  describe whatever the hub currently serves.</p>
<?php
else:
$meta = $hub['hub'];
$counts = $hub['counts'];
$plural = static fn (int $n, string $one, ?string $many = null): string => $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
$bytes = static function (int $n): string {
    return $n >= 1048576 ? number_format($n / 1048576, 1) . ' MB' : number_format(max(1, (int) round($n / 1024))) . ' kB';
};
?>
<h1>Data hub</h1>
<p class="lede">
  <strong><?= h($meta['shortLabel']) ?></strong> &mdash; <?= h($meta['longLabel']) ?>.
  <?= h($plural((int) $counts['tracks'], 'track')) ?> from
  <?= h($plural((int) $counts['datasets'], 'dataset')) ?>, on
  <?= h($plural((int) $counts['genomes'], 'assembly', 'assemblies')) ?>. These are the
  genome-wide profiles behind the origin records: they are served as a UCSC track hub so
  that a reader can look at the data a call was made from, rather than at the call alone.
</p>

<h2>Load it in the UCSC browser</h2>
<p>Paste this URL into the
  <a href="https://genome.ucsc.edu/cgi-bin/hgHubConnect">Track Hubs</a> page
  (<em>My Hubs</em> tab, then <em>Add Hub</em>):</p>
<p class="mono"><?= h($meta['url']) ?></p>
<p>Or open an assembly directly, which adds the hub and takes you straight to the tracks:</p>
<ul>
  <?php foreach ($hub['genomes'] as $g): ?>
  <li><a href="<?= h('https://genome.ucsc.edu/cgi-bin/hgTracks?db=' . rawurlencode($g['genome'])
        . '&hubUrl=' . rawurlencode($meta['url'])) ?>"><?= h($g['genome']) ?> with the hub loaded</a></li>
  <?php endforeach; ?>
</ul>
<p class="small">
  UCSC fetches the files itself, re-reading them only when they change, so a track may
  take a few minutes to update after the hub does. The files are served over HTTPS with
  byte-range support, which is what the browser needs to read a region out of a bigWig
  without downloading it.
</p>

<?php foreach ($hub['genomes'] as $g): ?>
<h2>Tracks on <?= h($g['genome']) ?></h2>
<?php foreach ($g['datasets'] as $d): ?>
<p><strong><?= h($d['title']) ?></strong></p>
<table>
  <tbody>
    <tr><th>Organism</th><td><em><?= h($d['organism']) ?></em></td></tr>
    <tr><th>Assembly</th><td><?= h($d['assembly']) ?></td></tr>
    <?php if ($d['measurement'] !== ''): ?>
    <tr><th>Measurement</th><td><?= h($d['measurement']) ?><?= $d['scale'] !== '' ? ' (' . h($d['scale']) . ')' : '' ?></td></tr>
    <?php endif; ?>
    <?php if ($d['bin_size_bp'] !== null): ?>
    <tr><th>Bin size</th><td><?= h(bp((int) $d['bin_size_bp'])) ?>&nbsp;bp</td></tr>
    <?php endif; ?>
    <tr><th>Source</th>
        <td>
          <?php if ($d['source']['doi'] !== ''): ?>
          <a href="<?= h('https://doi.org/' . $d['source']['doi']) ?>"><?= h($d['source']['doi']) ?></a>
          <?php endif; ?>
          <?php if ($d['source']['pubmed'] !== ''): ?>
          &middot; <a href="<?= h('https://pubmed.ncbi.nlm.nih.gov/' . $d['source']['pubmed'] . '/') ?>">PMID&nbsp;<?= h($d['source']['pubmed']) ?></a>
          <?php endif; ?>
          <?php foreach ($d['source']['archive_series'] as $acc): ?>
          &middot; <a href="<?= h('https://www.ncbi.nlm.nih.gov/geo/query/acc.cgi?acc=' . rawurlencode($acc)) ?>"><?= h($acc) ?></a>
          <?php endforeach; ?>
        </td></tr>
    <?php
    // A dataset describes its input either as per-sample records or, when the samples
    // are not tracked individually, as the archive runs it was built from. Say whichever
    // the dataset carries rather than assuming every dataset is described the same way.
    $nRuns = (int) ($d['source']['n_runs'] ?? 0);
    $nUsed = (int) ($d['source']['n_samples_used'] ?? 0);
    $nExcluded = (int) ($d['source']['n_samples_excluded'] ?? 0);
    ?>
    <?php if ($d['n_samples'] > 0): ?>
    <tr><th>Samples</th>
        <td><?= h((string) $d['n_samples']) ?> sequencing samples
          (<?= h((string) $nRuns) ?> archive runs), reprocessed onto
          <?= h($d['assembly']) ?> by this project's pipeline</td></tr>
    <?php elseif ($nRuns > 0): ?>
    <tr><th>Sequencing data</th>
        <td><?= h($plural($nRuns, 'archive run')) ?><?php if ($nUsed > 0): ?>
          covering <?= h($plural($nUsed, 'sample')) ?><?php endif; ?>,
          reprocessed onto <?= h($d['assembly']) ?> by this project's pipeline<?php
          if ($nExcluded > 0): ?>.
          <span class="small"><?= h($plural($nExcluded, 'sample')) ?> in the series
          <?= $nExcluded === 1 ? 'was' : 'were' ?> excluded<?= ($d['source']['exclusion_reason'] ?? '') !== ''
              ? ': ' . h($d['source']['exclusion_reason']) : '' ?>.</span>
          <?php endif; ?></td></tr>
    <?php endif; ?>
    <?php if (($d['calls'] ?? null) !== null && $d['calls']['definition'] !== ''): ?>
    <tr><th>Calls</th>
        <td><?= h($d['calls']['definition']) ?>
          <?php if ($d['calls']['caveat'] !== ''): ?>
            <br><span class="small">Caveat: <?= h($d['calls']['caveat']) ?></span>
          <?php endif; ?></td></tr>
    <?php endif; ?>
  </tbody>
</table>

<table>
  <thead><tr><th>Track group</th><th class="num">Tracks</th><th>What it shows</th><th>Files</th></tr></thead>
  <tbody>
  <?php foreach ($d['groups'] as $grp): ?>
    <tr>
      <td><?= h($grp['shortLabel']) ?></td>
      <td class="num"><?= h((string) count($grp['tracks'])) ?></td>
      <td class="small"><?= h($grp['longLabel']) ?>
        <?php if ($grp['container'] !== '' && count($grp['tracks']) > 1): ?><br><span class="small">drawn as one overlaid track</span><?php endif; ?>
      </td>
      <td class="small mono">
        <?php foreach ($grp['tracks'] as $t): ?>
          <?php if ($t['url'] !== ''): ?><a href="<?= h($t['url']) ?>"><?= h($t['file']) ?></a><?php else: ?><?= h($t['file']) ?><?php endif; ?>
          <?= (int) $t['bytes'] > 0 ? h('(' . $bytes((int) $t['bytes']) . ')') : '' ?>
          <?php if (isset($t['rows'])): ?>
            &mdash; <?= h((string) $t['rows']) ?> features, also as
            <a href="<?= h($t['bed_url']) ?>"><?= h($t['bed']) ?></a>
          <?php endif; ?>
          <br>
        <?php endforeach; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endforeach; ?>
<?php endforeach; ?>

<h2>Reuse</h2>
<p class="small">
  The files are static and may be read directly &mdash; by
  <span class="mono">pyBigWig</span>, <span class="mono">rtracklayer</span>, IGV, JBrowse
  or anything else that speaks bigWig and bigBed &mdash; using the URLs above. Each
  dataset directory carries a <span class="mono">dataset.json</span> recording its source
  accessions, per-sample statistics and file checksums. Hub configuration and tooling:
  <a href="<?= h($meta['repo_url']) ?>"><?= h($meta['repo_url']) ?></a>.
</p>
<p class="small">
  This page is generated from the hub's own configuration files
  (<?= h(substr((string) $hub['generated'], 0, 10)) ?>), so it describes what the hub
  serves rather than what someone last wrote down.
</p>
<?php endif; ?>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
