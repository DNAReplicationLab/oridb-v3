<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
$release = Data::release();
$hub = Data::hub();
$title = 'Development';
require ORIDB_APP_DIR . '/header.php';
?>
<h1>Development</h1>
<p class="lede">
  How this site is being built, and what is still outstanding. What the database contains and
  how to read it is on <a href="about.php">About</a>.
</p>
<p class="small">
  This is a trial release: <?= h($release['release']) ?>, generated
  <?= h($release['generated']) ?>. The URLs are not yet stable and the site is here to be
  argued with. Source, issues and history:
  <a href="https://github.com/DNAReplicationLab/oridb-v3">github.com/DNAReplicationLab/oridb-v3</a>.
</p>

<h2>Method notes</h2>
<p class="small">
  Each derived layer carries a written method in the repository, recording how it was
  produced and what it does and does not claim. They are kept with the code so that a
  number on this site can be traced to the procedure that produced it.
</p>
<ul class="small">
  <li><a href="https://github.com/DNAReplicationLab/oridb-v3/blob/main/docs/acs-motif.md">The ACS motif</a> &mdash; how the matrix was derived
      from the confirmed A elements, the window, and how elements are scored.</li>
  <li><a href="https://github.com/DNAReplicationLab/oridb-v3/blob/main/docs/origin_activity_timing.md">Origin activity and replication time</a>
      &mdash; how each origin takes an Ok-seq activity and a median replication time from
      the nearest call, the mutual-nearest rule and distance caps that stop a
      neighbouring origin&rsquo;s value being attributed, and why a blank is not a
      zero.</li>
  <li><a href="https://github.com/DNAReplicationLab/oridb-v3/blob/main/docs/tier-rebuild.md">How a site is tiered</a> &mdash; the dataset
      registry, where the MCM rank cut came from, why a negative ARS assay is a flag
      and not a tier, and which later changes are a rerun rather than a migration.</li>
  <li><a href="https://github.com/DNAReplicationLab/oridb-v3/blob/main/docs/tier-evidence-proposal.md">Rebuilding the origin tiers</a> &mdash; the
      evidence axes, the measured resolution of every candidate study, and the merge
      rule.</li>
  <li><a href="https://github.com/DNAReplicationLab/oridb-v3/blob/main/docs/origin_page_changes.md">The origin page</a> &mdash; how the elements
      section is laid out and why.</li>
</ul>

<h2>How the site is built</h2>
<ul>
  <li><strong>Flat files, read through one accessor.</strong> The release is a set of TSV files
      plus <span class="mono">release.json</span>, and <span class="mono">public/app/Data.php</span>
      is the only code that knows where they live. When a database back end arrives, its methods
      are re-implemented against it and no page changes.</li>
  <li><strong>No framework, no JavaScript, no build step for the front end.</strong> One
      stylesheet. The 2012 site is largely unusable today because of its dependencies &mdash; a
      Flash shim, a charting library pinned at a 2010 version, a CDN copy of jQuery, browser
      conditional comments. Anything added back has to be needed by a page: a genome browser will
      want JavaScript, a table of a few hundred rows does not.</li>
  <li><strong>Application code and data sit inside the web root with HTTP access denied.</strong>
      Shared hosts commonly jail PHP to the document root, where files above it do not merely fail
      a permission check but do not exist. A layout that cannot be deployed is not the better
      layout, so <span class="mono">app/</span> and <span class="mono">data/</span> live inside it
      behind a deny-all rule.</li>
  <li><strong>Nothing on a page states a fact of its own.</strong> Counts, assembly names, motif
      geometry and the track list are read from the release files and from the hub's own
      configuration. A page that hard-codes a count or an assembly name fails the test suite,
      because that is how the old site came to contradict its own data.</li>
  <li><strong>Assets carry a content hash.</strong> Stylesheet and logo URLs are generated with a
      short hash of the file, so a changed file arrives as a new URL instead of waiting for
      someone's cache to expire.</li>
  <li><strong>One template set, two outputs.</strong> The same PHP templates render the live site
      and a flat-HTML mirror for review, so the preview cannot drift from the site.</li>
</ul>
<p class="small">
  The checks that run before anything is deployed render every page with ordinary and hostile
  parameters &mdash; script tags, SQL fragments, directory traversal, negative page numbers
  &mdash; and fail on any PHP diagnostic, any empty response, any reflected unescaped input, any
  read from outside the web root, and any hard-coded count, assembly name or motif geometry.
</p>

<h2 id="known-issues">Known issues in this release</h2>
<p class="lede">
  Recorded openly rather than quietly: each entry below is a question a curator still has to
  answer, and the records it affects are linked so they can be inspected directly.
</p>
<ul>
<?php foreach ($release['known_issues'] as $issue): ?>
  <?php
  // Entries are objects carrying affected accessions; tolerate a plain string too.
  $text = is_array($issue) ? ($issue['text'] ?? '') : $issue;
  $accessions = is_array($issue) ? ($issue['accessions'] ?? []) : [];
  ?>
  <li>
    <?= h($text) ?>
    <?php if ($accessions !== []): ?>
      <div class="small" style="margin-top:.35rem">
        <?php foreach ($accessions as $i => $acc): ?>
          <a class="mono" href="<?= h(u('origin.php', ['id' => $acc])) ?>"><?= h($acc) ?></a><?php
          $o = Data::origin($acc);
          if ($o !== null && $o['name'] !== '') { echo ' (' . h($o['name']) . ')'; }
          echo $i < count($accessions) - 1 ? ', ' : '';
        ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>

<h2>What is deliberately not here yet</h2>
<ul>
  <li><strong>The Likely and Dubious tiers.</strong> They rest on genome-wide mapping data whose
      assemblies and thresholds are a larger problem than the Confirmed tier, and they are being
      rebuilt from current data rather than carried across.</li>
  <li><strong>OriDB's own origin intervals as a browser track.</strong> The
      <a href="datahub.php">data hub</a> exists and serves the genome-wide profiles behind the
      origin calls<?php if ($hub !== [] && isset($hub['counts'])): ?>
      (<?= h((string) $hub['counts']['datasets']) ?> dataset<?= (int) $hub['counts']['datasets'] === 1 ? '' : 's' ?>
      so far)<?php endif; ?>, but the origin records themselves are not in it.</li>
  <li><strong>A database back end.</strong> The trial is flat files on purpose: it keeps the
      release reproducible and checksummed while the schema is still moving.</li>
  <li><strong>Stable URLs.</strong> Accessions are permanent; the paths that serve them are not
      settled yet.</li>
</ul>

<h2>Working on it</h2>
<p class="small">
  The data files are regenerated from the OriDB v2 dump by the scripts in
  <span class="mono">bin/</span>, which also lift the cerevisiae coordinates onto the current
  assembly and check every lifted interval against the sequence. Checksums of the generated files
  are recorded in <span class="mono">release.json</span>, so a deposited copy can be proved
  identical to the served one. The data hub page is generated separately from the hub repository's
  own configuration, because the hub has its own release cycle.
</p>
<p class="small">
  Corrections to individual records, and arguments about what belongs in which tier, are the most
  useful thing a reader can send: the point of publishing the evidence records and the known
  issues is that both can be checked.
</p>
<?php require ORIDB_APP_DIR . '/footer.php'; ?>
