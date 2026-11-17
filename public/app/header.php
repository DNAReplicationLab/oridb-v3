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
<link rel="stylesheet" href="assets/style.css">
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
      <a href="studies.php">Studies</a>
      <a href="about.php">About</a>
    </nav>
  </div>
</header>
<div class="banner wrap">
  Trial release <strong><?= h($release['release']) ?></strong> &mdash; Confirmed origins only.
  Coordinates are <strong>not</strong> lifted to current assemblies; see <a href="about.php">About</a>.
</div>
<main id="main" class="wrap">
