<?php
declare(strict_types=1);

/**
 * Recompute the checksums release.json records for the files it ships.
 *
 *     php bin/refresh_release_checksums.php [--check]
 *
 * public/data/release.json carries an md5 and a row count for every released file, and
 * bin/smoke_test.php and the CI workflow fail when one disagrees with the file on disk.
 * That is the point of them: a data file that changed without the release being rebuilt
 * is exactly the defect the checksums exist to catch.
 *
 * It also means the manifest has to be refreshed deliberately, which is what this does.
 * It prints every entry it changes, so the diff is the evidence: one line moving means
 * one file drifted, and a page of lines means something larger happened and should be
 * understood before it is committed.
 *
 * --check makes no changes and exits non-zero if anything disagrees, which is the same
 * question the CI step asks, in a form that can be run by hand.
 */

$root = dirname(__DIR__);
$check = in_array('--check', array_slice($argv, 1), true);

$path = $root . '/public/data/release.json';
$release = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

$changed = [];
$missing = [];
foreach ($release['files'] as $name => $meta) {
    $file = $root . '/public/data/' . $name;
    if (!is_readable($file)) {
        $missing[] = $name;
        continue;
    }
    // Row count excludes the header, which is how release.json has always counted and
    // how the CI step counts; counting every line reports one row too many.
    $md5 = (string) md5_file($file);
    $rows = count(file($file)) - 1;
    if ($md5 !== ($meta['md5'] ?? '') || $rows !== ($meta['rows'] ?? -1)) {
        $changed[$name] = [
            'md5' => [$meta['md5'] ?? '(absent)', $md5],
            'rows' => [$meta['rows'] ?? '(absent)', $rows],
        ];
        $release['files'][$name]['md5'] = $md5;
        $release['files'][$name]['rows'] = $rows;
    }
}

foreach ($missing as $name) {
    fwrite(STDERR, "release.json lists {$name}, which is not in public/data\n");
}
foreach ($changed as $name => $was) {
    printf("%s\n", $name);
    if ($was['md5'][0] !== $was['md5'][1]) {
        printf("    md5  %s -> %s\n", $was['md5'][0], $was['md5'][1]);
    }
    if ($was['rows'][0] !== $was['rows'][1]) {
        printf("    rows %s -> %s\n", (string) $was['rows'][0], (string) $was['rows'][1]);
    }
}

if ($check) {
    printf("%d of %d file(s) disagree with release.json\n",
        count($changed), count($release['files']));
    exit($changed === [] && $missing === [] ? 0 : 1);
}

if ($changed === []) {
    echo "every file matches release.json; nothing written\n";
    exit($missing === [] ? 0 : 1);
}

// Written with the same flags the build scripts use, so refreshing a checksum does not
// reformat the whole file and bury the one line that matters.
file_put_contents($path,
    json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
printf("%d entr%s refreshed in public/data/release.json\n",
    count($changed), count($changed) === 1 ? 'y' : 'ies');
exit($missing === [] ? 0 : 1);
