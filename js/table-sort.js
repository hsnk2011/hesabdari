// =================================================================
// CLICK-TO-SORT FOR PLAIN TABLES (js/table-sort.js) — both template sets
// Any table that has no sorting of its own gets it: click a column title to
// sort the rows shown, click again to reverse. Left alone:
//   - lists sorted by the server (.sortable-header): sorting one page here
//     would mislead,
//   - DataTables (they sort themselves, or turned sorting off on purpose,
//     e.g. statements with a running balance),
//   - tables marked data-no-sort, headers with merged cells, and bodies with
//     grouped rows (a cell spanning columns), where row order carries meaning,
//   - entry tables (inside a form, or rows with input fields): their row
//     order is what the user is typing in.
// Columns without a title, «عملیات» and checkbox columns are not sortable.
// =================================================================
const TableSort = (function () {
    const collator = new Intl.Collator('fa', { numeric: true, sensitivity: 'base' });
    const DIGITS = { '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
        '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9' };
    const SKIP_TITLES = ['عملیات', 'اقدامات', 'عملیات‌ها'];

    const latinDigits = (s) => s.replace(/[۰-۹٠-٩]/g, (d) => DIGITS[d]).replace(/٫/g, '.').replace(/[٬،]/g, ',');

    function tableSortable(table) {
        if (!table || table.hasAttribute('data-no-sort')) return false;
        if (table.classList.contains('dataTable') || table.closest('.dataTables_wrapper, .dt-container')) return false;
        if (table.closest('form')) return false;
        if (Array.prototype.some.call(table.tBodies, (b) => b.querySelector('input:not([type="checkbox"]):not([type="hidden"]), select, textarea'))) return false;
        // Grouped bodies (a cell spanning columns, e.g. item rows under each invoice)
        // keep their order; a lone "nothing found" row does not count.
        if (Array.prototype.some.call(table.tBodies, (b) => b.rows.length > 1
            && Array.prototype.some.call(b.rows, (r) => Array.prototype.some.call(r.cells, (c) => c.colSpan > 1)))) return false;
        const thead = table.tHead;
        if (!thead || thead.rows.length !== 1) return false;
        if (thead.querySelector('.sortable-header, [colspan], [rowspan]')) return false;
        return true;
    }

    function columnSortable(th) {
        if (th.querySelector('input, select, button')) return false;
        if (th.classList.contains('no-sort')) return false;
        const title = th.textContent.replace(/\s+/g, ' ').trim();
        return title !== '' && !SKIP_TITLES.includes(title);
    }

    /** Marks the column titles that can be clicked (cursor + arrow). */
    function scan(root) {
        // A table that became a DataTable or an entry table after it was marked.
        (root || document).querySelectorAll('th[data-ui-sort]').forEach((th) => {
            if (tableSortable(th.closest('table'))) return;
            th.removeAttribute('data-ui-sort');
            th.classList.remove('ui-sort-th');
            th.querySelectorAll('.ui-sort-ind').forEach((i) => i.remove());
        });
        (root || document).querySelectorAll('table > thead > tr > th:not([data-ui-sort])').forEach((th) => {
            const table = th.closest('table');
            if (!tableSortable(table) || !columnSortable(th)) return;
            th.setAttribute('data-ui-sort', '');
            th.classList.add('ui-sort-th');
            if (!th.querySelector('.ui-sort-ind')) {
                const ind = document.createElement('span');
                ind.className = 'ui-sort-ind';
                ind.setAttribute('aria-hidden', 'true');
                th.appendChild(ind);
            }
        });
    }

    function cellValue(cell) {
        if (!cell) return '';
        if (cell.hasAttribute('data-sort-value')) return cell.getAttribute('data-sort-value');
        const field = cell.querySelector('input:not([type="checkbox"]):not([type="hidden"]), select');
        return field ? String(field.value) : cell.textContent;
    }

    /** {num} for amounts, counts and percents; {text} for everything else; null when empty. */
    function sortKey(raw) {
        let s = latinDigits(String(raw)).replace(/\s+/g, ' ').trim();
        if (s === '' || s === '-' || s === '—' || s === '–') return null;
        const negative = /^\(.*\)$/.test(s) || /^[-−]/.test(s) || /[-−]$/.test(s);
        const bare = s.replace(/[()−+%]/g, '').replace(/^-|-$/g, '').replace(/,/g, '')
            .replace(/(تومان|ریال|ت|عدد|تخته|فقره|متر مربع|م²|متر|روز)$/u, '').trim();
        if (/^\d+(\.\d+)?$/.test(bare)) {
            const n = parseFloat(bare);
            return { num: negative ? -n : n };
        }
        return { text: s };
    }

    function compare(a, b) {
        if (a === null || b === null) return a === b ? 0 : (a === null ? 1 : -1); // empty cells last
        if ('num' in a && 'num' in b) return a.num - b.num;
        if ('num' in a) return -1;
        if ('num' in b) return 1;
        return collator.compare(a.text, b.text);
    }

    function sortBy(th) {
        const table = th.closest('table');
        if (!tableSortable(table)) return;
        const col = Array.prototype.indexOf.call(th.parentElement.children, th);
        const dir = th.getAttribute('data-ui-sort') === 'asc' ? 'desc' : 'asc';
        let sorted = false;
        Array.prototype.forEach.call(table.tBodies, (tbody) => {
            const rows = Array.from(tbody.rows);
            if (rows.length < 2) return;
            const keyed = rows.map((row, i) => ({ row, i, key: sortKey(cellValue(row.cells[col])) }));
            keyed.sort((x, y) => {
                const c = compare(x.key, y.key);
                // Empty cells stay last in both directions.
                const d = (x.key === null || y.key === null) ? c : (dir === 'asc' ? c : -c);
                return d || x.i - y.i;
            });
            const frag = document.createDocumentFragment();
            keyed.forEach((k) => frag.appendChild(k.row));
            tbody.appendChild(frag);
            sorted = true;
        });
        if (!sorted) return;
        th.parentElement.querySelectorAll('th[data-ui-sort]').forEach((h) => {
            h.setAttribute('data-ui-sort', '');
            h.removeAttribute('aria-sort');
        });
        th.setAttribute('data-ui-sort', dir);
        th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
    }

    /**
     * On wide screens table text wraps so tables fit the page without sideways
     * scrolling (see modern/pages.css). Cells that must stay on one line are
     * tagged here:
     *   .ui-fig — a single amount, date or number,
     *   .ui-ctl — only controls: buttons, a status dropdown, badges, a switch.
     * A cell is re-checked when its text changes (rows are redrawn in place).
     */
    const DATE_RE = /^\d{4}\/\d{1,2}\/\d{1,2}$/;
    const CTL_SEL = '.btn, .btn-group, .dropdown, .badge, .form-check, .form-switch';
    function ownText(td) {
        let text = '';
        td.childNodes.forEach((n) => {
            if (n.nodeType === 3) text += n.textContent;
            else if (n.nodeType === 1 && !n.matches('.dropdown-menu, .visually-hidden')) {
                const clone = n.cloneNode(true);
                clone.querySelectorAll('.dropdown-menu, .visually-hidden').forEach((m) => m.remove());
                text += ' ' + clone.textContent;
            }
        });
        return text.replace(/\s+/g, ' ').trim();
    }
    function isControlCell(td) {
        if (!td.querySelector(CTL_SEL) || td.querySelector('table, br, p, ul:not(.dropdown-menu)')) return false;
        // Every visible piece of the cell is a control (or sits inside one).
        for (const n of td.childNodes) {
            if (n.nodeType === 3) { if (n.textContent.trim()) return false; continue; }
            if (n.nodeType !== 1 || n.matches('.dropdown-menu, input[type="hidden"]')) continue;
            if (n.matches(CTL_SEL) || n.matches('a, i')) continue;
            if (n.matches('div, span') && n.querySelector(CTL_SEL) && !Array.prototype.some.call(n.childNodes,
                (c) => c.nodeType === 3 && c.textContent.trim())) continue;
            return false;
        }
        return ownText(td).length <= 40;
    }
    function markFigureCells(root) {
        (root || document).querySelectorAll('table > tbody > tr > td').forEach((td) => {
            const sig = td.childElementCount + ':' + td.textContent.length;
            if (td.getAttribute('data-ui-fig') === sig) return;
            td.setAttribute('data-ui-fig', sig);
            td.classList.remove('ui-fig', 'ui-ctl');
            if (td.colSpan > 1) return;
            if (isControlCell(td)) { td.classList.add('ui-ctl'); return; }
            if (td.children.length > 1 || td.querySelector('table, div, br')) return;
            const text = td.textContent.replace(/\s+/g, ' ').trim();
            if (!text || text.length > 40) return;
            const key = sortKey(text);
            const plain = latinDigits(text).replace(/\s*\(.*\)$/, '').trim();
            if ((key && 'num' in key) || DATE_RE.test(plain) || /^-?\d+\s*روز$/.test(plain)) td.classList.add('ui-fig');
        });
    }

    function init() {
        document.addEventListener('click', (e) => {
            const th = e.target.closest && e.target.closest('th[data-ui-sort]');
            if (!th || e.target.closest('a, button, input, select, label')) return;
            sortBy(th);
        });
        scan();
        markFigureCells();
        // Tables are drawn by the managers at any time: mark new ones shortly after.
        let pending = null;
        new MutationObserver(() => {
            if (pending) return;
            pending = setTimeout(() => { pending = null; scan(); markFigureCells(); }, 150);
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

    return { scan, sortKey };
})();
