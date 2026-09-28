<?php

/**
 * The transport shim, verbatim from production (bandsintown_events.php).
 *
 * Transport stays this thin on purpose: every line of ingestion logic lives
 * in the pipeline library, so the pipeline is testable without HTTP and the
 * endpoint has nothing to break. In production the require points at the
 * private tour_merger.php; in this repo it would be:
 *
 *   require_once __DIR__ . '/../src/tour-feed-pipeline.php';
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=3600');

// require_once __DIR__ . '/tour_merger.php';

$artist = trim($_GET['artist'] ?? '');
$djId = trim($_GET['dj_id'] ?? '');

if ($artist === '') {
    echo '[]';
    exit;
}

echo json_encode(tourFetchBandsintown($artist, $djId), JSON_UNESCAPED_SLASHES);
