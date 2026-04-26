<?php

/*
 * CapstoneNMS — source protection pass.
 *
 * Best-effort obfuscation of the licensing-enforcement files. The
 * PHP language is interpreted, so any source we ship is technically
 * readable; this pass exists to make casual editing time-consuming
 * enough that buying a license is the cheaper path. Determined
 * attackers will still be able to crack it, and that's fine — the
 * legal layer (license violation = lawsuit) does the real work.
 *
 * What this script does, file by file:
 *   1. Strip every // and /* comment block via the PHP tokenizer.
 *      Pirates lose the inline documentation that makes the code
 *      readable.
 *   2. Collapse whitespace runs and remove all blank lines so the
 *      file is one long blob. Visual diffing becomes useless.
 *   3. Preserve string literals and heredocs verbatim.
 *
 * Variable / method renaming is intentionally NOT done because
 * we'd need cross-file analysis to keep call sites consistent, and
 * the marginal benefit isn't worth the build-time correctness risk.
 *
 * Usage:
 *   php scripts/protect.php <build-dir>
 */

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "usage: protect.php <build-dir>\n");
    exit(1);
}

$buildDir = rtrim((string) $argv[1], '/');
if (! is_dir($buildDir)) {
    fwrite(STDERR, "build dir not found: {$buildDir}\n");
    exit(1);
}

/** Files containing the licensing-enforcement logic. */
$targets = [
    'app/Services/Licensing/LicenseService.php',
    'app/Services/Licensing/LicenseCapException.php',
    'app/Http/Middleware/EnforceLicense.php',
    'app/Services/Update/UpdateService.php',
    'app/Filament/Pages/License.php',
    // Scattered "secondary" checks — see scripts/protect-secondary.php
    // for the call sites that piggyback on already-extant files.
];

foreach ($targets as $rel) {
    $abs = $buildDir . '/' . $rel;
    if (! is_file($abs)) {
        fwrite(STDERR, "skip (not found): {$rel}\n");
        continue;
    }
    $src = (string) file_get_contents($abs);
    $out = stripCommentsAndWhitespace($src);
    file_put_contents($abs, $out);
    echo "  protected: {$rel}\n";
}

echo "Done.\n";

/**
 * Use the PHP tokenizer so we can drop T_COMMENT / T_DOC_COMMENT
 * without disturbing comment-shaped substrings inside strings or
 * heredocs.
 */
function stripCommentsAndWhitespace(string $src): string
{
    $tokens = token_get_all($src);
    $out = '';
    $lastWasSpace = true;

    foreach ($tokens as $t) {
        if (is_array($t)) {
            [$id, $text] = [$t[0], $t[1]];
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                // Replace with a single space so adjacent tokens don't
                // collide ("public function" → "publicfunction").
                if (! $lastWasSpace) {
                    $out .= ' ';
                    $lastWasSpace = true;
                }
                continue;
            }
            if ($id === T_WHITESPACE) {
                if (! $lastWasSpace) {
                    $out .= ' ';
                    $lastWasSpace = true;
                }
                continue;
            }
            $out .= $text;
            $lastWasSpace = false;
        } else {
            $out .= $t;
            $lastWasSpace = false;
        }
    }

    return $out;
}
