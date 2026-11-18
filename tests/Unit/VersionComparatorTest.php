<?php

namespace Tests\Unit;

use App\Services\VersionComparator;
use PHPUnit\Framework\TestCase;

class VersionComparatorTest extends TestCase
{
    public function test_it_compares_numeric_versions_and_ranges(): void
    {
        $comparator = new VersionComparator;
        self::assertTrue($comparator->compare('8.10', '>=8.0,<8.11')['affected']);
        self::assertFalse($comparator->compare('8.9', '>=8.10')['affected']);
    }

    public function test_it_returns_unresolved_for_unknown_versions_and_ranges(): void
    {
        $comparator = new VersionComparator;
        self::assertFalse($comparator->compare('unknown', '>=8.0')['resolvable']);
        self::assertFalse($comparator->compare('8.1', 'latest-ish')['resolvable']);
    }
}
