<?php
// /api/controllers/ImportExportController.php

class ImportExportController {
    private $conn;

    // تعریف انواع داده‌های قابل پشتیبانی با جزییات کامل
    private $dataTypes = [
        'customers' => [
            'label' => 'مشتریان',
            'table' => 'customers',
            'columns' => ['id', 'name', 'phone', 'address', 'nationalId', 'initial_balance'],
            'headers' => ['شناسه', 'نام', 'تلفن', 'آدرس', 'کد ملی', 'مانده اولیه'],
            'name_col' => 'name',
            'import_columns' => ['name', 'phone', 'address', 'nationalId', 'initial_balance'],
            'import_types' => 'ssssd',
            'upsert_update' => 'phone = VALUES(phone), address = VALUES(address), nationalId = VALUES(nationalId)',
            'required' => 'name'
        ],
        'suppliers' => [
            'label' => 'تأمین‌کنندگان',
            'table' => 'suppliers',
            'columns' => ['id', 'name', 'phone', 'address', 'economicCode', 'initial_balance'],
            'headers' => ['شناسه', 'نام', 'تلفن', 'آدرس', 'کد اقتصادی', 'مانده اولیه'],
            'name_col' => 'name',
            'import_columns' => ['name', 'phone', 'address', 'economicCode', 'initial_balance'],
            'import_types' => 'ssssd',
            'upsert_update' => 'phone = VALUES(phone), address = VALUES(address), economicCode = VALUES(economicCode)',
            'required' => 'name'
        ],
        'products' => [
            'label' => 'محصولات',
            'table' => 'products',
            'columns' => ['id', 'name', 'description', 'price', 'unit', 'sku', 'design_code', 'colorway', 'min_stock', 'max_stock', 'reorder_point', 'weight', 'is_active'],
            'headers' => ['شناسه', 'نام', 'توضیحات', 'قیمت', 'واحد', 'کد SKU', 'کد طرح', 'رنگبندی', 'حداقل موجودی', 'حداکثر موجودی', 'نقطه سفارش', 'وزن', 'فعال'],
            'name_col' => 'name',
            'import_columns' => ['name', 'description', 'price', 'unit', 'sku', 'design_code', 'colorway'],
            'import_types' => 'ssdssss',
            'upsert_update' => 'description = VALUES(description), price = VALUES(price), unit = VALUES(unit)',
            'required' => 'name'
        ],
        'accounts' => [
            'label' => 'حساب‌های بانکی',
            'table' => 'accounts',
            'columns' => ['id', 'name', 'bank_name', 'account_number', 'card_number', 'initial_balance', 'current_balance', 'is_cash'],
            'headers' => ['شناسه', 'نام حساب', 'نام بانک', 'شماره حساب', 'شماره کارت', 'مانده اولیه', 'مانده فعلی', 'صندوق نقدی'],
            'name_col' => 'name',
            'import_columns' => ['name', 'bank_name', 'account_number', 'card_number', 'initial_balance', 'is_cash'],
            'import_types' => 'ssssdi',
            'upsert_update' => 'bank_name = VALUES(bank_name), account_number = VALUES(account_number), card_number = VALUES(card_number)',
            'required' => 'name'
        ],
        'partners' => [
            'label' => 'شرکا',
            'table' => 'partners',
            'columns' => ['id', 'name', 'share'],
            'headers' => ['شناسه', 'نام', 'سهم'],
            'name_col' => 'name',
            'import_columns' => ['name', 'share'],
            'import_types' => 'sd',
            'upsert_update' => 'share = VALUES(share)',
            'required' => 'name'
        ],
        'product_categories' => [
            'label' => 'دسته‌بندی محصولات',
            'table' => 'product_categories',
            'columns' => ['id', 'name', 'description', 'sort_order', 'icon', 'color'],
            'headers' => ['شناسه', 'نام', 'توضیحات', 'ترتیب', 'آیکون', 'رنگ'],
            'name_col' => 'name',
            'import_columns' => ['name', 'description', 'sort_order'],
            'import_types' => 'ssi',
            'upsert_update' => 'description = VALUES(description)',
            'required' => 'name'
        ],
        'product_collections' => [
            'label' => 'کلکسیون محصولات',
            'table' => 'product_collections',
            'columns' => ['id', 'name', 'type', 'price_per_meter'],
            'headers' => ['شناسه', 'نام', 'نوع', 'قیمت هر متر'],
            'name_col' => 'name'
        ],
        'warehouses' => [
            'label' => 'انبارها',
            'table' => 'warehouses',
            'columns' => ['id', 'name', 'address', 'phone', 'is_default', 'is_active'],
            'headers' => ['شناسه', 'نام', 'آدرس', 'تلفن', 'پیش‌فرض', 'فعال'],
            'name_col' => 'name',
            'import_columns' => ['name', 'address', 'phone'],
            'import_types' => 'sss',
            'upsert_update' => 'address = VALUES(address), phone = VALUES(phone)',
            'required' => 'name'
        ],
        'sales_invoices' => [
            'label' => 'فاکتورهای فروش',
            'table' => 'sales_invoices',
            'columns' => ['id', 'invoice_number', 'customerId', 'date', 'due_date', 'totalAmount', 'discount', 'tax_amount', 'paidAmount', 'description', 'is_consignment', 'delivery_status'],
            'headers' => ['شناسه', 'شماره فاکتور', 'شناسه مشتری', 'تاریخ', 'سررسید', 'مبلغ کل', 'تخفیف', 'مالیات', 'پرداخت شده', 'توضیحات', 'امانی', 'وضعیت تحویل'],
            'name_col' => 'invoice_number',
            'join' => 'LEFT JOIN customers c ON sales_invoices.customerId = c.id',
            'extra_select' => ', c.name as customer_name'
        ],
        'sales_invoice_items' => [
            'label' => 'آیتم‌های فاکتور فروش',
            'table' => 'sales_invoice_items',
            'columns' => ['id', 'invoiceId', 'productId', 'quantity', 'unitPrice', 'dimensions', 'is_service', 'item_description'],
            'headers' => ['شناسه', 'شناسه فاکتور', 'شناسه محصول', 'تعداد', 'قیمت واحد', 'ابعاد', 'خدمات', 'توضیح آیتم'],
            'name_col' => 'id',
            'entity_via' => 'sales_invoices',
            'entity_join' => 'INNER JOIN sales_invoices si ON sales_invoice_items.invoiceId = si.id'
        ],
        'purchase_invoices' => [
            'label' => 'فاکتورهای خرید',
            'table' => 'purchase_invoices',
            'columns' => ['id', 'invoice_number', 'supplierId', 'date', 'due_date', 'totalAmount', 'discount', 'tax_amount', 'paidAmount', 'description', 'is_consignment', 'delivery_status'],
            'headers' => ['شناسه', 'شماره فاکتور', 'شناسه تأمین‌کننده', 'تاریخ', 'سررسید', 'مبلغ کل', 'تخفیف', 'مالیات', 'پرداخت شده', 'توضیحات', 'امانی', 'وضعیت تحویل'],
            'name_col' => 'invoice_number',
            'join' => 'LEFT JOIN suppliers s ON purchase_invoices.supplierId = s.id',
            'extra_select' => ', s.name as supplier_name'
        ],
        'purchase_invoice_items' => [
            'label' => 'آیتم‌های فاکتور خرید',
            'table' => 'purchase_invoice_items',
            'columns' => ['id', 'invoiceId', 'productId', 'quantity', 'unitPrice', 'dimensions', 'is_service', 'item_description'],
            'headers' => ['شناسه', 'شناسه فاکتور', 'شناسه محصول', 'تعداد', 'قیمت واحد', 'ابعاد', 'خدمات', 'توضیح آیتم'],
            'name_col' => 'id',
            'entity_via' => 'purchase_invoices',
            'entity_join' => 'INNER JOIN purchase_invoices pi ON purchase_invoice_items.invoiceId = pi.id'
        ],
        'payments' => [
            'label' => 'پرداخت‌ها',
            'table' => 'payments',
            'columns' => ['id', 'invoiceId', 'invoiceType', 'person_id', 'person_type', 'transaction_type', 'type', 'amount', 'date', 'description', 'checkId', 'account_id'],
            'headers' => ['شناسه', 'شناسه فاکتور', 'نوع فاکتور', 'شناسه شخص', 'نوع شخص', 'نوع تراکنش', 'روش پرداخت', 'مبلغ', 'تاریخ', 'توضیحات', 'شناسه چک', 'شناسه حساب'],
            'name_col' => 'id'
        ],
        'checks' => [
            'label' => 'چک‌ها',
            'table' => 'checks',
            'columns' => ['id', 'type', 'status', 'checkNumber', 'dueDate', 'bankName', 'amount', 'invoiceId', 'invoiceType', 'endorsedToInvoiceId', 'cashed_in_account_id'],
            'headers' => ['شناسه', 'نوع', 'وضعیت', 'شماره چک', 'سررسید', 'نام بانک', 'مبلغ', 'شناسه فاکتور', 'نوع فاکتور', 'ظهرنویسی به', 'واریز به حساب'],
            'name_col' => 'checkNumber'
        ],
        'expenses' => [
            'label' => 'هزینه‌ها',
            'table' => 'expenses',
            'columns' => ['id', 'category', 'date', 'amount', 'description', 'account_id'],
            'headers' => ['شناسه', 'دسته‌بندی', 'تاریخ', 'مبلغ', 'توضیحات', 'شناسه حساب'],
            'name_col' => 'category',
            'import_columns' => ['category', 'date', 'amount', 'description', 'account_id'],
            'import_types' => 'ssdsi',
            'upsert_update' => null,
            'required' => 'category'
        ],
        'quotations' => [
            'label' => 'پیش‌فاکتورها',
            'table' => 'quotations',
            'columns' => ['id', 'customerId', 'date', 'valid_until', 'totalAmount', 'discount', 'description', 'status', 'converted_invoice_id'],
            'headers' => ['شناسه', 'شناسه مشتری', 'تاریخ', 'معتبر تا', 'مبلغ کل', 'تخفیف', 'توضیحات', 'وضعیت', 'فاکتور تبدیل شده'],
            'name_col' => 'id',
            'join' => 'LEFT JOIN customers c ON quotations.customerId = c.id',
            'extra_select' => ', c.name as customer_name'
        ],
        'quotation_items' => [
            'label' => 'آیتم‌های پیش‌فاکتور',
            'table' => 'quotation_items',
            'columns' => ['id', 'quotationId', 'productId', 'quantity', 'unitPrice', 'dimensions'],
            'headers' => ['شناسه', 'شناسه پیش‌فاکتور', 'شناسه محصول', 'تعداد', 'قیمت واحد', 'ابعاد'],
            'name_col' => 'id',
            'entity_via' => 'quotations',
            'entity_join' => 'INNER JOIN quotations q ON quotation_items.quotationId = q.id'
        ],
        'return_invoices' => [
            'label' => 'فاکتورهای برگشت',
            'table' => 'return_invoices',
            'columns' => ['id', 'type', 'original_invoice_id', 'original_invoice_type', 'person_id', 'person_type', 'date', 'totalAmount', 'discount', 'paidAmount', 'description'],
            'headers' => ['شناسه', 'نوع', 'شناسه فاکتور اصلی', 'نوع فاکتور اصلی', 'شناسه شخص', 'نوع شخص', 'تاریخ', 'مبلغ کل', 'تخفیف', 'پرداخت شده', 'توضیحات'],
            'name_col' => 'id'
        ],
        'return_invoice_items' => [
            'label' => 'آیتم‌های فاکتور برگشت',
            'table' => 'return_invoice_items',
            'columns' => ['id', 'return_invoice_id', 'productId', 'quantity', 'unitPrice', 'dimensions'],
            'headers' => ['شناسه', 'شناسه فاکتور برگشت', 'شناسه محصول', 'تعداد', 'قیمت واحد', 'ابعاد'],
            'name_col' => 'id',
            'entity_via' => 'return_invoices',
            'entity_join' => 'INNER JOIN return_invoices ri ON return_invoice_items.return_invoice_id = ri.id'
        ],
        'inventory_items' => [
            'label' => 'موجودی انبار',
            'table' => 'inventory_items',
            'columns' => ['id', 'product_id', 'warehouse_id', 'dimensions', 'serial_number', 'batch_number', 'cost_price', 'status', 'notes', 'entry_date'],
            'headers' => ['شناسه', 'شناسه محصول', 'شناسه انبار', 'ابعاد', 'شماره سریال', 'شماره بچ', 'قیمت تمام‌شده', 'وضعیت', 'یادداشت', 'تاریخ ورود'],
            'name_col' => 'id'
        ],
        'installments' => [
            'label' => 'اقساط',
            'table' => 'installments',
            'columns' => ['id', 'invoice_id', 'invoice_type', 'installment_number', 'amount', 'due_date', 'paid_amount', 'paid_date', 'status', 'description'],
            'headers' => ['شناسه', 'شناسه فاکتور', 'نوع فاکتور', 'شماره قسط', 'مبلغ', 'سررسید', 'مبلغ پرداختی', 'تاریخ پرداخت', 'وضعیت', 'توضیحات'],
            'name_col' => 'id'
        ],
    ];

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * دریافت لیست انواع داده قابل پشتیبانی
     */
    public function getDataTypes($data) {
        $types = [];
        foreach ($this->dataTypes as $key => $config) {
            $types[$key] = [
                'label' => $config['label'],
                'importable' => isset($config['import_columns']),
            ];
        }
        send_json(['types' => $types]);
    }

    /**
     * دریافت آیتم‌های یک نوع داده برای انتخاب (خروجی انتخابی)
     */
    public function getExportItems($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $type = $data['type'] ?? '';

        if (!isset($this->dataTypes[$type])) {
            send_json(['error' => 'نوع داده نامعتبر.'], 400); return;
        }

        $config = $this->dataTypes[$type];
        $table = $config['table'];
        $cols = $config['columns'];
        $colStr = implode(', ', array_map(fn($c) => "{$table}.{$c}", $cols));
        $extraSelect = $config['extra_select'] ?? '';

        // ساخت کوئری بر اساس نوع
        if (isset($config['entity_via'])) {
            // Join for child tables (invoice items, etc.)
            $join = $config['entity_join'];
            $parentTable = $config['entity_via'];
            $sql = "SELECT {$colStr}{$extraSelect} FROM {$table} {$join} WHERE {$parentTable}.entity_id = ? ORDER BY {$table}.id DESC";
        } elseif (isset($config['join'])) {
            $join = $config['join'];
            $sql = "SELECT {$colStr}{$extraSelect} FROM {$table} {$join} WHERE {$table}.entity_id = ? ORDER BY {$table}.id DESC";
        } else {
            $sql = "SELECT {$colStr} FROM {$table} WHERE {$table}.entity_id = ? ORDER BY {$table}.id DESC";
        }

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            send_json(['error' => 'خطا در اجرای کوئری: ' . $this->conn->error], 500); return;
        }
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);

        send_json([
            'items' => $rows,
            'headers' => $config['headers'],
            'columns' => $config['columns'],
            'label' => $config['label'],
            'name_col' => $config['name_col']
        ]);
    }

    /**
     * خروجی CSV - با قابلیت انتخاب آیتم‌های خاص
     */
    public function exportCsv($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $type = $data['type'] ?? '';
        $selectedIds = $data['ids'] ?? []; // آرایه‌ای از شناسه‌ها (اختیاری)
        $format = $data['format'] ?? 'csv'; // csv or json

        if (!isset($this->dataTypes[$type])) {
            send_json(['error' => 'نوع داده نامعتبر.'], 400); return;
        }

        $config = $this->dataTypes[$type];
        $table = $config['table'];
        $cols = $config['columns'];
        $colStr = implode(', ', array_map(fn($c) => "{$table}.{$c}", $cols));
        $extraSelect = $config['extra_select'] ?? '';

        // ساخت کوئری
        if (isset($config['entity_via'])) {
            $join = $config['entity_join'];
            $parentTable = $config['entity_via'];
            $sql = "SELECT {$colStr}{$extraSelect} FROM {$table} {$join} WHERE {$parentTable}.entity_id = ?";
        } elseif (isset($config['join'])) {
            $join = $config['join'];
            $sql = "SELECT {$colStr}{$extraSelect} FROM {$table} {$join} WHERE {$table}.entity_id = ?";
        } else {
            $sql = "SELECT {$colStr} FROM {$table} WHERE {$table}.entity_id = ?";
        }

        $params = [$entity_id];
        $paramTypes = 'i';

        // فیلتر بر اساس شناسه‌های انتخابی
        if (!empty($selectedIds)) {
            $selectedIds = array_map('intval', $selectedIds);
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $sql .= " AND {$table}.id IN ({$placeholders})";
            $paramTypes .= str_repeat('i', count($selectedIds));
            $params = array_merge($params, $selectedIds);
        }

        $sql .= " ORDER BY {$table}.id";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            send_json(['error' => 'خطا در اجرای کوئری: ' . $this->conn->error], 500); return;
        }
        $stmt->bind_param($paramTypes, ...$params);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);

        if ($format === 'json') {
            send_json(['rows' => $rows, 'type' => $type, 'label' => $config['label']]);
            return;
        }

        // تبدیل به CSV
        $headers = $config['headers'];
        // اگر extra_select داریم، هدر اضافه کنیم
        if (!empty($extraSelect)) {
            if ($type === 'sales_invoices' || $type === 'quotations') {
                $headers[] = 'نام مشتری';
            } elseif ($type === 'purchase_invoices') {
                $headers[] = 'نام تأمین‌کننده';
            }
        }

        $csv = implode(',', $headers) . "\n";
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $val) {
                $val = str_replace('"', '""', $val ?? '');
                $line[] = '"' . $val . '"';
            }
            $csv .= implode(',', $line) . "\n";
        }

        $count = count($rows);
        log_activity($this->conn, 'EXPORT_CSV', "خروجی {$config['label']}: {$count} رکورد.");
        send_json(['csv' => $csv, 'type' => $type, 'count' => $count, 'label' => $config['label']]);
    }

    /**
     * ورود داده از CSV
     */
    public function importCsv($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $type = $data['type'] ?? '';
        $csvData = $data['csv_data'] ?? '';
        $rows = $data['rows'] ?? [];

        if (!isset($this->dataTypes[$type])) {
            send_json(['error' => 'نوع داده نامعتبر.'], 400); return;
        }

        $config = $this->dataTypes[$type];
        if (!isset($config['import_columns'])) {
            send_json(['error' => "ورود داده برای «{$config['label']}» پشتیبانی نمی‌شود."], 400); return;
        }

        // اگر CSV خام ارسال شده، آن را پارس کنیم
        if (!empty($csvData) && empty($rows)) {
            $rows = $this->parseCsv($csvData, $config['import_columns']);
        }

        if (empty($rows)) {
            send_json(['error' => 'داده‌ای وارد نشده یا فایل خالی است.'], 400); return;
        }

        $dryRun = !empty($data['dry_run']);

        // Expenses are financial documents: they must go through the Expense model
        // (account ownership, wallet balance, closed-period lock, date validation).
        // A raw INSERT used to create expenses that never reduced any account.
        if ($config['table'] === 'expenses') {
            $this->importExpenses($rows, $dryRun);
            return;
        }

        $this->conn->begin_transaction();
        try {
            $inserted = 0;
            $skipped = 0;
            $errors = [];
            $preview = [];

            $importCols = $config['import_columns'];
            $table = $config['table'];
            $isAccounts = ($table === 'accounts');
            $colList = implode(', ', $importCols) . ', entity_id';
            $placeholders = implode(', ', array_fill(0, count($importCols), '?')) . ', ?';
            $updateClause = $config['upsert_update'];

            if ($isAccounts) {
                // Imported accounts must start with current_balance = initial_balance,
                // otherwise the account balance stays 0 and all ledgers are wrong.
                $colList = implode(', ', $importCols) . ', current_balance, entity_id';
                $placeholders = implode(', ', array_fill(0, count($importCols), '?')) . ', ?, ?';
                $updateClause = ($updateClause ? $updateClause . ', ' : '')
                    . 'current_balance = IF(current_balance = 0 OR current_balance IS NULL, VALUES(initial_balance), current_balance)';
            }

            if ($updateClause) {
                $sql = "INSERT INTO {$table} ({$colList}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$updateClause}";
            } else {
                $sql = "INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})";
            }

            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('خطا در آماده‌سازی کوئری: ' . $this->conn->error);
            }

            $requiredField = $config['required'];
            $importTypes = $config['import_types'] . 'i'; // +entity_id
            if ($isAccounts) {
                $importTypes = $config['import_types'] . 'di'; // +current_balance +entity_id
            }

            foreach ($rows as $idx => $row) {
                // بررسی فیلد اجباری
                $reqVal = trim($row[$requiredField] ?? '');
                if (empty($reqVal)) { $skipped++; continue; }

                // ساخت مقادیر
                $values = [];
                foreach ($importCols as $col) {
                    $val = trim($row[$col] ?? '');
                    // تبدیل مقادیر عددی
                    if (strpos($config['import_types'], 'd') !== false) {
                        $colIdx = array_search($col, $importCols);
                        if ($config['import_types'][$colIdx] === 'd') {
                            $val = floatval(str_replace(',', '', $val));
                        } elseif ($config['import_types'][$colIdx] === 'i') {
                            $val = intval($val);
                        }
                    }
                    $values[] = $val;
                }
                if ($isAccounts) {
                    // Mirror initial_balance into current_balance for a fresh import.
                    $initIdx = array_search('initial_balance', $importCols);
                    $values[] = ($initIdx !== false) ? floatval($values[$initIdx]) : 0;
                }
                $values[] = $entity_id;

                try {
                    $stmt->bind_param($importTypes, ...$values);
                    $stmt->execute();
                    // ON DUPLICATE KEY UPDATE: 1 = inserted, 2 = updated, 0 = unchanged
                    $aff = $stmt->affected_rows;
                    if ($aff > 0) {
                        $inserted++;
                    } else {
                        $skipped++;
                    }
                    if (count($preview) < 50) {
                        $preview[] = ['row' => $idx + 1, 'name' => $reqVal, 'status' => $aff === 1 ? 'new' : ($aff === 2 ? 'update' : 'unchanged')];
                    }
                } catch (Exception $rowEx) {
                    $skipped++;
                    $errors[] = "ردیف " . ($idx + 1) . ": " . $rowEx->getMessage();
                    if (count($preview) < 50) {
                        $preview[] = ['row' => $idx + 1, 'name' => $reqVal, 'status' => 'error', 'error' => $rowEx->getMessage()];
                    }
                }
            }

            if ($dryRun) {
                // Preview: run the real statements, report, then undo everything.
                $this->conn->rollback();
                send_json([
                    'success' => true, 'dry_run' => true,
                    'would_insert' => $inserted, 'would_skip' => $skipped,
                    'preview' => $preview, 'errors' => array_slice($errors, 0, 20),
                    'message' => "پیش‌نمایش: {$inserted} ردیف وارد/به‌روزرسانی می‌شود و {$skipped} ردیف رد می‌شود. هنوز چیزی ذخیره نشده است.",
                ]);
                return;
            }

            $this->conn->commit();
            log_activity($this->conn, 'IMPORT_CSV', "ورود {$config['label']}: {$inserted} رکورد جدید، {$skipped} رد شده.");

            $msg = "{$inserted} رکورد وارد شد.";
            if ($skipped > 0) $msg .= " {$skipped} رکورد رد شد.";

            $result = ['success' => true, 'inserted' => $inserted, 'skipped' => $skipped, 'message' => $msg];
            if (!empty($errors)) $result['errors'] = array_slice($errors, 0, 10);
            send_json($result);
        } catch (Exception $e) {
            $this->conn->rollback();
            send_json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Import expense rows one by one through Expense::save (own transaction per
     * row). Dates may be Gregorian or Jalali; the account may be given by id or
     * by exact account name. With $dryRun nothing is written.
     */
    private function importExpenses(array $rows, $dryRun) {
        require_once __DIR__ . '/../models/Expense.php';
        $entityId = (int)$_SESSION['current_entity_id'];
        $accounts = [];
        $res = $this->conn->prepare("SELECT id, name FROM accounts WHERE entity_id = ?");
        $res->bind_param('i', $entityId);
        $res->execute();
        foreach (db_stmt_to_assoc_array($res) as $a) {
            $accounts[(int)$a['id']] = $a['name'];
        }
        $byName = array_flip(array_map('trim', $accounts));
        $closed = get_entity_last_closed_date($this->conn, $entityId);

        $expenseModel = new Expense($this->conn);
        $inserted = 0; $skipped = 0; $errors = []; $preview = [];
        foreach ($rows as $idx => $row) {
            $rowNo = $idx + 1;
            $category = trim((string)($row['category'] ?? ''));
            $amount = parse_money($row['amount'] ?? 0);
            $date = normalize_input_date($row['date'] ?? '');
            $accRaw = trim((string)($row['account_id'] ?? ''));
            $accountId = ctype_digit($accRaw) ? (int)$accRaw : (int)($byName[$accRaw] ?? 0);
            $problem = null;
            if ($category === '') $problem = 'دسته‌بندی خالی است';
            elseif ($amount <= 0) $problem = 'مبلغ نامعتبر است';
            elseif (!$date) $problem = 'تاریخ نامعتبر است';
            elseif (!isset($accounts[$accountId])) $problem = 'حساب یافت نشد';
            elseif ($closed && $date <= $closed) $problem = "تاریخ در دوره بسته‌شده (تا {$closed}) است";

            if ($problem === null && !$dryRun) {
                $r = $expenseModel->save([
                    'category' => $category, 'date' => $date, 'amount' => $amount,
                    'description' => trim((string)($row['description'] ?? '')), 'account_id' => $accountId,
                ]);
                if (empty($r['success'])) {
                    $problem = $r['error'] ?? 'خطا';
                }
            }
            if ($problem === null) {
                $inserted++;
            } else {
                $skipped++;
                $errors[] = "ردیف {$rowNo}: {$problem}";
            }
            if (count($preview) < 50) {
                $preview[] = ['row' => $rowNo, 'name' => $category, 'amount' => $amount, 'date' => $date,
                    'account' => $accounts[$accountId] ?? $accRaw, 'status' => $problem ? 'error' : 'new', 'error' => $problem];
            }
        }
        if ($dryRun) {
            send_json([
                'success' => true, 'dry_run' => true, 'would_insert' => $inserted, 'would_skip' => $skipped,
                'preview' => $preview, 'errors' => array_slice($errors, 0, 20),
                'message' => "پیش‌نمایش: {$inserted} هزینه ثبت می‌شود و {$skipped} ردیف رد می‌شود. هنوز چیزی ذخیره نشده است.",
            ]);
            return;
        }
        log_activity($this->conn, 'IMPORT_CSV', "ورود هزینه‌ها: {$inserted} ثبت، {$skipped} رد شده.");
        $result = ['success' => true, 'inserted' => $inserted, 'skipped' => $skipped,
            'message' => "{$inserted} هزینه ثبت شد و از موجودی حساب‌ها کسر گردید." . ($skipped ? " {$skipped} ردیف رد شد." : '')];
        if (!empty($errors)) $result['errors'] = array_slice($errors, 0, 20);
        send_json($result);
    }

    /**
     * پارس کردن متن CSV به آرایه
     */
    private function parseCsv($csvText, $expectedColumns) {
        $rows = [];
        // Strip a UTF-8 BOM (Excel) and parse with fgetcsv so quoted fields may
        // contain commas and line breaks (line-by-line splitting broke them).
        $csvText = preg_replace('/^\xEF\xBB\xBF/', '', (string)$csvText);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csvText);
        rewind($fh);
        $headers = fgetcsv($fh, 0, ',', '"', '\\');
        if (!$headers) {
            fclose($fh);
            return $rows;
        }
        // Excel in Persian locales often saves with ';' — detect it from the header.
        if (count($headers) === 1 && strpos((string)$headers[0], ';') !== false) {
            rewind($fh);
            $delimiter = ';';
            $headers = fgetcsv($fh, 0, $delimiter, '"', '\\');
        } else {
            $delimiter = ',';
        }
        $headers = array_map('trim', $headers);

        // سعی می‌کنیم هدرهای فایل را با ستون‌های مورد انتظار مپ کنیم
        $headerMap = $this->mapHeaders($headers, $expectedColumns);

        while (($values = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
            if ($values === [null] || (count($values) === 1 && trim((string)$values[0]) === '')) {
                continue;
            }
            $row = [];
            foreach ($expectedColumns as $col) {
                if (isset($headerMap[$col]) && isset($values[$headerMap[$col]])) {
                    $row[$col] = neutralize_markup(trim((string)$values[$headerMap[$col]]));
                } else {
                    $row[$col] = '';
                }
            }
            $rows[] = $row;
        }
        fclose($fh);

        return $rows;
    }

    /**
     * مپ کردن هدرهای CSV به ستون‌های دیتابیس (پشتیبانی از فارسی و انگلیسی)
     */
    private function mapHeaders($fileHeaders, $expectedColumns) {
        $map = [];
        // مپ فارسی به انگلیسی
        $aliases = [
            'نام' => 'name', 'name' => 'name',
            'تلفن' => 'phone', 'phone' => 'phone',
            'آدرس' => 'address', 'address' => 'address',
            'کد ملی' => 'nationalId', 'nationalid' => 'nationalId', 'national_id' => 'nationalId',
            'کد اقتصادی' => 'economicCode', 'economiccode' => 'economicCode', 'economic_code' => 'economicCode',
            'مانده اولیه' => 'initial_balance', 'initial_balance' => 'initial_balance',
            'توضیحات' => 'description', 'description' => 'description',
            'قیمت' => 'price', 'price' => 'price',
            'واحد' => 'unit', 'unit' => 'unit',
            'کد' => 'sku', 'sku' => 'sku',
            'کد طرح' => 'design_code', 'design_code' => 'design_code',
            'رنگبندی' => 'colorway', 'colorway' => 'colorway',
            'نام بانک' => 'bank_name', 'bank_name' => 'bank_name',
            'شماره حساب' => 'account_number', 'account_number' => 'account_number',
            'شماره کارت' => 'card_number', 'card_number' => 'card_number',
            'صندوق نقدی' => 'is_cash', 'is_cash' => 'is_cash',
            'سهم' => 'share', 'share' => 'share',
            'ترتیب' => 'sort_order', 'sort_order' => 'sort_order',
            'دسته‌بندی' => 'category', 'category' => 'category',
            'تاریخ' => 'date', 'date' => 'date',
            'مبلغ' => 'amount', 'amount' => 'amount',
            'شناسه حساب' => 'account_id', 'account_id' => 'account_id',
        ];

        foreach ($fileHeaders as $idx => $header) {
            $normalized = mb_strtolower(trim($header));
            if (isset($aliases[$normalized])) {
                $colName = $aliases[$normalized];
                if (in_array($colName, $expectedColumns)) {
                    $map[$colName] = $idx;
                }
            }
            // تطابق مستقیم
            if (in_array($normalized, $expectedColumns) && !isset($map[$normalized])) {
                $map[$normalized] = $idx;
            }
        }

        // اگر هیچ مپی پیدا نشد، ستون‌ها را به ترتیب مپ کن
        if (empty($map)) {
            foreach ($expectedColumns as $i => $col) {
                if ($i < count($fileHeaders)) {
                    $map[$col] = $i;
                }
            }
        }

        return $map;
    }

    /**
     * خروجی کامل JSON (برای پشتیبان‌گیری)
     */
    public function exportAllJson($data) {
        $entity_id = $_SESSION['current_entity_id'];
        $selectedTypes = $data['types'] ?? array_keys($this->dataTypes);

        $allData = [];
        foreach ($selectedTypes as $type) {
            if (!isset($this->dataTypes[$type])) continue;
            $config = $this->dataTypes[$type];
            $table = $config['table'];
            $cols = $config['columns'];
            $colStr = implode(', ', array_map(fn($c) => "{$table}.{$c}", $cols));

            if (isset($config['entity_via'])) {
                $join = $config['entity_join'];
                $parentTable = $config['entity_via'];
                $sql = "SELECT {$colStr} FROM {$table} {$join} WHERE {$parentTable}.entity_id = ? ORDER BY {$table}.id";
            } else {
                $sql = "SELECT {$colStr} FROM {$table} WHERE {$table}.entity_id = ? ORDER BY {$table}.id";
            }

            $stmt = $this->conn->prepare($sql);
            if (!$stmt) continue;
            $stmt->bind_param("i", $entity_id);
            $stmt->execute();
            $rows = db_stmt_to_assoc_array($stmt);
            $allData[$type] = ['label' => $config['label'], 'count' => count($rows), 'data' => $rows];
        }

        log_activity($this->conn, 'EXPORT_JSON', "خروجی کامل JSON: " . count($selectedTypes) . " نوع داده.");
        send_json(['success' => true, 'export' => $allData]);
    }
}
