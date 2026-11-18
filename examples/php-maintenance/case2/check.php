<?php

use function Maintenance\Case2\After\journalDate as fixedDate;
use function Maintenance\Case2\Before\journalDate as legacyDate;

require __DIR__.'/before.php';
require __DIR__.'/after.php';

$mode = $argv[1] ?? 'after';
if (! in_array($mode, ['before', 'after'], true)) {
    fwrite(STDERR, "Usage: php check.php [before|after]\n");
    exit(2);
}
$group = $mode === 'before' ? legacyDate(...) : fixedDate(...);
$boundary = '2026-11-18T15:00:00Z';
$defaultZone = date_default_timezone_get();
foreach (['UTC', 'Asia/Tokyo'] as $zone) {
    date_default_timezone_set($zone);
    printf("Probe: legacy result with default %s = %s\n", $zone, legacyDate($boundary));
}
date_default_timezone_set($defaultZone);

$cases = [
    'ordinary time' => ['2026-11-18T03:00:00Z', '2026-11-18'],
    'one second before JST midnight' => ['2026-11-18T14:59:59Z', '2026-11-18'],
    'JST midnight' => [$boundary, '2026-11-19'],
    'JST year boundary' => ['2026-12-31T15:00:00Z', '2027-01-01'],
];
$failures = 0;
foreach ($cases as $name => [$data, $expected]) {
    $actual = $group($data);
    if ($actual !== $expected) {
        printf("FAIL %s: expected %s, got %s\n", $name, $expected, $actual);
        $failures++;

        continue;
    }
    printf("PASS %s: %s\n", $name, $actual);
}
printf("%s: %d/%d passed\n", $mode, count($cases) - $failures, count($cases));
exit($failures === 0 ? 0 : 1);
