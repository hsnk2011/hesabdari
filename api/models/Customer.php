<?php
// /api/models/Customer.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/traits/PersonBalanceTrait.php';

class Customer extends BaseModel {
    use PersonBalanceTrait; // Use the refactored trait

    protected $tableName = 'customers';
    protected $allowedFilters = ['name', 'phone', 'nationalId', 'address', 'job'];
    protected $allowedSorts = ['id', 'name', 'phone', 'nationalId', 'job'];

    public function getPaginated($input) {
        $this->ensurePersonJobColumn();
        $balanceFilter = trim($input['balanceFilter'] ?? '');

        if ($balanceFilter !== '') {
            // Scan every matching person: the balance is computed in PHP, so a page cap here would drop people.
            $withBalance = $this->attachBalanceDataToPersons($this->getAllMatching($input), 'customer');
            $filtered = $this->filterPersonsByBalance($withBalance, $balanceFilter);
            $page = $this->paginatePersonList($filtered, $input);
            $page['data'] = $this->attachLinkedPersonData($page['data'], 'customer');
            return $page;
        }

        $paginatedResult = parent::getPaginated($input);
        
        if (empty($paginatedResult['data'])) {
            return $paginatedResult;
        }
        
        // Use the trait method to attach balance data
        $customersWithBalance = $this->attachBalanceDataToPersons($paginatedResult['data'], 'customer');
        
        $paginatedResult['data'] = $this->attachLinkedPersonData($customersWithBalance, 'customer');
        return $paginatedResult;
    }

    public function save($data) {
        // Validation
        if (empty(trim($data['name'] ?? ''))) {
            return ['error' => 'نام مشتری الزامی است.', 'statusCode' => 400];
        }
        // Relaxed validation: nationalId is optional but if provided must be valid
        $nationalId = !empty(trim($data['nationalId'] ?? '')) ? trim($data['nationalId']) : '';
        
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

        // Check for duplicate name in the same entity
        $stmt_name = $this->conn->prepare("SELECT id FROM `{$this->tableName}` WHERE name = ? AND entity_id = ? AND id != ?");
        $check_id = $id ?: 0;
        $stmt_name->bind_param("sii", $name, $entity_id, $check_id);
        $stmt_name->execute();
        if ($stmt_name->fetch()) {
            $stmt_name->close();
            return ['error' => 'مشتری با این نام قبلاً ثبت شده است.', 'statusCode' => 409];
        }
        $stmt_name->close();

        // Check for duplicate nationalId if provided
        if ($nationalId) {
            $stmt_nid = $this->conn->prepare("SELECT id FROM `{$this->tableName}` WHERE nationalId = ? AND entity_id = ? AND id != ?");
            $stmt_nid->bind_param("sii", $nationalId, $entity_id, $check_id);
            $stmt_nid->execute();
            if ($stmt_nid->fetch()) {
                $stmt_nid->close();
                return ['error' => 'مشتری با این کد ملی/شناسایی قبلاً ثبت شده است.', 'statusCode' => 409];
            }
            $stmt_nid->close();
        }

        $jobValue = $job;
        // Credit limit: empty = no limit. Group: optional price/discount group.
        $creditRaw = trim((string)($data['credit_limit'] ?? ''));
        $creditLimit = $creditRaw === '' ? null : max(0, parse_money($creditRaw));
        $groupId = !empty($data['group_id']) ? (int)$data['group_id'] : null;
        if ($groupId) {
            $g = $this->conn->prepare("SELECT id FROM customer_groups WHERE id = ? AND entity_id = ?");
            $g->bind_param('ii', $groupId, $entity_id);
            $g->execute();
            if (empty(db_stmt_to_assoc_array($g))) {
                return ['error' => 'گروه مشتری نامعتبر است.', 'statusCode' => 400];
            }
        }
        if ($id) {
            $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET name = ?, address = ?, phone = ?, job = ?, nationalId = ?, initial_balance = ?, credit_limit = ?, group_id = ? WHERE id = ? AND entity_id = ?");
            $stmt->bind_param("sssssddiii", $name, $address, $phone, $jobValue, $nationalId, $initial_balance, $creditLimit, $groupId, $id, $entity_id);
        } else {
            $stmt = $this->conn->prepare("INSERT INTO `{$this->tableName}` (entity_id, name, address, phone, job, nationalId, initial_balance, credit_limit, group_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssssddi", $entity_id, $name, $address, $phone, $jobValue, $nationalId, $initial_balance, $creditLimit, $groupId);
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
            // Fallback for other DB errors
            return ['error' => 'خطا در ذخیره مشتری: ' . $e->getMessage(), 'statusCode' => 500];
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
        $blockers = $this->personDeleteBlockers('customer', $id);
        if (!empty($blockers)) {
            return [
                'error' => 'این مشتری قابل حذف نیست زیرا در اسناد زیر استفاده شده است: ' . implode('، ', $blockers) . '.',
                'statusCode' => 409,
            ];
        }
        return parent::delete($id);
    }

    /**
     * Current consolidated balance of a customer (positive = they owe us),
     * same formula as the customer list and statements. Includes uncommitted
     * changes of the current connection, so it can be checked inside a save.
     */
    /** Every customer of the current entity with balance data (no paging cap; CRM debtors). */
    public function allWithBalance(): array {
        $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
        $stmt = $this->conn->prepare("SELECT * FROM customers WHERE entity_id = ? ORDER BY id");
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $rows = db_stmt_to_assoc_array($stmt);
        return $rows ? $this->attachBalanceDataToPersons($rows, 'customer') : [];
    }

    public function getNetBalance($customerId) {
        $customerId = (int)$customerId;
        $entityId = (int)($_SESSION['current_entity_id'] ?? 0);
        $stmt = $this->conn->prepare("SELECT * FROM customers WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $customerId, $entityId);
        $stmt->execute();
        $row = db_stmt_to_assoc_array($stmt)[0] ?? null;
        if (!$row) {
            return null;
        }
        // Every document counts toward credit, including future-dated ones.
        $withBalance = $this->attachBalanceDataToPersons([$row], 'customer', '9999-12-31');
        return [
            'net_balance' => (float)($withBalance[0]['net_balance'] ?? 0),
            'credit_limit' => $row['credit_limit'] !== null ? (float)$row['credit_limit'] : null,
            'name' => $row['name'],
        ];
    }
}
