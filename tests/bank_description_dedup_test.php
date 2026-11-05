<?php
/**
 * A payment registered from a bank line gets «[جزئیات بانک] …» appended to its
 * description. The block must not repeat what the description already says:
 * the bank text itself, the counterparty name, or a number shown twice
 * (Blu bank files carry the same value as reference and document number).
 * Run: php tests/bank_description_dedup_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/BaseModel.php';
require_once $root . '/api/models/BankImport.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }
$count = fn(string $hay, string $needle) => mb_substr_count($hay, $needle, 'UTF-8');

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
$bank = new BankImport($conn);

// The Blu bank row behind payment #3161 (as stored by the importer).
$bankDesc = 'انتقال به سپرده — انتقال به سپرده بلو - شماره سپرده: 611828005148620501 بنام: الهام عماری اله یاری - شماره سند: 14056119053262';
$item = [
    'transaction_date' => '2026-10-16', 'transaction_time' => '15:36:28', 'amount' => -10000000,
    'description' => $bankDesc, 'counterparty' => 'الهام عماری اله یاری',
    'tracking_number' => '', 'reference_number' => '14056119053262', 'document_number' => '14056119053262',
    'branch' => '', 'note' => 'انتقال به سپرده',
];

// «ثبت از ردیف بانک»: the description starts as the bank text.
$out = $bank->composeEnrichedDescription($bankDesc, $item);
expect_true(str_contains($out, '[جزئیات بانک]'), 'the bank block is still added');
expect_true($count($out, 'شماره سپرده: 611828005148620501') === 1, 'the bank text appears once');
expect_true($count($out, 'الهام عماری اله یاری') === 1, 'the counterparty name appears once');
expect_true($count($out, '14056119053262') === 1, 'the document number appears once');
expect_true(!str_contains($out, 'یادداشت: انتقال به سپرده'), 'a note that only repeats the bank text is left out');
expect_true(str_contains($out, 'تاریخ:') && str_contains($out, 'ساعت: 15:36:28') && str_contains($out, 'مبلغ بانک: 10,000,000 (برداشت)'), 'date, time and bank amount are kept');

// A note the user typed is kept.
$withNote = $item + [];
$withNote['note'] = 'انتقال به سپرده - فیلمبرداری و تولید محتوای شمال';
$out = $bank->composeEnrichedDescription($bankDesc, $withNote);
expect_true(str_contains($out, 'یادداشت: انتقال به سپرده - فیلمبرداری و تولید محتوای شمال'), 'a real note is kept');

// Matching an existing hand-written payment: nothing is in its text yet, so all details are added.
$out = $bank->composeEnrichedDescription('پرداخت به الهام بابت کرایه', $item);
expect_true(str_contains($out, 'شرح: ' . $bankDesc), 'the bank text is added when the description lacks it');
expect_true($count($out, 'الهام عماری اله یاری') === 1 && !str_contains($out, '| طرف:'), 'the counterparty is not repeated when the added bank text names it');
expect_true($count($out, '14056119053262') === 1, 'a number already inside the added bank text is not repeated as مرجع / سند');
expect_true(!str_contains($out, '| سند:') && !str_contains($out, '| مرجع:'), 'no separate مرجع / سند for it');
$noText = $item; $noText['description'] = '';
$out = $bank->composeEnrichedDescription('پرداخت به الهام بابت کرایه', $noText);
expect_true($count($out, '14056119053262') === 1 && str_contains($out, 'مرجع: 14056119053262'), 'without bank text the number is shown once, as مرجع');

// An already enriched description is left alone.
$once = $bank->composeEnrichedDescription($bankDesc, $item);
expect_true($bank->composeEnrichedDescription($once, $item) === $once, 'enriching twice changes nothing');

echo "\nPassed: $passed, Failed: $failed\n";
exit($failed ? 1 : 0);
