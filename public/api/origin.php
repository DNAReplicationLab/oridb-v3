<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$accession = (string) ($_GET['id'] ?? '');
$origin = $accession !== '' ? Data::origin($accession) : null;

if ($origin === null) {
    http_response_code(404);
    echo json_encode(['error' => 'not found', 'accession' => $accession]);
    exit;
}

$origin['evidence_records'] = Data::evidenceFor($accession);
$origin['elements'] = Data::elementsFor($accession);
$origin['references'] = [];
foreach ($origin['evidence_records'] as $e) {
    $study = Data::study($e['pmid']);
    if ($study !== null) {
        $origin['references'][$study['pmid']] = $study;
    }
}
$origin['references'] = array_values($origin['references']);

echo json_encode($origin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
