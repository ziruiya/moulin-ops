<?php

namespace App\Services;

/**
 * Compares numeric dotted versions and comma-separated constraints such as
 * ">=8.0,<8.1.31". Each clause is an operator (>=, <=, >, <, =) followed
 * by a numeric dotted version. Prerelease/vendor formats are intentionally
 * unresolved; callers must not turn that result into a safety conclusion.
 */
class VersionComparator
{
    /** @return array{resolvable: bool, affected: ?bool, reason: ?string} */
    public function compare(?string $version, ?string $expression): array
    {
        if (! $this->valid($version) || ! is_string($expression) || trim($expression) === '') {
            return ['resolvable' => false, 'affected' => null, 'reason' => 'Version or range is missing or unsupported.'];
        }

        $clauses = explode(',', $expression);
        foreach ($clauses as $clause) {
            if (! preg_match('/^\s*(>=|<=|>|<|=)\s*(\d+(?:\.\d+)*)\s*$/', $clause, $m)) {
                return ['resolvable' => false, 'affected' => null, 'reason' => 'Unsupported range expression.'];
            }
            $comparison = version_compare($version, $m[2]);
            $matches = match ($m[1]) {
                '>=' => $comparison >= 0, '<=' => $comparison <= 0,
                '>' => $comparison > 0, '<' => $comparison < 0,
                '=' => $comparison === 0,
            };
            if (! $matches) {
                return ['resolvable' => true, 'affected' => false, 'reason' => null];
            }
        }

        return ['resolvable' => true, 'affected' => true, 'reason' => null];
    }

    private function valid(?string $version): bool
    {
        return is_string($version) && preg_match('/^\d+(?:\.\d+)*$/', trim($version)) === 1;
    }
}
