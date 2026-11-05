<?php
/**
 * Both template sets (templates/ = redesigned, templates/classic/ = frozen
 * classic) run the same JavaScript, which finds its elements by id. Every id of
 * the classic set must therefore exist in the redesigned set too (it may move
 * to another file, but not disappear), and no id may appear more often than in
 * classic (a duplicated id makes getElementById pick the wrong element).
 * The same holds for tab targets (data-bs-target) and form field names.
 * Run: php tests/ui_parity_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; } else { $failed++; echo "[FAIL] $m\n"; } }

/** @return array<string,int> value => occurrences, over every template file of a set */
function collect(string $dir, string $attr): array {
    $counts = [];
    foreach (glob($dir . '/*.php') as $file) {
        // Ids only the redesigned set needs are allowed; PHP-built values are skipped.
        preg_match_all('/\s' . preg_quote($attr, '/') . '="([^"<>]+)"/', (string)file_get_contents($file), $m);
        foreach ($m[1] as $v) {
            $counts[$v] = ($counts[$v] ?? 0) + 1;
        }
    }
    return $counts;
}

// Classic-only controls the redesign replaced on purpose (their JS skips modern pages).
$classicOnly = [
    'dark-mode-toggle' => 'replaced by the theme picker (js/theme.js)',
    'dark-mode-icon' => 'replaced by the theme picker (js/theme.js)',
];

$classicDir = $root . '/templates/classic';
$modernDir = $root . '/templates';
expect_true(is_dir($classicDir), 'templates/classic exists');

foreach (['id' => 'id', 'data-bs-target' => 'tab/collapse target', 'name' => 'form field name'] as $attr => $label) {
    $classic = collect($classicDir, $attr);
    $modern = collect($modernDir, $attr);
    $missing = $dup = 0;
    foreach ($classic as $value => $n) {
        $m = $modern[$value] ?? 0;
        if ($attr === 'id' && isset($classicOnly[$value])) {
            expect_true($m === 0, "«{$value}» is classic-only ({$classicOnly[$value]})");
        } elseif ($m === 0) {
            $missing++;
            expect_true(false, "$label «{$value}» of the classic templates is missing in templates/");
        } elseif ($attr === 'id' && $m > $n) {
            $dup++;
            expect_true(false, "$label «{$value}» appears {$m}x in templates/ but {$n}x in classic");
        } else {
            expect_true(true, $value);
        }
    }
    echo sprintf("%-20s classic %4d, redesigned %4d, missing %d, duplicated %d\n", $label, count($classic), count($modern), $missing, $dup);
}

// Every template the page includes exists in both sets.
$index = (string)file_get_contents($root . '/index.php');
preg_match_all("/include \\\$tpl \\. '\\/([a-z_]+\\.php)'/", $index, $m);
expect_true(count($m[1]) > 10, 'index.php includes its templates through $tpl');
foreach ($m[1] as $file) {
    expect_true(is_file("$classicDir/$file"), "classic/$file exists");
    expect_true(is_file("$modernDir/$file"), "$file exists");
}

echo "\nPassed: $passed, Failed: $failed\n";
exit($failed ? 1 : 0);
