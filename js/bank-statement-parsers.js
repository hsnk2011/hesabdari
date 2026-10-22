/**
 * Bank statement parsers for Mellat (CSV), Day (XLS), Melli (XLS), and Blubank/Saman (XLSX).
 * Amounts in bank files are Rial; app uses Toman (÷10).
 * Signed amount: positive = credit/deposit, negative = debit/withdrawal.
 */
const BankStatementParsers = (() => {
    const PERSIAN_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
    const ARABIC_DIGITS = '٠١٢٣٤٥٦٧٨٩';

    // Iranian IBAN layout: IR + 2 check digits + 2-3 bank code + account number.
    // For Blu/Saman statements the last 13 digits of the IBAN are the account number.
    const IR_IBAN_ACCOUNT_SUFFIX_LEN = 13;

    // Registry of supported statement formats (labels used in UI notes/errors).
    const BANK_FORMATS = {
        mellat:  { label: 'ملت (CSV)' },
        dey:     { label: 'دی (Excel)' },
        melli:   { label: 'ملی (Excel)' },
        blubank: { label: 'بلو/سامان (Excel)' },
        generic: { label: 'CSV عمومی' }
    };

    function supportedFormatsLabel() {
        return Object.keys(BANK_FORMATS)
            .filter(k => k !== 'generic')
            .map(k => BANK_FORMATS[k].label)
            .join('، ');
    }

    function toEnglishDigits(str) {
        return String(str ?? '').replace(/[۰-۹٠-٩]/g, (d) => {
            const pi = PERSIAN_DIGITS.indexOf(d);
            if (pi >= 0) return String(pi);
            const ai = ARABIC_DIGITS.indexOf(d);
            return ai >= 0 ? String(ai) : d;
        });
    }

    // Jalaali → Gregorian (same algorithm as UI)
    function jalaliToGregorian(jy, jm, jd) {
        jy = parseInt(jy, 10);
        jm = parseInt(jm, 10);
        jd = parseInt(jd, 10);
        jy += 1595;
        let days = -355668 + (365 * jy) + (~~(jy / 33) * 8) + ~~(((jy % 33) + 3) / 4) + jd + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
        let gy = 400 * ~~(days / 146097);
        days %= 146097;
        if (days > 36524) {
            days--;
            gy += 100 * ~~(days / 36524);
            days %= 36524;
            if (days >= 365) days++;
        }
        gy += 4 * ~~(days / 1461);
        days %= 1461;
        if (days > 365) {
            gy += ~~((days - 1) / 365);
            days = (days - 1) % 365;
        }
        let gd = days + 1;
        const sal_a = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        let gm;
        for (gm = 1; gm < 13; gm++) {
            const v = sal_a[gm];
            if (gd <= v) break;
            gd -= v;
        }
        return `${gy}-${String(gm).padStart(2, '0')}-${String(gd).padStart(2, '0')}`;
    }

    function parseJalaliDate(raw) {
        if (!raw) return null;
        const s = toEnglishDigits(String(raw)).trim().replace(/-/g, '/');
        const m = s.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})/);
        if (!m) return null;
        return jalaliToGregorian(m[1], m[2], m[3]);
    }

    /** Parse Jalali datetime like 1405/05/02 20:14:01 */
    function parseJalaliDateTime(raw) {
        const s = toEnglishDigits(String(raw ?? '')).trim().replace(/-/g, '/');
        const date = parseJalaliDate(s);
        const tm = s.match(/\d{4}\/\d{1,2}\/\d{1,2}\s+(\d{1,2}:\d{2}(?::\d{2})?)/);
        return { date, time: tm ? tm[1] : null };
    }

    function parseAmount(raw) {
        if (raw === null || raw === undefined || raw === '') return 0;
        if (typeof raw === 'number') return raw;
        let s = toEnglishDigits(String(raw)).trim();
        if (!s || s === '-' || s === '0') return 0;
        s = s.replace(/,/g, '').replace(/\s/g, '').replace(/[^\d.-]/g, '');
        const n = parseFloat(s);
        return Number.isFinite(n) ? n : 0;
    }

    /**
     * Rial → accounting unit factor. Reads the `currency_rial_divisor` public
     * setting (default 10 = toman) so non-toman installs import correctly.
     */
    function rialDivisor() {
        let d = NaN;
        try {
            if (typeof UI !== 'undefined' && UI.getAppSetting) {
                d = parseFloat(UI.getAppSetting('currency_rial_divisor', NaN));
            }
        } catch (e) { /* fall through */ }
        if (!Number.isFinite(d) || d <= 0) {
            d = (typeof AppConfig !== 'undefined' && AppConfig.DEFAULTS && AppConfig.DEFAULTS.RIAL_DIVISOR) || 10;
        }
        return d;
    }

    /** Convert Rial to the accounting unit (integer-friendly). */
    function rialToToman(rial) {
        return Math.round(Number(rial) || 0) / rialDivisor();
    }

    function normalizeHeader(h) {
        return toEnglishDigits(String(h || ''))
            .replace(/\u200c/g, '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    function cellText(v) {
        return String(v ?? '').replace(/\u200c/g, '').trim();
    }

    function extractCounterparty(extra) {
        const s = cellText(extra);
        if (!s) return '';
        const m = s.match(/نام\s*کامل\s*طرف\s*مقابل\s*[:：]\s*([^،,]*)/i);
        return m ? m[1].trim() : '';
    }

    function parseCsv(text) {
        const rows = [];
        let row = [];
        let field = '';
        let inQuotes = false;
        const src = String(text || '').replace(/^\uFEFF/, '');
        for (let i = 0; i < src.length; i++) {
            const c = src[i];
            const next = src[i + 1];
            if (inQuotes) {
                if (c === '"' && next === '"') {
                    field += '"';
                    i++;
                } else if (c === '"') {
                    inQuotes = false;
                } else {
                    field += c;
                }
            } else if (c === '"') {
                inQuotes = true;
            } else if (c === ',') {
                row.push(field);
                field = '';
            } else if (c === '\n') {
                row.push(field);
                rows.push(row);
                row = [];
                field = '';
            } else if (c === '\r') {
                // skip
            } else {
                field += c;
            }
        }
        if (field.length || row.length) {
            row.push(field);
            rows.push(row);
        }
        return rows.filter(r => r.some(cell => String(cell || '').trim() !== ''));
    }

    function detectFormatFromHeaders(headers) {
        const normalized = headers.map(normalizeHeader);
        const joined = normalized.join('|');
        if (joined.includes('مبلغ گردش بستانکار') || joined.includes('مبلغ گردش بدهکار')) {
            return 'mellat';
        }
        // Blubank (Saman): نوع تراکنش + شرح سند + واریز/برداشت جدا — قبل از دی چک شود
        const bluTxn = normalized.some(h => h.includes('نوع تراکنش'));
        const bluDesc = normalized.some(h => h.includes('شرح سند'));
        const bluCreditDebit = normalized.some(h => h.includes('واریز')) && normalized.some(h => h.includes('برداشت'));
        if (bluTxn && bluDesc && bluCreditDebit) {
            return 'blubank';
        }
        // Bank Melli: single مبلغ + نوع (واریز/برداشت) + ردیف
        const hasRow = normalized.includes('ردیف');
        const hasType = normalized.some(h => h === 'نوع' || h.includes('نوع تراکنش'));
        const hasAmount = normalized.some(h => h === 'مبلغ' || h.startsWith('مبلغ '));
        const hasSeparateCreditDebit = normalized.some(h => h.includes('واریز')) && normalized.some(h => h.includes('برداشت'));
        if (hasRow && hasType && hasAmount && !hasSeparateCreditDebit) {
            return 'melli';
        }
        if (joined.includes('واریز (ریال)') || (joined.includes('واریز') && joined.includes('برداشت') && joined.includes('شماره پیگیری'))) {
            return 'dey';
        }
        if (joined.includes('تاریخ') && (joined.includes('مبلغ') || joined.includes('amount'))) {
            return 'generic';
        }
        return null;
    }

    function buildSummary(rows, format, meta = {}) {
        let creditTotal = 0;
        let debitTotal = 0;
        let minDate = null;
        let maxDate = null;
        rows.forEach(r => {
            if (r.amount > 0) creditTotal += r.amount;
            else debitTotal += Math.abs(r.amount);
            if (r.date) {
                if (!minDate || r.date < minDate) minDate = r.date;
                if (!maxDate || r.date > maxDate) maxDate = r.date;
            }
        });
        const closing = rows.length ? (rows[0].balance ?? rows[rows.length - 1].balance ?? null) : null;
        let closingBalance = null;
        let openingBalance = null;
        if (rows.length) {
            const withBal = rows.filter(r => r.balance !== null && r.balance !== undefined);
            if (withBal.length) {
                if (minDate && maxDate && withBal[0].date >= withBal[withBal.length - 1].date) {
                    closingBalance = withBal[0].balance;
                    openingBalance = withBal[withBal.length - 1].balance;
                } else {
                    openingBalance = withBal[0].balance;
                    closingBalance = withBal[withBal.length - 1].balance;
                }
            }
        }
        return {
            format,
            row_count: rows.length,
            credit_total: creditTotal,
            debit_total: debitTotal,
            period_start: minDate,
            period_end: maxDate,
            opening_balance: openingBalance,
            closing_balance: closingBalance ?? closing,
            currency_note: (function () {
                const d = rialDivisor();
                const label = (typeof UI !== 'undefined' && UI.getCurrencyLabel) ? UI.getCurrencyLabel() : 'تومان';
                const dFa = Number(d).toLocaleString('fa-IR');
                return `مبالغ از ریال به ${label} تبدیل شده‌اند (÷${dFa}).`;
            })(),
            preview: rows.slice(0, 8),
            meta
        };
    }

    function mapMellatRows(matrix) {
        if (!matrix.length) throw new Error('فایل CSV خالی است.');
        const headers = matrix[0].map(normalizeHeader);
        const idx = {
            balance: headers.findIndex(h => h.includes('مانده')),
            credit: headers.findIndex(h => h.includes('بستانکار')),
            debit: headers.findIndex(h => h.includes('بدهکار')),
            desc: headers.findIndex(h => h === 'شرح' || h.includes('شرح')),
            party: headers.findIndex(h => h.includes('واریز کننده') || h.includes('ذینفع') || h.includes('ذیتفع')),
            serial: headers.findIndex(h => h.includes('سریال')),
            deposit: headers.findIndex(h => h.includes('شناسه واریز')),
            branch: headers.findIndex(h => h.includes('شعبه')),
            time: headers.findIndex(h => h === 'زمان' || h.includes('زمان')),
            date: headers.findIndex(h => h === 'تاریخ' || h.includes('تاریخ')),
        };
        if (idx.date < 0 || (idx.credit < 0 && idx.debit < 0)) {
            throw new Error('قالب CSV بانک ملت شناسایی نشد.');
        }
        const rows = [];
        for (let i = 1; i < matrix.length; i++) {
            const cells = matrix[i];
            const date = parseJalaliDate(cells[idx.date]);
            if (!date) continue;
            const creditRial = idx.credit >= 0 ? parseAmount(cells[idx.credit]) : 0;
            const debitRial = idx.debit >= 0 ? parseAmount(cells[idx.debit]) : 0;
            if (creditRial === 0 && debitRial === 0) continue;
            const amount = creditRial > 0 ? rialToToman(creditRial) : -rialToToman(debitRial);
            const balanceRial = idx.balance >= 0 ? parseAmount(cells[idx.balance]) : null;
            rows.push({
                date,
                time: idx.time >= 0 ? toEnglishDigits(cells[idx.time] || '').trim() : null,
                amount,
                balance: balanceRial === null ? null : rialToToman(balanceRial),
                description: idx.desc >= 0 ? String(cells[idx.desc] || '').trim() : '',
                counterparty: idx.party >= 0 ? String(cells[idx.party] || '').trim() : '',
                reference: idx.serial >= 0 ? toEnglishDigits(cells[idx.serial] || '').trim() : '',
                document_number: null,
                tracking_number: idx.serial >= 0 ? toEnglishDigits(cells[idx.serial] || '').trim() : '',
                deposit_id: idx.deposit >= 0 ? toEnglishDigits(cells[idx.deposit] || '').trim() : '',
                branch: idx.branch >= 0 ? String(cells[idx.branch] || '').trim() : '',
                note: ''
            });
        }
        return rows;
    }

    function isBlubankHeaderCells(cells) {
        const hasTxnType = cells.some(c => c.includes('نوع تراکنش'));
        const hasDocDesc = cells.some(c => c.includes('شرح سند'));
        const hasCredit = cells.some(c => c.includes('واریز'));
        const hasDebit = cells.some(c => c.includes('برداشت'));
        const hasDate = cells.some(c => c === 'تاریخ' || c.startsWith('تاریخ'));
        const hasRow = cells.includes('ردیف');
        const hasTrackingCol = cells.some(c => c.includes('شماره پیگیری'));
        // بلو: واریز/برداشت جدا + نوع تراکنش + شرح سند؛ ستون «شماره پیگیری» معمولاً ندارد (برخلاف دی)
        if (hasTxnType && hasDocDesc && hasCredit && hasDebit && hasDate && !hasTrackingCol) {
            return true;
        }
        if (hasTxnType && hasCredit && hasDebit && hasDate && hasRow
            && cells.some(c => c.includes('مانده'))
            && cells.some(c => c.includes('ریال'))) {
            return true;
        }
        return false;
    }

    function findBlubankHeaderRow(matrix) {
        for (let r = 0; r < Math.min(matrix.length, 35); r++) {
            const cells = (matrix[r] || []).map(normalizeHeader);
            if (isBlubankHeaderCells(cells)) return r;
        }
        return -1;
    }

    function matrixLooksLikeBlubank(matrix) {
        if (findBlubankHeaderRow(matrix) >= 0) return true;
        const scan = Math.min(matrix.length, 15);
        let blob = '';
        for (let r = 0; r < scan; r++) {
            blob += (matrix[r] || []).map(cellText).join(' ') + ' ';
        }
        const n = normalizeHeader(blob);
        return (n.includes('بلو') || n.includes('بانک سامان') || n.includes('سپرده بلو'))
            && n.includes('صورتحساب')
            && (n.includes('واریز') && n.includes('برداشت'));
    }

    function findDeyHeaderRow(matrix) {
        for (let r = 0; r < Math.min(matrix.length, 20); r++) {
            const cells = matrix[r].map(normalizeHeader);
            // جلوگیری از اشتباه گرفتن صورتحساب بلو/سامان با دی
            if (isBlubankHeaderCells(cells)) continue;
            if (cells.includes('ردیف') && cells.some(c => c.includes('واریز')) && cells.some(c => c.includes('برداشت'))) {
                return r;
            }
        }
        return -1;
    }

    function findMelliHeaderRow(matrix) {
        for (let r = 0; r < Math.min(matrix.length, 25); r++) {
            const cells = matrix[r].map(normalizeHeader);
            const hasRow = cells.includes('ردیف');
            const hasType = cells.some(c => c === 'نوع' || c.includes('نوع تراکنش'));
            const hasAmount = cells.some(c => c === 'مبلغ' || c.startsWith('مبلغ '));
            const hasDate = cells.some(c => c === 'تاریخ' || c.startsWith('تاریخ'));
            const hasSeparateCreditDebit = cells.some(c => c.includes('واریز')) && cells.some(c => c.includes('برداشت'));
            if (hasRow && hasType && hasAmount && hasDate && !hasSeparateCreditDebit) {
                return r;
            }
            if (
                hasRow && hasDate && hasType && hasAmount
                && cells.some(c => c.includes('شماره پیگیری'))
                && cells.some(c => c === 'شرح' || c.includes('شرح'))
                && cells.some(c => c.includes('مانده'))
            ) {
                return r;
            }
        }
        return -1;
    }

    function extractMelliMeta(matrix, headerRow) {
        const meta = { account_number: '', account_holder: '', period_from: '', period_to: '' };
        const scanTo = Math.max(0, headerRow);
        for (let r = 0; r < scanTo; r++) {
            const cells = (matrix[r] || []).map(cellText);
            for (let c = 0; c < cells.length; c++) {
                const label = normalizeHeader(cells[c]).replace(/:$/, '');
                const value = cellText(cells[c + 1]);
                if (!value) continue;
                if (label.includes('شماره حساب')) meta.account_number = toEnglishDigits(value).replace(/\D/g, '');
                else if (label.includes('نام و نام خانوادگی') || label === 'نام') meta.account_holder = value;
                else if (label.includes('از تاریخ')) meta.period_from = value;
                else if (label.includes('تا تاریخ')) meta.period_to = value;
            }
        }
        return meta;
    }

    function mapDeyRows(matrix) {
        const headerRow = findDeyHeaderRow(matrix);
        if (headerRow < 0) throw new Error('هدر صورتحساب بانک دی یافت نشد.');
        const headers = matrix[headerRow].map(normalizeHeader);
        const col = (preds) => headers.findIndex(h => preds.some(p => (typeof p === 'string' ? h.includes(p) : p(h))));
        const idx = {
            date: col(['تاریخ']),
            time: col(['زمان']),
            desc: col([h => h === 'شرح', 'شرح']),
            credit: col(['واریز']),
            debit: col(['برداشت']),
            balance: col(['مانده']),
            branch: col(['شعبه']),
            doc: col(['شماره سند']),
            check: col(['شماره برگه', 'شماره چک']),
            deposit: col(['شناسه واریز']),
            note: col(['یادداشت']),
            track: col(['شماره پیگیری']),
        };
        const rows = [];
        for (let i = headerRow + 1; i < matrix.length; i++) {
            const cells = matrix[i];
            const first = normalizeHeader(cells[0]);
            const descCell = idx.desc >= 0 ? normalizeHeader(cells[idx.desc]) : '';
            if (descCell === 'مجموع' || first === 'مجموع') continue;
            const date = parseJalaliDate(cells[idx.date]);
            if (!date) continue;
            const creditRial = idx.credit >= 0 ? parseAmount(cells[idx.credit]) : 0;
            const debitRial = idx.debit >= 0 ? parseAmount(cells[idx.debit]) : 0;
            if (creditRial === 0 && debitRial === 0) continue;
            const amount = creditRial > 0 ? rialToToman(creditRial) : -rialToToman(debitRial);
            const balanceRial = idx.balance >= 0 ? parseAmount(cells[idx.balance]) : null;
            const tracking = idx.track >= 0 ? toEnglishDigits(cells[idx.track] || '').trim() : '';
            const docNo = idx.doc >= 0 ? toEnglishDigits(cells[idx.doc] || '').trim() : '';
            rows.push({
                date,
                time: idx.time >= 0 ? toEnglishDigits(cells[idx.time] || '').trim() : null,
                amount,
                balance: balanceRial === null ? null : rialToToman(balanceRial),
                description: idx.desc >= 0 ? String(cells[idx.desc] || '').trim() : '',
                counterparty: '',
                reference: tracking || docNo || null,
                document_number: docNo || null,
                tracking_number: tracking || null,
                deposit_id: idx.deposit >= 0 ? toEnglishDigits(cells[idx.deposit] || '').trim() : '',
                branch: idx.branch >= 0 ? String(cells[idx.branch] || '').trim() : '',
                note: idx.note >= 0 ? String(cells[idx.note] || '').trim() : ''
            });
        }
        return rows;
    }

    function mapMelliRows(matrix) {
        const headerRow = findMelliHeaderRow(matrix);
        if (headerRow < 0) throw new Error('هدر صورتحساب بانک ملی یافت نشد.');
        const headers = matrix[headerRow].map(normalizeHeader);
        const col = (preds) => headers.findIndex(h => preds.some(p => (typeof p === 'string' ? (p === h || h.includes(p)) : p(h))));
        const idx = {
            date: col([h => h === 'تاریخ', 'تاریخ']),
            time: col([h => h === 'زمان', 'زمان']),
            branch: col(['شعبه']),
            type: col([h => h === 'نوع', 'نوع تراکنش']),
            amount: col([h => h === 'مبلغ', h => h.startsWith('مبلغ ')]),
            track: col(['شماره پیگیری', 'پیگیری']),
            extra: col(['اطلاعات تکمیلی']),
            desc: col([h => h === 'شرح', 'شرح']),
            details: col([h => h === 'توضیحات', 'توضیحات']),
            balance: col(['مانده']),
            userNote: col(['توضیحات کاربر']),
        };
        if (idx.date < 0 || idx.amount < 0 || idx.type < 0) {
            throw new Error('قالب اکسل بانک ملی شناسایی نشد (ستون‌های تاریخ/نوع/مبلغ لازم است).');
        }
        const rows = [];
        for (let i = headerRow + 1; i < matrix.length; i++) {
            const cells = matrix[i] || [];
            const date = parseJalaliDate(cells[idx.date]);
            if (!date) continue;
            const typeRaw = normalizeHeader(cells[idx.type]);
            const amountRial = Math.abs(parseAmount(cells[idx.amount]));
            if (!amountRial) continue;

            let signedRial;
            if (typeRaw.includes('واریز') || typeRaw.includes('بستانکار') || typeRaw.includes('credit')) {
                signedRial = amountRial;
            } else if (typeRaw.includes('برداشت') || typeRaw.includes('بدهکار') || typeRaw.includes('debit')) {
                signedRial = -amountRial;
            } else {
                continue;
            }

            const desc = idx.desc >= 0 ? cellText(cells[idx.desc]) : '';
            const details = idx.details >= 0 ? cellText(cells[idx.details]) : '';
            const extra = idx.extra >= 0 ? cellText(cells[idx.extra]) : '';
            const tracking = idx.track >= 0 ? toEnglishDigits(cells[idx.track] || '').trim() : '';
            const balanceRaw = idx.balance >= 0 ? cellText(cells[idx.balance]) : '';
            const balanceRial = balanceRaw === '' ? null : parseAmount(cells[idx.balance]);
            const userNote = idx.userNote >= 0 ? cellText(cells[idx.userNote]) : '';
            const counterparty = extractCounterparty(details) || extractCounterparty(extra);

            const descriptionParts = [desc];
            if (extra) descriptionParts.push(extra);
            if (details) descriptionParts.push(details);

            rows.push({
                date,
                time: idx.time >= 0 ? toEnglishDigits(cells[idx.time] || '').trim() : null,
                amount: rialToToman(signedRial),
                balance: balanceRial === null ? null : rialToToman(balanceRial),
                description: descriptionParts.filter(Boolean).join(' — '),
                counterparty,
                reference: tracking || null,
                document_number: null,
                tracking_number: tracking || null,
                deposit_id: '',
                branch: idx.branch >= 0 ? cellText(cells[idx.branch]) : '',
                note: userNote
            });
        }
        return { rows, meta: extractMelliMeta(matrix, headerRow) };
    }

    function extractBlubankCounterparty(desc) {
        const s = cellText(desc);
        if (!s) return '';
        const m = s.match(/بنام\s*[:：]\s*([^\-|]+)/i);
        return m ? m[1].replace(/\s+/g, ' ').trim() : '';
    }

    function extractBlubankTracking(desc) {
        const s = toEnglishDigits(cellText(desc));
        const m = s.match(/ش\s*پ\s*[:：]?\s*(\d{3,})/i);
        return m ? m[1] : '';
    }

    function extractBlubankMeta(matrix, headerRow) {
        const meta = {
            account_number: '',
            account_holder: '',
            iban: '',
            period_from: '',
            period_to: '',
            bank_name: 'بلو (سامان)'
        };
        const scanTo = Math.max(0, headerRow);
        for (let r = 0; r < scanTo; r++) {
            const cells = (matrix[r] || []).map(cellText);
            const line = cells.filter(Boolean).join(' ');
            const nline = normalizeHeader(line);
            let m = line.match(/نام\s*صاحب\s*حساب\s*[:：]\s*(.+)/i);
            if (m) meta.account_holder = m[1].trim();
            m = line.match(/شماره\s*شبا\s*[:：]?\s*(IR[\d\s]+)/i);
            if (m) {
                meta.iban = toEnglishDigits(m[1]).replace(/\s/g, '');
                const digits = meta.iban.replace(/\D/g, '');
                if (digits.length >= IR_IBAN_ACCOUNT_SUFFIX_LEN) meta.account_number = digits.slice(-IR_IBAN_ACCOUNT_SUFFIX_LEN);
            }
            m = toEnglishDigits(line).match(/صورتحساب\s*از\s*تاریخ\s*([\d/]+(?:\s+[\d:]+)?)\s*تا\s*تاریخ\s*([\d/]+(?:\s+[\d:]+)?)/i);
            if (m) {
                meta.period_from = m[1].trim();
                meta.period_to = m[2].trim();
            }
            if (nline.includes('بلو') || nline.includes('سامان')) {
                meta.bank_name = 'بلو (سامان)';
            }
        }
        return meta;
    }

    function mapBlubankRows(matrix) {
        const headerRow = findBlubankHeaderRow(matrix);
        if (headerRow < 0) throw new Error('هدر صورتحساب بلو بانک (سامان) یافت نشد.');
        const headers = matrix[headerRow].map(normalizeHeader);
        const col = (preds) => headers.findIndex(h => preds.some(p => (typeof p === 'string' ? (p === h || h.includes(p)) : p(h))));
        const idx = {
            balance: col(['مانده']),
            debit: col(['برداشت']),
            credit: col(['واریز']),
            type: col(['نوع تراکنش', h => h.includes('نوع تراکنش')]),
            desc: col(['شرح سند', h => h.includes('شرح')]),
            doc: col(['شماره سند']),
            date: col([h => h === 'تاریخ', 'تاریخ']),
            row: col([h => h === 'ردیف']),
        };
        if (idx.date < 0 || (idx.credit < 0 && idx.debit < 0)) {
            throw new Error('قالب اکسل بلو بانک شناسایی نشد (ستون‌های تاریخ/واریز/برداشت لازم است).');
        }
        const rows = [];
        for (let i = headerRow + 1; i < matrix.length; i++) {
            const cells = matrix[i] || [];
            const { date, time } = parseJalaliDateTime(cells[idx.date]);
            if (!date) continue;
            const creditRial = idx.credit >= 0 ? parseAmount(cells[idx.credit]) : 0;
            const debitRial = idx.debit >= 0 ? parseAmount(cells[idx.debit]) : 0;
            if (creditRial === 0 && debitRial === 0) continue;
            const amount = creditRial > 0 ? rialToToman(creditRial) : -rialToToman(debitRial);
            const balanceRial = idx.balance >= 0 ? parseAmount(cells[idx.balance]) : null;
            const desc = idx.desc >= 0 ? cellText(cells[idx.desc]) : '';
            const txnType = idx.type >= 0 ? cellText(cells[idx.type]) : '';
            const docNo = idx.doc >= 0 ? toEnglishDigits(cells[idx.doc] || '').trim() : '';
            const tracking = extractBlubankTracking(desc);
            const counterparty = extractBlubankCounterparty(desc);
            const description = [txnType, desc].filter(Boolean).join(' — ');
            rows.push({
                date,
                time: time || null,
                amount,
                balance: balanceRial === null ? null : rialToToman(balanceRial),
                description,
                counterparty,
                reference: tracking || docNo || null,
                document_number: docNo || null,
                tracking_number: tracking || null,
                deposit_id: '',
                branch: '',
                note: txnType || ''
            });
        }
        return { rows, meta: extractBlubankMeta(matrix, headerRow) };
    }

    function sheetToMatrix(sheet) {
        if (typeof XLSX === 'undefined') {
            throw new Error('کتابخانه خواندن اکسل (SheetJS) بارگذاری نشده است.');
        }
        return XLSX.utils.sheet_to_json(sheet, { header: 1, raw: true, defval: '' });
    }

    function parseMellatCsv(text) {
        const matrix = parseCsv(text);
        const rows = mapMellatRows(matrix);
        if (!rows.length) throw new Error('هیچ تراکنشی در فایل ملت یافت نشد.');
        return { rows, summary: buildSummary(rows, 'mellat') };
    }

    function parseDeyWorkbook(workbook) {
        if (!workbook || !workbook.SheetNames || !workbook.SheetNames.length) {
            throw new Error('فایل اکسل بانک دی معتبر نیست.');
        }
        let matrix = null;
        for (const name of workbook.SheetNames) {
            const m = sheetToMatrix(workbook.Sheets[name]);
            if (findDeyHeaderRow(m) >= 0) {
                matrix = m;
                break;
            }
        }
        if (!matrix) {
            matrix = sheetToMatrix(workbook.Sheets[workbook.SheetNames[0]]);
        }
        const rows = mapDeyRows(matrix);
        if (!rows.length) throw new Error('هیچ تراکنشی در فایل بانک دی یافت نشد.');
        return { rows, summary: buildSummary(rows, 'dey') };
    }

    function parseMelliWorkbook(workbook) {
        if (!workbook || !workbook.SheetNames || !workbook.SheetNames.length) {
            throw new Error('فایل اکسل بانک ملی معتبر نیست.');
        }
        let matrix = null;
        for (const name of workbook.SheetNames) {
            const m = sheetToMatrix(workbook.Sheets[name]);
            if (findMelliHeaderRow(m) >= 0) {
                matrix = m;
                break;
            }
        }
        if (!matrix) {
            matrix = sheetToMatrix(workbook.Sheets[workbook.SheetNames[0]]);
        }
        const { rows, meta } = mapMelliRows(matrix);
        if (!rows.length) throw new Error('هیچ تراکنشی در فایل بانک ملی یافت نشد.');
        return { rows, summary: buildSummary(rows, 'melli', meta) };
    }

    function parseBlubankWorkbook(workbook) {
        if (!workbook || !workbook.SheetNames || !workbook.SheetNames.length) {
            throw new Error('فایل اکسل بلو بانک معتبر نیست.');
        }
        let matrix = null;
        for (const name of workbook.SheetNames) {
            const m = sheetToMatrix(workbook.Sheets[name]);
            if (findBlubankHeaderRow(m) >= 0 || matrixLooksLikeBlubank(m)) {
                matrix = m;
                break;
            }
        }
        if (!matrix) {
            matrix = sheetToMatrix(workbook.Sheets[workbook.SheetNames[0]]);
        }
        const { rows, meta } = mapBlubankRows(matrix);
        if (!rows.length) throw new Error('هیچ تراکنشی در فایل بلو بانک یافت نشد.');
        return { rows, summary: buildSummary(rows, 'blubank', meta) };
    }

    function detectExcelWorkbook(workbook) {
        for (const name of workbook.SheetNames || []) {
            const matrix = sheetToMatrix(workbook.Sheets[name]);
            const bluRow = findBlubankHeaderRow(matrix);
            const melliRow = findMelliHeaderRow(matrix);
            const deyRow = findDeyHeaderRow(matrix);
            // اولویت: بلو (قبل از دی، چون هر دو واریز/برداشت دارند)
            if (bluRow >= 0) return { format: 'blubank', matrix };
            if (melliRow >= 0 && deyRow < 0) return { format: 'melli', matrix };
            if (deyRow >= 0 && melliRow < 0) return { format: 'dey', matrix };
            if (melliRow >= 0) return { format: 'melli', matrix };
            if (deyRow >= 0) return { format: 'dey', matrix };
            if (matrixLooksLikeBlubank(matrix)) return { format: 'blubank', matrix };
        }
        const first = sheetToMatrix(workbook.Sheets[workbook.SheetNames[0]]);
        if (findBlubankHeaderRow(first) >= 0 || matrixLooksLikeBlubank(first)) {
            return { format: 'blubank', matrix: first };
        }
        if (findMelliHeaderRow(first) >= 0) return { format: 'melli', matrix: first };
        if (findDeyHeaderRow(first) >= 0) return { format: 'dey', matrix: first };
        return { format: null, matrix: first };
    }

    function parseGenericCsv(text) {
        const matrix = parseCsv(text);
        const headers = (matrix[0] || []).map(normalizeHeader);
        const dateIdx = headers.findIndex(h => h.includes('date') || h.includes('تاریخ'));
        const amountIdx = headers.findIndex(h => h.includes('amount') || h === 'مبلغ' || h.includes('مبلغ'));
        const descIdx = headers.findIndex(h => h.includes('desc') || h.includes('شرح'));
        const refIdx = headers.findIndex(h => h.includes('ref') || h.includes('پیگیری') || h.includes('مرجع'));
        if (dateIdx < 0 || amountIdx < 0) throw new Error('قالب CSV عمومی شناسایی نشد (نیاز به ستون تاریخ و مبلغ).');
        const rows = [];
        for (let i = 1; i < matrix.length; i++) {
            const cells = matrix[i];
            let date = parseJalaliDate(cells[dateIdx]);
            if (!date) {
                const eng = toEnglishDigits(cells[dateIdx] || '').trim();
                if (/^\d{4}-\d{2}-\d{2}/.test(eng)) date = eng.slice(0, 10);
            }
            if (!date) continue;
            const amt = parseAmount(cells[amountIdx]);
            if (!amt) continue;
            rows.push({
                date,
                time: null,
                amount: amt,
                balance: null,
                description: descIdx >= 0 ? String(cells[descIdx] || '').trim() : '',
                counterparty: '',
                reference: refIdx >= 0 ? toEnglishDigits(cells[refIdx] || '').trim() : '',
                document_number: null,
                tracking_number: null,
                deposit_id: '',
                branch: '',
                note: ''
            });
        }
        return { rows, summary: buildSummary(rows, 'generic') };
    }

    /**
     * Parse a File object (csv/xls/xlsx).
     * @returns {Promise<{rows: Array, summary: Object}>}
     */
    function parseFile(file) {
        return new Promise((resolve, reject) => {
            const name = (file.name || '').toLowerCase();
            const reader = new FileReader();
            reader.onerror = () => reject(new Error('خطا در خواندن فایل.'));

            if (name.endsWith('.csv') || name.endsWith('.txt')) {
                reader.onload = () => {
                    try {
                        const text = reader.result;
                        const matrix = parseCsv(text);
                        const detected = detectFormatFromHeaders(matrix[0] || []);
                        if (detected === 'mellat' || (!detected && String(matrix[0]?.join(',') || '').includes('بستانکار'))) {
                            resolve(parseMellatCsv(text));
                        } else if (detected === 'blubank') {
                            const { rows, meta } = mapBlubankRows(matrix);
                            if (!rows.length) throw new Error('هیچ تراکنشی در فایل بلو بانک یافت نشد.');
                            resolve({ rows, summary: buildSummary(rows, 'blubank', meta) });
                        } else if (detected === 'melli') {
                            const { rows, meta } = mapMelliRows(matrix);
                            if (!rows.length) throw new Error('هیچ تراکنشی در فایل بانک ملی یافت نشد.');
                            resolve({ rows, summary: buildSummary(rows, 'melli', meta) });
                        } else if (detected === 'dey') {
                            const rows = mapDeyRows(matrix);
                            resolve({ rows, summary: buildSummary(rows, 'dey') });
                        } else {
                            resolve(parseGenericCsv(text));
                        }
                    } catch (e) {
                        reject(e);
                    }
                };
                reader.readAsText(file, 'UTF-8');
                return;
            }

            if (name.endsWith('.xls') || name.endsWith('.xlsx')) {
                if (typeof XLSX === 'undefined') {
                    reject(new Error('برای فایل اکسل، کتابخانه SheetJS باید بارگذاری شود.'));
                    return;
                }
                reader.onload = () => {
                    try {
                        const data = new Uint8Array(reader.result);
                        const workbook = XLSX.read(data, { type: 'array' });
                        const detected = detectExcelWorkbook(workbook);
                        if (detected.format === 'blubank') {
                            resolve(parseBlubankWorkbook(workbook));
                        } else if (detected.format === 'melli') {
                            resolve(parseMelliWorkbook(workbook));
                        } else if (detected.format === 'dey') {
                            resolve(parseDeyWorkbook(workbook));
                        } else {
                            try {
                                resolve(parseBlubankWorkbook(workbook));
                            } catch (e0) {
                                try {
                                    resolve(parseMelliWorkbook(workbook));
                                } catch (e1) {
                                    try {
                                        resolve(parseDeyWorkbook(workbook));
                                    } catch (e2) {
                                        reject(new Error('قالب اکسل شناسایی نشد. قالب‌های پشتیبانی‌شده: ' + supportedFormatsLabel()));
                                    }
                                }
                            }
                        }
                    } catch (e) {
                        reject(e);
                    }
                };
                reader.readAsArrayBuffer(file);
                return;
            }

            reject(new Error('فرمت فایل پشتیبانی نمی‌شود. CSV یا Excel (xls/xlsx) انتخاب کنید.'));
        });
    }

    return {
        parseFile,
        parseMellatCsv,
        parseDeyWorkbook,
        parseMelliWorkbook,
        parseBlubankWorkbook,
        parseGenericCsv,
        detectFormatFromHeaders,
        rialToToman,
        parseJalaliDate,
        toEnglishDigits,
        BANK_FORMATS,
        supportedFormatsLabel
    };
})();
