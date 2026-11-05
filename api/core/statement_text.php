<?php
// /api/core/statement_text.php
// Short descriptions for person statements.
//
// A payment registered from a bank line stores the bank's own text and an
// appended «[جزئیات بانک] تاریخ: … | شرح: … | طرف: … | یادداشت: …» block
// (BankImport::composeEnrichedDescription). That detail is useful in the books
// but noise — and other people's account numbers — on a statement sent to a
// customer, so statements show: the bank's operation title + the user's note,
// or + the counterparty when there is no note. Descriptions without the block
// are left as they are (only the internal «TRANSFER:a:b:c|» marker is dropped).

const STATEMENT_BANK_MARKER = '[جزئیات بانک]';
const STATEMENT_BANK_FIELDS = ['تاریخ', 'ساعت', 'مبلغ بانک', 'شرح', 'طرف', 'پیگیری', 'مرجع', 'سند', 'شعبه', 'یادداشت'];

/** Drops the internal transfer-pair marker («TRANSFER:12:34:5678|») anywhere in the text. */
function statement_strip_transfer_marker(string $text): string {
    return trim(preg_replace('/TRANSFER:\d+:\d+:\d+\|/u', '', $text));
}

/** Comparable form: Latin digits, ی/ک, single spaces. */
function statement_comparable(string $text): string {
    $text = strtr($text, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        'ي' => 'ی', 'ك' => 'ک',
    ]);
    return trim(preg_replace('/\s+/u', ' ', $text));
}

function statement_text_has(string $haystack, string $needle): bool {
    $needle = statement_comparable($needle);
    return $needle !== '' && mb_strpos(statement_comparable($haystack), $needle, 0, 'UTF-8') !== false;
}

/** «تاریخ: … | شرح: … | یادداشت: …» → [label => value]. */
function statement_parse_bank_block(string $block): array {
    $labels = implode('|', array_map(fn($l) => preg_quote($l, '/'), STATEMENT_BANK_FIELDS));
    $chunks = preg_split('/\s*\|\s*(?=(?:' . $labels . '):)/u', trim($block));
    $fields = [];
    foreach ($chunks as $chunk) {
        if (preg_match('/^(' . $labels . '):\s*(.*)$/us', trim($chunk), $m)) {
            $fields[$m[1]] = trim($m[2]);
        }
    }
    return $fields;
}

/**
 * Bank text without card / account / IBAN / tracking numbers, which mean
 * nothing to the reader of a statement and belong to other people.
 */
function statement_drop_bank_numbers(string $text): string {
    $d = '[0-9۰-۹٠-٩]';
    $text = preg_replace('/IR\s*' . $d . '{6,}/u', ' ', $text);
    $text = preg_replace('/(?:با\s*)?(?:کد|شماره)\s*(?:ر?هگ[يی]ر[يی]|پ[يی]گ[يی]ر[يی])\s*(?:تراکنش)?\s*:?\s*' . $d . '+/u', ' ', $text);
    $text = preg_replace('/' . $d . '{8,}/u', ' ', $text);
    $text = preg_replace('/(?:^|\s)از\s+به(?=\s|$)/u', ' ', $text);   // «انتقال از … به …» once the numbers are gone
    $text = preg_replace('/\s+/u', ' ', $text);
    return preg_replace('/^[\s\-—–:،|.]+|[\s\-—–:،|.]+$/u', '', $text); // trim() is byte-based: no multi-byte list there
}

/**
 * The operation title of a bank text: «انتقال پل — …» → «انتقال پل»;
 * «انتقال به سپرده بلو - شماره سپرده: …» → «انتقال به سپرده بلو». Text after a
 * « - » that is not bank detail (the sender's own words) is kept.
 */
function statement_bank_title(string $bankText): string {
    $bankText = trim(preg_replace('/\s+/u', ' ', $bankText));
    $pos = mb_strpos($bankText, ' — ', 0, 'UTF-8');
    if ($pos !== false && $pos > 0) {
        $bankText = mb_substr($bankText, 0, $pos, 'UTF-8');
    } else {
        $offset = 0;
        while (($p = mb_strpos($bankText, ' - ', $offset, 'UTF-8')) !== false) {
            if ($p >= 3 && statement_looks_like_bank_text(mb_substr($bankText, $p + 3, null, 'UTF-8'))) {
                $bankText = mb_substr($bankText, 0, $p, 'UTF-8');
                break;
            }
            $offset = $p + 3;
        }
    }
    $title = statement_drop_bank_numbers($bankText);
    if (mb_strlen($title, 'UTF-8') <= 90) {
        return $title;
    }
    // Very long texts: cut at a word boundary.
    $cut = mb_substr($title, 0, 90, 'UTF-8');
    $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
    return trim($space > 40 ? mb_substr($cut, 0, $space, 'UTF-8') : $cut) . '…';
}

/** A counterparty fit to print: no numbers, and a name repeated twice («علی رضایی علی رضایی») once. */
function statement_clean_counterparty(string $who): string {
    $who = statement_drop_bank_numbers($who);
    $words = preg_split('/\s+/u', $who, -1, PREG_SPLIT_NO_EMPTY);
    $n = count($words);
    if ($n >= 2 && $n % 2 === 0 && array_slice($words, 0, $n / 2) === array_slice($words, $n / 2)) {
        $who = implode(' ', array_slice($words, 0, $n / 2));
    }
    return preg_match('/\p{L}{2,}/u', $who) ? $who : '';
}

/** Counterparty named inside a bank text («بنام: …», «نام کامل طرف مقابل : …»). */
function statement_bank_counterparty(string $bankText): string {
    if (preg_match('/نام\s*کامل\s*طرف\s*مقابل\s*:\s*([^،|\n]+)/u', $bankText, $m)
        || preg_match('/بنام\s*:?\s*([^\-،|\n:]+?)(?:\s+-\s|،|\||\n|$)/u', $bankText, $m)) {
        return trim($m[1]);
    }
    return '';
}

/** The user's note without the bank title it was pre-filled with («انتقال پل - جهت …» → «جهت …»). */
function statement_note_without_title(string $note, string $title): string {
    $note = trim($note);
    if ($note === '' || $title === '') return $note;
    $cNote = statement_comparable($note);
    $cTitle = statement_comparable($title);
    if ($cNote === $cTitle || mb_strpos($cTitle, $cNote, 0, 'UTF-8') === 0) return '';
    if (mb_strpos($cNote, $cTitle, 0, 'UTF-8') === 0) {
        // Cut the title off the user's own text (its digits and letters stay as typed).
        $len = mb_strlen($note, 'UTF-8');
        for ($i = 1; $i <= $len; $i++) {
            if (statement_comparable(mb_substr($note, 0, $i, 'UTF-8')) === $cTitle) {
                $note = mb_substr($note, $i, null, 'UTF-8');
                break;
            }
        }
        $note = trim(preg_replace('/^[\s\-—–:،]+/u', '', $note));
    }
    return $note;
}

/** Typical wording of a bank's own transaction text (account / IBAN / tracking details). */
function statement_looks_like_bank_text(string $text): bool {
    $c = statement_comparable($text);
    foreach (['طرف مقابل', 'شماره شبا', 'کد رهگیری', 'کدرهگیری', 'کد پیگیری', 'کدپیگیری', 'شماره سپرده', 'شماره سند', 'شماره کارت', 'از درگاه', 'وشبای'] as $w) {
        if (mb_strpos($c, statement_comparable($w), 0, 'UTF-8') !== false) return true;
    }
    return (bool)preg_match('/IR\s*[0-9]{10,}/u', $c); // a long number alone may be a check number
}

/** Short statement line for a bank text: its title + the user's note, or + the counterparty. */
function statement_bank_line(string $source, string $note, string $who, string $noteTitle = ''): string {
    $title = statement_bank_title($source);
    $note = statement_note_without_title($note, $noteTitle !== '' ? $noteTitle : $title);
    $note = statement_note_without_title($note, $title);
    if ($note !== '') {
        return $title . ' — ' . $note;
    }
    $who = statement_clean_counterparty($who) ?: statement_clean_counterparty(statement_bank_counterparty($source));
    return $who !== '' && !statement_text_has($title, $who) ? $title . ' — ' . $who : $title;
}

function statement_short_description(string $text): string {
    $text = statement_strip_transfer_marker($text);
    $pos = mb_strpos($text, STATEMENT_BANK_MARKER, 0, 'UTF-8');
    if ($pos === false) {
        // Bank text saved without the details block (e.g. a transfer registered from the bank).
        return statement_looks_like_bank_text($text) ? statement_bank_line($text, '', '') : $text;
    }
    $base = trim(mb_substr($text, 0, $pos, 'UTF-8'));
    $fields = statement_parse_bank_block(mb_substr($text, $pos + mb_strlen(STATEMENT_BANK_MARKER, 'UTF-8'), null, 'UTF-8'));
    $bankText = statement_strip_transfer_marker($fields['شرح'] ?? '');

    // The description is the bank text itself when the block does not repeat
    // another text (newer rows) or repeats this one (older rows) — compared
    // without numbers, since a line matched later may differ only in its codes.
    $plainBase = statement_drop_bank_numbers($base);
    $plainBank = statement_drop_bank_numbers($bankText);
    $baseIsBank = $base === '' || $bankText === ''
        || statement_text_has($plainBase, $plainBank) || statement_text_has($plainBank, $plainBase)
        || statement_looks_like_bank_text($base);

    if ($baseIsBank) {
        return statement_bank_line($base !== '' ? $base : $bankText, $fields['یادداشت'] ?? '', $fields['طرف'] ?? '',
            statement_bank_title($bankText !== '' ? $bankText : $base));
    }

    // A hand-written description matched to a bank line: keep it (without long
    // account / tracking numbers) and add the user's note.
    $note = statement_note_without_title($fields['یادداشت'] ?? '', statement_bank_title($bankText));
    return ($note !== '' && !statement_text_has($plainBase, $note)) ? $plainBase . ' — ' . $note : $plainBase;
}
