<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';
http_response_code(404);
$title = 'Not found';
require ORIDB_ROOT . '/src/header.php';
?>
<h1>Not found</h1>
<p>That page does not exist in the trial site. Try <a href="browse.php">Browse</a> or <a href="search.php">Search</a>.</p>
<?php require ORIDB_ROOT . '/src/footer.php'; ?>
