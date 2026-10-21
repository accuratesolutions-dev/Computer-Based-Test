<?php
// Class levels (no database access, so it can be unit-tested on its own).

function levels_for_section($junior)
{
    return $junior ? ['J.S.S 1', 'J.S.S 2', 'J.S.S 3'] : ['S.S.S 1', 'S.S.S 2', 'S.S.S 3'];
}

// Accepts "jss1", "J.S.S 2", "SSS 3", "s.s.s3"... and returns the canonical label for the given section.
function canonical_level($value, $junior)
{
    if (preg_match('/^([js])\.?\s*s\.?\s*s?\.?\s*([123])$/i', trim((string) $value), $m)) {
        $isJunior = strtolower($m[1]) === 'j';
        if ($isJunior === (bool) $junior) {
            return levels_for_section($junior)[(int) $m[2] - 1];
        }
    }
    return null;
}
