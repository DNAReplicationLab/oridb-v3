<?php
declare(strict_types=1);

/**
 * OriDB v3.0 trial - bootstrap.
 * No database, no Composer, no external dependencies: the trial release reads
 * the versioned flat files in ../data. Swapping in MariaDB later means
 * replacing Data.php only; nothing in public/ touches storage directly.
 */

/**
 * ORIDB_ROOT is the web root. src/ and data/ live INSIDE it, as public/app and
 * public/data, because shared hosts commonly jail PHP to the document root -
 * files above it are not merely unreadable, they do not exist for PHP. Both
 * directories carry a deny-all .htaccess, so they are on disk but not servable.
 */
define('ORIDB_ROOT', dirname(__DIR__));
define('ORIDB_APP_DIR', ORIDB_ROOT . '/app');
define('ORIDB_DATA_DIR', ORIDB_ROOT . '/data');

require_once __DIR__ . '/Data.php';

/** Escape for HTML output. Every value printed in a template goes through this. */
function h(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an internal URL with a query string. */
function u(string $path, array $query = []): string
{
    return $query === [] ? $path : $path . '?' . http_build_query($query);
}

/** Format an integer coordinate with thousands separators. */
function bp(int|string $n): string
{
    return number_format((int) $n);
}

function send_404(string $message = 'Not found'): never
{
    http_response_code(404);
    $title = 'Not found';
    require ORIDB_APP_DIR . '/header.php';
    echo '<h1>Not found</h1><p>' . h($message) . '</p>';
    require ORIDB_APP_DIR . '/footer.php';
    exit;
}

/**
 * CSS modifier for a tier chip. The tiers are ordered by how much is known, and
 * the colours follow that order rather than implying good and bad: a site nobody
 * has looked at is not a failed one.
 */
function tier_class(string $tier): string
{
    return [
        'Confirmed' => 'confirmed',
        'Likely' => 'likely',
        'Unsupported' => 'unsupported',
        'Not assessed' => 'unassessed',
    ][$tier] ?? 'unsupported';
}

