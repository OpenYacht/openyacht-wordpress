<?php

declare(strict_types=1);

// Unit-lane stand-in for WordPress's upgrade.php: records dbDelta calls so
// tests can assert exactly which statements the migration runner executed.
if (! function_exists('dbDelta')) {
    function dbDelta(string $sql): array
    {
        $GLOBALS['openyacht_dbdelta_calls'][] = $sql;

        return [];
    }
}
