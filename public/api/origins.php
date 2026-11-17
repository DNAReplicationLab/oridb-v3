<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$rows = Data::query(
    species: isset($_GET['species']) ? (string) $_GET['species'] : null,
    chromosome: isset($_GET['chr']) ? (string) $_GET['chr'] : null,
    text: isset($_GET['q']) ? (string) $_GET['q'] : null,
    from: isset($_GET['from']) ? (int) $_GET['from'] : null,
    to: isset($_GET['to']) ? (int) $_GET['to'] : null,
);

echo json_encode([
    'release' => Data::release()['release'],
    'count'   => count($rows),
    'origins' => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
