<?php

use function Maintenance\Case1\After\quantityLabel as fixedLabel;
use function Maintenance\Case1\Before\quantityLabel as legacyLabel;

require __DIR__.'/before.php';
require __DIR__.'/after.php';

$mode = $argv[1] ?? 'after';
if (! in_array($mode, ['before', 'after'], true)) {
    fwrite(STDERR, "Usage: php check.php [before|after]\n");
    exit(2);
}
$format = $mode === 'before' ? legacyLabel(...) : fixedLabel(...);
$cases = [
    'inventory zero (int)' => [['quantity' => 0], '0'],
    'CSV zero (string)' => [['quantity' => '0'], '0'],
    'ordinary quantity' => [['quantity' => 12], '12'],
    'null field' => [['quantity' => null], 'missing'],
    'empty cell' => [['quantity' => ''], 'missing'],
    'absent key' => [[], 'missing'],
];

printf("Probe: (string) 0 = %s; empty(0) = %s; empty('0') = %s\n", (string) 0, var_export(empty(0), true), var_export(empty('0'), true));
$failures = 0;
foreach ($cases as $name => [$data, $expected]) {
    $actual = $format($data);
    if ($actual !== $expected) {
        printf("FAIL %s: expected %s, got %s\n", $name, $expected, $actual);
        $failures++;

        continue;
    }
    printf("PASS %s: %s\n", $name, $actual);
}
printf("%s: %d/%d passed\n", $mode, count($cases) - $failures, count($cases));
exit($failures === 0 ? 0 : 1);
