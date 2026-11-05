<?php
/**
 * Person statements show payments registered from bank lines with a short
 * description: the bank's operation title plus the user's own note (or the
 * counterparty when there is no note). Account/IBAN numbers, tracking codes,
 * time and the repeated bank text are left out. Hand-written descriptions
 * stay as they are. Run: php tests/statement_short_description_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once dirname(__DIR__) . '/api/core/statement_text.php';

$passed = 0; $failed = 0;
function expect_same(string $got, string $want, string $m): void {
    global $passed, $failed;
    if ($got === $want) { $passed++; echo "[PASS] $m\n"; }
    else { $failed++; echo "[FAIL] $m\n   got:  $got\n   want: $want\n"; }
}

// Row 1 of «صورتحساب بازار فرش گیلان mz7» (Blu, «انتقال پل», with the user's note).
$row1 = "انتقال پل — انتقال پل به شماره شبا: IR900120020000000945158 بنام حسینعلی اصغری - کد پیگیری تراکنش 14050705211020578056 | 3315535983\n"
      . "[جزئیات بانک] تاریخ: 1405/07/05 | ساعت: 21:10:20 | مبلغ بانک: 6,642,500 (برداشت) | شرح: انتقال پل — انتقال پل به شماره شبا: IR900120020000000945158 بنام حسینعلی اصغری - کد پیگیری تراکنش 14050705211020578056 | 3315535983 | پیگیری: 14055483241949 | سند: 14055483241949 | یادداشت: انتقال پل - جهت بنر پشت فرش — سهم بازار فرش گیلان mz7";
expect_same(statement_short_description($row1), 'انتقال پل — جهت بنر پشت فرش — سهم بازار فرش گیلان mz7', 'bank row with a user note: title + note');

// Row 9 («انتقال به سپرده» with a note).
$row9 = "انتقال به سپرده — انتقال به سپرده بلو - شماره سپرده: 611828005615320101 بنام: علی کاظمی گوار - شماره سند: 14055716487708\n"
      . "[جزئیات بانک] تاریخ: 1405/07/12 | ساعت: 18:09:32 | مبلغ بانک: 1,000,000 (برداشت) | شرح: انتقال به سپرده — انتقال به سپرده بلو - شماره سپرده: 611828005615320101 بنام: علی کاظمی گوار - شماره سند: 14055716487708 | طرف: علی کاظمی گوار | مرجع: 14055716487708 | سند: 14055716487708 | یادداشت: انتقال به سپرده - فیلمبرداری و تولید محتوای شمال";
expect_same(statement_short_description($row9), 'انتقال به سپرده — فیلمبرداری و تولید محتوای شمال', 'deposit transfer with a note');

// Payment #3161: the note only repeats the title → the counterparty is shown.
$p3161 = "انتقال به سپرده — انتقال به سپرده بلو - شماره سپرده: 611828005148620501 بنام: الهام عماری اله یاری - شماره سند: 14056119053262\n"
       . "[جزئیات بانک] تاریخ: 1405/07/24 | ساعت: 15:36:28 | مبلغ بانک: 10,000,000 (برداشت) | شرح: انتقال به سپرده — انتقال به سپرده بلو - شماره سپرده: 611828005148620501 بنام: الهام عماری اله یاری - شماره سند: 14056119053262 | طرف: الهام عماری اله یاری | مرجع: 14056119053262 | سند: 14056119053262 | یادداشت: انتقال به سپرده";
expect_same(statement_short_description($p3161), 'انتقال به سپرده — الهام عماری اله یاری', 'no real note: title + counterparty');

// Payment #3160: machine transfer marker in front.
$p3160 = "TRANSFER:25:27:1792178543636896|انتقال به سپرده — انتقال به سپرده بلو - شماره سپرده: 611828006980446101 بنام: حمید دانش پور - شماره سند: 14056085326320\n"
       . "[جزئیات بانک] تاریخ: 1405/07/23 | ساعت: 13:40:40 | مبلغ بانک: 5,000,000 (برداشت) | شرح: انتقال به سپرده — انتقال به سپرده بلو - شماره سپرده: 611828006980446101 بنام: حمید دانش پور - شماره سند: 14056085326320 | طرف: حمید دانش پور | مرجع: 14056085326320 | سند: 14056085326320 | یادداشت: انتقال به سپرده";
expect_same(statement_short_description($p3160), 'انتقال به سپرده — حمید دانش پور', 'transfer marker is dropped');

// Payment #3168: Paya transfer (other bank format, no note).
$p3168 = "حواله پايا — 405071801923905، 140507180192390582 — بانک طرف مقابل : بانک صادرات،  نام کامل طرف مقابل : کيارش انوشا،  کد رهگیری : 140507180192390582، شماره شبای طرف مقابل : IR130190000000113333126008\n"
       . "[جزئیات بانک] تاریخ: 1405/07/19 | ساعت: 04:45:49 | مبلغ بانک: 125,000,000 (واریز) | شرح: حواله پايا — 405071801923905، 140507180192390582 — بانک طرف مقابل : بانک صادرات،  نام کامل طرف مقابل : کيارش انوشا،  کد رهگیری : 140507180192390582، شماره شبای طرف مقابل : IR130190000000113333126008 | طرف: کيارش انوشا | پیگیری: ACH17347 | شعبه: پايا (8890)";
expect_same(statement_short_description($p3168), 'حواله پايا — کيارش انوشا', 'Paya transfer: title + counterparty');

// New-format block (after the dedup fix): no «شرح», no «طرف» — counterparty read from the bank text.
$newFmt = "انتقال به سپرده بلو - شماره سپرده: 611828005148620501 بنام: الهام عماری اله یاری - شماره سند: 14056119053262\n"
        . "[جزئیات بانک] تاریخ: 1405/07/24 | ساعت: 15:36:28 | مبلغ بانک: 10,000,000 (برداشت)";
expect_same(statement_short_description($newFmt), 'انتقال به سپرده بلو — الهام عماری اله یاری', 'new format: counterparty taken from the bank text');

// A hand-written payment matched to a bank line: the user's text stays, bank details go.
$manual = "پرداخت کرایه حمل\n[جزئیات بانک] تاریخ: 1405/07/24 | مبلغ بانک: 1,200,000 (برداشت) | شرح: انتقال پل — انتقال پل به شماره شبا: IR90012002 بنام حسین | پیگیری: 1405";
expect_same(statement_short_description($manual), 'پرداخت کرایه حمل', 'hand-written text is kept, bank block dropped');
$manualNote = "پرداخت کرایه حمل\n[جزئیات بانک] تاریخ: 1405/07/24 | مبلغ بانک: 1,200,000 (برداشت) | شرح: انتقال پل — انتقال پل به IR90 | یادداشت: انتقال پل - بار تهران";
expect_same(statement_short_description($manualNote), 'پرداخت کرایه حمل — بار تهران', 'hand-written text + the user note');

// The note keeps its digits and letters as typed.
$typed = "انتقال پل — انتقال پل به IR90 بنام كيان\n[جزئیات بانک] تاریخ: 1405/07/05 | شرح: انتقال پل — انتقال پل به IR90 بنام كيان | یادداشت: انتقال پل - بابت فاکتور ۳۳۷ كيان";
expect_same(statement_short_description($typed), 'انتقال پل — بابت فاکتور ۳۳۷ كيان', 'the note keeps Persian digits and its own letters');

// Matched to a slightly different bank line (other tracking numbers): still bank text (#2324).
$p2324 = "انتقال به کارت — انتقال به کارت شماره: 6221061202393866 بنام: محمدرضا رضائی از درگاه اینترنتی 10000006 بانک سامان - ش م: 178784499875 ش پ: 634222\n"
       . "[جزئیات بانک] تاریخ: 1405/06/05 | مبلغ بانک: 5,000,000 (برداشت) | شرح: انتقال به کارت — انتقال به کارت شماره: 6221061202393866 بنام: محمدرضا رضائی از درگاه اینترنتی 10000006 بانک سامان - ش م: 178784496528 ش پ: 809125 | طرف: محمدرضا رضائی از درگاه اینترنتی 10000006 بانک سامان | پیگیری: 809125 | یادداشت: انتقال به کارت";
expect_same(statement_short_description($p2324), 'انتقال به کارت — محمدرضا رضائی از درگاه اینترنتی بانک سامان', 'bank text matched to a near-identical line');

// Older bank text kept as the description: card numbers and tracking code go (#2162).
$p2162 = "انتقال از 5029381065886087 به 6104338744690724 با کدرهگيري 082571\n"
       . "[جزئیات بانک] تاریخ: 1405/06/03 | مبلغ بانک: 5,002,180 (برداشت) | شرح: انتقال از 5029381065886087 به 6219861868135012 با کدرهگيري 340340 | مرجع: 260825844645852 | یادداشت: برداشت شخصي";
expect_same(statement_short_description($p2162), 'انتقال — برداشت شخصي', 'card numbers and tracking code are dropped');

// A bank text without the details block (registered through a transfer, #2389).
$p2389 = "TRANSFER:25:16:1788364890|حواله پل — بانک طرف مقابل : بانک سامان،  نام کامل طرف مقابل : محمدرضا رضائي، شماره شبای طرف مقابل : IR230560611828005606951601";
expect_same(statement_short_description($p2389), 'حواله پل — محمدرضا رضائي', 'bank-style text without the block is shortened too');

// Short numbers in a text stay; long ones go (#2526).
$p2526 = "TRANSFER:10:16:1789827285|پایا ۱۴۰۵۰۶۲۳۰۱۲۲۹۸۶۲۲۶۷۱ از اینترنت  - جهت تامین چک 3 مهر\n"
       . "[جزئیات بانک] تاریخ: 1405/06/23 | مبلغ بانک: 190,000,000 (واریز) | شرح: حواله پايا — 405062301229862، 14050623012298622671 — بانک طرف مقابل : بانک ملت،  نام کامل طرف مقابل : عليرضا ميرشمس | طرف: عليرضا ميرشمس";
expect_same(statement_short_description($p2526), 'پایا از اینترنت - جهت تامین چک 3 مهر', 'a kept text loses only its long numbers; short ones (چک 3 مهر) stay');

// Check numbers in hand-written text are kept (#1073).
expect_same(statement_short_description('راس‌گیری و انتقال چک 120331742477 به آقای زکریا'), 'راس‌گیری و انتقال چک 120331742477 به آقای زکریا', 'a check number in a typed description stays');

// A card purchase with the user's words after « - » keeps them; card and terminal numbers go (expense #259).
expect_same(statement_short_description('خريد توسط کارت به شماره 5029381065886087 از پايانه 13501090 باکدرهگيري 453988بانک - حمل و نقل تبلیغات'),
    'خريد توسط کارت به شماره از پايانه بانک - حمل و نقل تبلیغات', 'card purchase keeps the typed reason');

// Plain descriptions are untouched.
expect_same(statement_short_description('تسویه حساب فاکتور ۳۳۷'), 'تسویه حساب فاکتور ۳۳۷', 'a normal description is unchanged');
expect_same(statement_short_description(''), '', 'empty stays empty');
expect_same(statement_short_description('TRANSFER:1:2:3|انتقال بین حساب‌ها'), 'انتقال بین حساب‌ها', 'the transfer marker alone is dropped');
expect_same(statement_short_description($row9) , statement_short_description(statement_short_description($row9)), 'shortening twice changes nothing');

echo "\nPassed: $passed, Failed: $failed\n";
exit($failed ? 1 : 0);
