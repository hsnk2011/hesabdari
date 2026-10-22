<?php
// /api/models/Supplier.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/traits/PersonBalanceTrait.php';

class Supplier extends BaseModel {
    use PersonBalanceTrait; // Use the refactored trait

    protected $tableName = 'suppliers';
    protected $allowedFilters = ['name', 'phone', 'economicCode', 'job'];
    protected $allowedSorts = ['id', 'name', 'phone', 'economicCode', 'job'];

    public function getPaginated($input) {
        $this->ensurePersonJobColumn();
        $balanceFilter = trim($input['balanceFilter'] ?? '');

        if ($balanceFilter !== '') {
            // Scan every matching person: the balance is computed in PHP, so a page cap here would drop people.
            $withBalance = $this->attachBalanceDataToPersons($this->getAllMatching($input), 'supplier');
            $filtered = $this->filterPersonsByBalance($withBalance, $balanceFilter);
            $page = $this->paginatePersonList($filtered, $input);
            $page['data'] = $this->attachLinkedPersonData($page['data'], 'supplier');
            return $page;
        }

        $paginatedResult = parent::getPaginated($input);
        
        if (empty($paginatedResult['data'])) {
            return $paginatedResult;
        }

        // Use the trait method to attach balance data
        $suppliersWithBalance = $this->attachBalanceDataToPersons($paginatedResult['data'], 'supplier');

        $paginatedResult['data'] = $this->attachLinkedPersonData($suppliersWithBalance, 'supplier');
        return $paginatedResult;
    }

    public function save($data) {
        // Validation
        if (empty(trim($data['name'] ?? ''))) {
            return ['error' => 'نام تامین‌کننده الزامی است.', 'statusCode' => 400];
        }
        // Relaxed validation: economicCode is optional
        $economicCode = !empty(trim($data['economicCode'] ?? '')) ? trim($data['economicCode']) : '';
        
        $id = $data['id'] ?? null;
        $name = trim($data['name']);
        $address = $data['address'] ?? '';
        $phone = $data['phone'] ?? '';
        $job = trim((string)($data['job'] ?? ''));
        $initial_balance = parse_money($data['initial_balance'] ?? 0);
        $entity_id = $_SESSION['current_entity_id'];

        $lockError = $this->initialBalanceChangeError($data['id'] ?? 0, $initial_balance);
        if ($lockError !== null) {
            return ['error' => $lockError, 'statusCode' => 400];
        }

        // Allow multiple phones joined with " / " (legacy DBs may still be varchar(20))
        $this->ensurePhoneColumnWidth();
        $this->ensurePersonJobColumn();

        // Check for duplicate name
        $stmt_name = $this->conn->prepare("SELECT id FROM `{$this->tableName}` WHERE name = ? AND entity_id = ? AND id != ?");
        $check_id = $id ?: 0;
        $stmt_name->bind_param("sii", $name, $entity_id, $check_id);
        $stmt_name->execute();
        if ($stmt_name->fetch()) {
            $stmt_name->close();
            return ['error' => 'تامین‌کننده با این نام قبلاً ثبت شده است.', 'statusCode' => 409];
        }
        $stmt_name->close();

        // Check for duplicate economicCode if provided
        if ($economicCode) {
            $stmt_code = $this->conn->prepare("SELECT id FROM `{$this->tableName}` WHERE economicCode = ? AND entity_id = ? AND id != ?");
            $stmt_code->bind_param("sii", $economicCode, $entity_id, $check_id);
            $stmt_code->execute();
            if ($stmt_code->fetch()) {
                $stmt_code->close();
                return ['error' => 'تامین‌کننده با این کد اقتصادی قبلاً ثبت شده است.', 'statusCode' => 409];
            }
            $stmt_code->close();
        }

        $jobValue = $job;
        if ($id) {
            $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET name = ?, address = ?, phone = ?, job = ?, economicCode = ?, initial_balance = ? WHERE id = ? AND entity_id = ?");
            $stmt->bind_param("sssssdii", $name, $address, $phone, $jobValue, $economicCode, $initial_balance, $id, $entity_id);
        } else {
            $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (entity_id, name, address, phone, job, economicCode, initial_balance) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssssd", $entity_id, $name, $address, $phone, $jobValue, $economicCode, $initial_balance);
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
            return ['error' => 'خطا در ذخیره تامین‌کننده: ' . $e->getMessage(), 'statusCode' => 500];
        }
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
            // ignore if no permission / already correct
        }
    }

    public function delete($id) {
        $id = (int)$id;
        $blockers = $this->personDeleteBlockers('supplier', $id);
        if (!empty($blockers)) {
            return [
                'error' => 'این تأمین‌کننده قابل حذف نیست زیرا در اسناد زیر استفاده شده است: ' . implode('، ', $blockers) . '.',
                'statusCode' => 409,
            ];
        }
        $result = parent::delete($id);
        if (!empty($result['success'])) {
            // Drop the customer twin's link (the customer itself stays).
            $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
            $st = $this->conn->prepare("UPDATE customers SET linked_supplier_id = NULL WHERE linked_supplier_id = ? AND entity_id = ?");
            $st->bind_param('ii', $id, $entityId);
            $st->execute();
        }
        return $result;
    }
}
