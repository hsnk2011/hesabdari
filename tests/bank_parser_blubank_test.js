/**
 * Blu bank (Saman) statement rows: the «شرح سند» already starts with the
 * transaction type, so the description must not repeat it, and the note must
 * not just repeat the type either. js/bank-reconciliation-manager.js must
 * compose «[جزئیات بانک]» without repeating the description (mirror of the PHP
 * test tests/bank_description_dedup_test.php).
 * Run: node tests/bank_parser_blubank_test.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

let passed = 0, failed = 0;
const expect = (c, m) => { if (c) { passed++; console.log('[PASS] ' + m); } else { failed++; console.log('[FAIL] ' + m); } };
const count = (hay, needle) => hay.split(needle).length - 1;

const ctx = { console, XLSX: { utils: { sheet_to_json: (sheet) => sheet } } };
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../js/bank-statement-parsers.js'), 'utf8') + ';this.BankStatementParsers = BankStatementParsers;', ctx);
const P = ctx.BankStatementParsers;

const desc = 'انتقال به سپرده بلو - شماره سپرده: 611828005148620501 بنام: الهام عماری اله یاری - شماره سند: 14056119053262';
const matrix = [
    ['ردیف', 'تاریخ', 'نوع تراکنش', 'شرح سند', 'شماره سند', 'برداشت', 'واریز', 'مانده'],
    ['1', '1405/07/24 15:36:28', 'انتقال به سپرده', desc, '14056119053262', '100000000', '0', '500000000'],
    ['2', '1405/07/25 10:00:00', 'کارمزد', 'کارمزد انتقال', '14056119053999', '120000', '0', '499880000'],
];
const { rows } = P.parseBlubankWorkbook({ SheetNames: ['s'], Sheets: { s: matrix } });
expect(rows.length === 2, 'both rows are read');
expect(rows[0].description === desc, 'a description that already starts with its type is not prefixed again');
expect(count(rows[0].description, 'انتقال به سپرده') === 1, 'the type appears once');
expect(rows[1].description === 'کارمزد — کارمزد انتقال' || rows[1].description === 'کارمزد انتقال', 'a short description keeps its type once');
expect(rows[0].amount === -10000000, 'amount is read in toman');
expect(rows[0].document_number === '14056119053262', 'the document number is kept');

// Client-side composition (the «ثبت از ردیف بانک» form fills this).
const mgr = fs.readFileSync(path.join(__dirname, '../js/bank-reconciliation-manager.js'), 'utf8');
const start = mgr.indexOf('    function bankComparable');
const end = mgr.indexOf('    function populateAccounts');
expect(start > 0 && end > start, 'the composition helpers are found');
const helpers = mgr.slice(start, end);
const ctx2 = { UI: { gregorianToPersian: (d) => d } };
vm.createContext(ctx2);
vm.runInContext(helpers + ';this.compose = composeEnrichedDescription;', ctx2);
const item = {
    transaction_date: '2026-10-16', transaction_time: '15:36:28', amount: -10000000,
    description: 'انتقال به سپرده — ' + desc, counterparty: 'الهام عماری اله یاری',
    tracking_number: '', reference_number: '14056119053262', document_number: '14056119053262',
    branch: '', note: 'انتقال به سپرده',
};
const out = ctx2.compose(item.description, item);
expect(out.includes('[جزئیات بانک]'), 'the bank block is added');
expect(count(out, 'شماره سپرده: 611828005148620501') === 1, 'the bank text appears once');
expect(count(out, 'الهام عماری اله یاری') === 1, 'the counterparty appears once');
expect(count(out, '14056119053262') === 1, 'the document number appears once');
expect(!out.includes('یادداشت: انتقال به سپرده'), 'a note that only repeats the bank text is left out');

console.log(`\nPassed: ${passed}, Failed: ${failed}`);
process.exit(failed ? 1 : 0);
