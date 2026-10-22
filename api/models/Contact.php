<?php
// /api/models/Contact.php — سایر اشخاص (طرف‌حساب بدون فاکتور)
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/traits/PersonBalanceTrait.php';

class Contact extends BaseModel {
    use PersonBalanceTrait;

    protected $tableName = 'contacts';
    protected $allowedFilters = ['name', 'phone', 'nationalId', 'notes', 'job'];
    protected $allowedSorts = ['id', 'name', 'phone', 'nationalId', 'job'];

    public function getPaginated($input) {
        $this->ensurePersonJobColumn();
        $balanceFilter = trim($input['balanceFilter'] ?? '');

        if ($balanceFilter !== '') {
            // Scan every matching person: the balance is computed in PHP, so a page cap here would drop people.
            $withBalance = $this->attachContactBalanceData($this->getAllMatching($input));
            $filtered = $this->filterPersonsByBalance($withBalance, $balanceFilter);
            return $this->paginatePersonList($filtered, $input);
        }

        $paginatedResult = parent::getPaginated($input);
        if (empty($paginatedResult['data'])) {
            return $paginatedResult;
        }
        $paginatedResult['data'] = $this->attachContactBalanceData($paginatedResult['data']);
        return $paginatedResult;
    }

    /**
     * Balance without invoices: initial − payments_out + payments_in − linked expenses + linked incomes.
     * Positive = بستانکار (we owe them), like suppliers.
     */
    protected function attachContactBalanceData(array $persons): array {
        if (empty($persons)) {
            return [];
        }
        $entityId = $_SESSION['current_entity_id'] ?? null;
        if (!$entityId) {
            return $persons;
        }

        $personIds = array_column($persons, 'id');
        $ids_placeholder = implode(',', array_fill(0, count($personIds), '?'));
        $types = str_repeat('i', count($personIds));
        $endDate = date('Y-m-d');
        $personType = 'contact';

        $paymentsOut = [];
        $paymentsIn = [];
        $paySql = "SELECT person_id, transaction_type, SUM(amount) as total
                   FROM payments
                   WHERE entity_id = ? AND person_type = ? AND person_id IN ({$ids_placeholder})
                     AND invoiceId IS NULL AND date <= ?
                   GROUP BY person_id, transaction_type";
        $payStmt = $this->conn->prepare($paySql);
        $payStmt->bind_param('is' . $types . 's', $entityId, $personType, ...array_merge($personIds, [$endDate]));
        $payStmt->execute();
        foreach (db_stmt_to_assoc_array($payStmt) as $row) {
            if ($row['transaction_type'] === 'payment') {
                $paymentsOut[$row['person_id']] = floatval($row['total']);
            } else {
                $paymentsIn[$row['person_id']] = floatval($row['total']);
            }
        }

        $expenses = [];
        $expSql = "SELECT person_id, SUM(amount) as total
                   FROM expenses
                   WHERE entity_id = ? AND person_type = ? AND person_id IN ({$ids_placeholder})
                     AND date <= ?
                   GROUP BY person_id";
        $expStmt = $this->conn->prepare($expSql);
        if ($expStmt) {
            $expStmt->bind_param('is' . $types . 's', $entityId, $personType, ...array_merge($personIds, [$endDate]));
            $expStmt->execute();
            foreach (db_stmt_to_assoc_array($expStmt) as $row) {
                $expenses[$row['person_id']] = floatval($row['total']);
            }
        }

        $incomes = [];
        $incSql = "SELECT person_id, SUM(amount) as total
                   FROM incomes
                   WHERE entity_id = ? AND person_type = ? AND person_id IN ({$ids_placeholder})
                     AND date <= ?
                   GROUP BY person_id";
        $incStmt = $this->conn->prepare($incSql);
        if ($incStmt) {
            $incStmt->bind_param('is' . $types . 's', $entityId, $personType, ...array_merge($personIds, [$endDate]));
            $incStmt->execute();
            foreach (db_stmt_to_assoc_array($incStmt) as $row) {
                $incomes[$row['person_id']] = floatval($row['total']);
            }
        }

        require_once __DIR__ . '/Asset.php';
        $assetNet = Asset::personNet($this->conn, (int)$entityId, 'contact', $personIds, $endDate);

        foreach ($persons as &$person) {
            $id = $person['id'];
            $initial = floatval($person['initial_balance'] ?? 0);
            $out = $paymentsOut[$id] ?? 0;
            $in = $paymentsIn[$id] ?? 0;
            $exp = $expenses[$id] ?? 0;
            $inc = $incomes[$id] ?? 0;
            $person['total_unsettled'] = 0;
            $person['available_credit'] = max(0, $out + $exp - $in - $inc);
            $person['net_balance'] = $initial - $out + $in - $exp + $inc - floatval($assetNet[(int)$id] ?? 0);
        }
        unset($person);

        return $persons;
    }

    public function save($data) {
        if (empty(trim($data['name'] ?? ''))) {
            return ['error' => 'نام شخص الزامی است.', 'statusCode' => 400];
        }

        $id = $data['id'] ?? null;
        $name = trim($data['name']);
        $notes = $data['notes'] ?? ($data['address'] ?? '');
        $phone = $data['phone'] ?? '';
        $job = trim((string)($data['job'] ?? ''));
        $nationalId = !empty(trim($data['nationalId'] ?? '')) ? trim($data['nationalId']) : '';
        $initial_balance = floatval(str_replace(',', '', $data['initial_balance'] ?? 0));
        $entity_id = $_SESSION['current_entity_id'];

        $this->ensurePhoneColumnWidth();
        $this->ensurePersonJobColumn();

        $stmt_name = $this->conn->prepare("SELECT id FROM `{$this->tableName}` WHERE name = ? AND entity_id = ? AND id != ?");
        $check_id = $id ?: 0;
        $stmt_name->bind_param('sii', $name, $entity_id, $check_id);
        $stmt_name->execute();
        if ($stmt_name->fetch()) {
            $stmt_name->close();
            return ['error' => 'شخصی با این نام قبلاً ثبت شده است.', 'statusCode' => 409];
        }
        $stmt_name->close();

        if ($nationalId) {
            $stmt_nid = $this->conn->prepare("SELECT id FROM `{$this->tableName}` WHERE nationalId = ? AND entity_id = ? AND id != ?");
            $stmt_nid->bind_param('sii', $nationalId, $entity_id, $check_id);
            $stmt_nid->execute();
            if ($stmt_nid->fetch()) {
                $stmt_nid->close();
                return ['error' => 'شخصی با این کد ملی/شناسایی قبلاً ثبت شده است.', 'statusCode' => 409];
            }
            $stmt_nid->close();
        }

        $jobValue = $job;
        if ($id) {
            $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET name = ?, notes = ?, phone = ?, job = ?, nationalId = ?, initial_balance = ? WHERE id = ? AND entity_id = ?");
            $stmt->bind_param('sssssdii', $name, $notes, $phone, $jobValue, $nationalId, $initial_balance, $id, $entity_id);
        } else {
            $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (entity_id, name, notes, phone, job, nationalId, initial_balance) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssssd', $entity_id, $name, $notes, $phone, $jobValue, $nationalId, $initial_balance);
        }

        try {
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            $savedId = $id ?? $this->conn->insert_id;
            $stmt->close();
            return ['success' => true, 'id' => $savedId];
        } catch (Exception $e) {
            $stmt->close();
            return ['error' => 'خطا در ذخیره شخص: ' . $e->getMessage(), 'statusCode' => 500];
        }
    }

    public function delete($id) {
        $id = intval($id);
        $entity_id = $_SESSION['current_entity_id'] ?? 0;
        if ($id <= 0 || !$entity_id) {
            return ['error' => 'شناسه نامعتبر است.', 'statusCode' => 400];
        }

        $chkPay = $this->conn->prepare("SELECT id FROM payments WHERE entity_id = ? AND person_type = 'contact' AND person_id = ? LIMIT 1");
        $chkPay->bind_param('ii', $entity_id, $id);
        $chkPay->execute();
        if (!empty(db_stmt_to_assoc_array($chkPay))) {
            return ['error' => 'این شخص قابل حذف نیست زیرا در پرداخت‌ها استفاده شده است.', 'statusCode' => 409];
        }

        $chkExp = $this->conn->prepare("SELECT id FROM expenses WHERE entity_id = ? AND person_type = 'contact' AND person_id = ? LIMIT 1");
        $chkExp->bind_param('ii', $entity_id, $id);
        $chkExp->execute();
        if (!empty(db_stmt_to_assoc_array($chkExp))) {
            return ['error' => 'این شخص قابل حذف نیست زیرا در هزینه‌ها استفاده شده است.', 'statusCode' => 409];
        }

        $chkInc = $this->conn->prepare("SELECT id FROM incomes WHERE entity_id = ? AND person_type = 'contact' AND person_id = ? LIMIT 1");
        if ($chkInc) {
            $chkInc->bind_param('ii', $entity_id, $id);
            $chkInc->execute();
            if (!empty(db_stmt_to_assoc_array($chkInc))) {
                return ['error' => 'این شخص قابل حذف نیست زیرا در درآمدها استفاده شده است.', 'statusCode' => 409];
            }
        }

        return parent::delete($id);
    }

    private function ensurePhoneColumnWidth() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $this->conn->query(
                "ALTER TABLE `{$this->tableName}` MODIFY COLUMN `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL"
            );
        } catch (Exception $e) {
            // ignore
        }
    }
}
