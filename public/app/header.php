<?php
declare(strict_types=1);
$release = Data::release();
$title = $title ?? 'OriDB';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> &middot; OriDB</title>
<link rel="stylesheet" href="<?= h(Data::asset('assets/style.css')) ?>">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="site">
  <div class="wrap">
    <a class="brand" href="index.php">OriDB<span class="ver">v3.0 trial</span></a>
    <nav>
      <a href="browse.php">Browse</a>
      <a href="search.php">Search</a>
      <a href="downloads.php">Downloads</a>
      <a href="datahub.php">Data hub</a>
      <a href="studies.php">Studies</a>
      <a href="about.php">About</a>
      <a href="development.php">Development</a>
    </nav>
  </div>
</header>
<?php
// Derived from the release metadata, never asserted: a hard-coded claim about the
// assemblies went stale the moment the data was lifted.
$assembly_summary = [];
$builds = $release['assemblies'];
uasort($builds, static fn (array $a, array $b): int => strcmp($a['species'], $b['species']));
foreach ($builds as $build => $meta) {
    $parts = explode(' ', $meta['species']);
    $abbrev = count($parts) > 1 ? substr($parts[0], 0, 1) . '. ' . $parts[1] : $meta['species'];
    $assembly_summary[] = '<em>' . h($abbrev) . '</em> on ' . h($build);
}
?>
<div class="banner wrap">
  Trial release <strong><?= h($release['release']) ?></strong> &mdash; every OriDB origin, with its tier recomputed.
  Coordinates: <?= implode(', ', $assembly_summary) ?>.
  See <a href="about.php">About</a> for scope and known issues.
</div>
<main id="main" class="wrap">
