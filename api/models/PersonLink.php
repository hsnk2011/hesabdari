<?php
// /api/models/PersonLink.php

/**
 * A person we both sell to and buy from lives as two records, a customer and a
 * supplier, because every document references (person_type, person_id).
 * customers.linked_supplier_id ties such a pair together: the shared profile
 * (name, address, phones, job) is kept identical on both sides, and lists and
 * statements can show the combined net balance.
 *
 * Merging two persons:
 *  - customer + supplier: links them as one person (nothing is moved).
 *  - same type (duplicates): every document of the source is moved onto the
 *    target, opening balances are added up and the source is deleted.
 */
class PersonLink {
    private $conn;
    private $entityId;

    private const SHARED_FIELDS = ['name', 'address', 'phone', 'job'];

    public function __construct($db) {
        $this->conn = $db;
        $this->entityId = (int)($_SESSION['current_entity_id'] ?? 0);
    }

    public static function isPersonType($type): bool {
        return $type === 'customer' || $type === 'supplier';
    }

    public static function otherType(string $type): string {
        return $type === 'customer' ? 'supplier' : 'customer';
    }

    public static function label(string $type): string {
        return $type === 'customer' ? 'مشتری' : 'تأمین‌کننده';
    }

    public static function canManage(string $type): bool {
        $perm = $type === 'customer' ? 'manage_customers' : 'manage_suppliers';
        return in_array($perm, $_SESSION['permissions'] ?? [], true);
    }

    private static function table(string $type): string {
        return $type === 'customer' ? 'customers' : 'suppliers';
    }

    private function fetchAll(string $sql, string $types, array $params): array {
        $st = $this->conn->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        return db_stmt_to_assoc_array($st);
    }

    private function exec(string $sql, string $types, array $params): int {
        $st = $this->conn->prepare($sql);
        $st->bind_param($types, ...$params);
        if (!$st->execute()) {
            throw new Exception($st->error);
        }
        $n = $st->affected_rows;
        $st->close();
        return $n;
    }

    public function getPerson(string $type, int $id): ?array {
        if (!self::isPersonType($type) || $id <= 0) {
            return null;
        }
        $table = self::table($type);
        return $this->fetchAll("SELECT * FROM `{$table}` WHERE id = ? AND entity_id = ?", 'ii', [$id, $this->entityId])[0] ?? null;
    }

    /** Id of the linked record on the other side, or 0. */
    public function twinId(string $type, int $id): int {
        if ($id <= 0) {
            return 0;
        }
        if ($type === 'customer') {
            $row = $this->fetchAll("SELECT linked_supplier_id FROM customers WHERE id = ? AND entity_id = ?", 'ii', [$id, $this->entityId])[0] ?? null;
            return (int)($row['linked_supplier_id'] ?? 0);
        }
        $row = $this->fetchAll("SELECT id FROM customers WHERE linked_supplier_id = ? AND entity_id = ? LIMIT 1", 'ii', [$id, $this->entityId])[0] ?? null;
        return (int)($row['id'] ?? 0);
    }

    /** [personId => twin row] for a page of persons of $type. */
    public function twinRows(string $type, array $ids): array {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $types = 'i' . str_repeat('i', count($ids));
        if ($type === 'customer') {
            $rows = $this->fetchAll(
                "SELECT c.id AS owner_id, s.* FROM customers c
                 JOIN suppliers s ON s.id = c.linked_supplier_id AND s.entity_id = c.entity_id
                 WHERE c.entity_id = ? AND c.id IN ({$in})",
                $types, array_merge([$this->entityId], $ids)
            );
        } else {
            $rows = $this->fetchAll(
                "SELECT c.linked_supplier_id AS owner_id, c.* FROM customers c
                 WHERE c.entity_id = ? AND c.linked_supplier_id IN ({$in})",
                $types, array_merge([$this->entityId], $ids)
            );
        }
        $map = [];
        foreach ($rows as $r) {
            $owner = (int)$r['owner_id'];
            unset($r['owner_id']);
            $map[$owner] = $r;
        }
        return $map;
    }

    private function nameTaken(string $type, string $name, int $exceptId): bool {
        $table = self::table($type);
        return !empty($this->fetchAll(
            "SELECT id FROM `{$table}` WHERE name = ? AND entity_id = ? AND id != ? LIMIT 1",
            'sii', [$name, $this->entityId, $exceptId]
        ));
    }

    /**
     * Validation before a customer/supplier save, so the pair is never left
     * half-written. $wantBoth: null = keep the link as is, true = must be both,
     * false = drop the link.
     */
    public function precheckSave(string $type, int $id, string $name, ?bool $wantBoth): ?string {
        $other = self::otherType($type);
        $twin = $this->twinId($type, $id);
        if ($twin && $wantBoth !== false) {
            if ($this->nameTaken($other, $name, $twin)) {
                return 'نام «' . $name . '» در لیست ' . self::label($other) . '‌ها برای شخص دیگری ثبت شده است.';
            }
            return null;
        }
        if (!$twin && $wantBoth === true) {
            $table = self::table($other);
            $same = $this->fetchAll("SELECT id FROM `{$table}` WHERE name = ? AND entity_id = ? LIMIT 1", 'si', [$name, $this->entityId])[0] ?? null;
            if ($same && $this->twinId($other, (int)$same['id'])) {
                return 'یک ' . self::label($other) . ' با نام «' . $name . '» وجود دارد که به شخص دیگری متصل است.';
            }
        }
        return null;
    }

    /** is_both from a save request: null when the form did not send it. */
    public static function wantBothFromRequest(array $data): ?bool {
        if (!array_key_exists('is_both', $data) || $data['is_both'] === null || $data['is_both'] === '') {
            return null;
        }
        return filter_var($data['is_both'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Saves a customer/supplier through its model and applies the
     * "both customer and supplier" option of the form.
     */
    public function saveWithLink($model, string $type, array $data): array {
        $wantBoth = self::wantBothFromRequest($data);
        $twin = $this->twinId($type, (int)($data['id'] ?? 0));
        if ((($wantBoth === true && !$twin) || ($wantBoth === false && $twin)) && !self::canManage(self::otherType($type))) {
            return ['error' => 'برای تغییر حالت «هم مشتری و هم تأمین‌کننده» به دسترسی مدیریت ' . self::label(self::otherType($type)) . '‌ها نیاز دارید.', 'statusCode' => 403];
        }
        $error = $this->precheckSave($type, (int)($data['id'] ?? 0), trim((string)($data['name'] ?? '')), $wantBoth);
        if ($error !== null) {
            return ['error' => $error, 'statusCode' => 409];
        }
        $result = $model->save($data);
        if (empty($result['success'])) {
            return $result;
        }
        $this->conn->begin_transaction();
        try {
            $link = $this->afterSave($type, (int)$result['id'], $wantBoth);
            if (!empty($link['error'])) {
                throw new Exception($link['error']);
            }
            $this->conn->commit();
            $result['linked_id'] = (int)($link['twin_id'] ?? 0);
        } catch (Throwable $e) {
            $this->conn->rollback();
            $result['warning'] = 'اطلاعات ذخیره شد، اما اتصال به ' . self::label(self::otherType($type)) . ' انجام نشد: ' . $e->getMessage();
        }
        return $result;
    }

    /**
     * Applies the link side of a customer/supplier save (after the main save).
     * Returns ['twin_id' => int] or ['error' => string].
     */
    public function afterSave(string $type, int $id, ?bool $wantBoth): array {
        $twin = $this->twinId($type, $id);
        if ($twin && $wantBoth === false) {
            $this->unlink($type, $id);
            return ['twin_id' => 0];
        }
        if ($twin) {
            $this->copyShared($type, $id, self::otherType($type), $twin);
            return ['twin_id' => $twin];
        }
        if ($wantBoth === true) {
            return $this->createOrAdoptTwin($type, $id);
        }
        return ['twin_id' => 0];
    }

    private function createOrAdoptTwin(string $type, int $id): array {
        $person = $this->getPerson($type, $id);
        if (!$person) {
            return ['error' => 'شخص یافت نشد.'];
        }
        $other = self::otherType($type);
        $table = self::table($other);
        $same = $this->fetchAll("SELECT id FROM `{$table}` WHERE name = ? AND entity_id = ? LIMIT 1", 'si', [$person['name'], $this->entityId])[0] ?? null;
        if ($same) {
            $twin = (int)$same['id'];
            if ($this->twinId($other, $twin)) {
                return ['error' => 'یک ' . self::label($other) . ' با همین نام به شخص دیگری متصل است.'];
            }
            // The saved person's profile wins; the existing record's phones are kept too.
            $this->linkPair($other, $twin, $type, $id);
            return ['twin_id' => $twin, 'adopted' => true];
        }
        // Opening balance stays on the side where it was entered.
        $zero = 0.0;
        $empty = '';
        if ($other === 'supplier') {
            $this->exec(
                "INSERT INTO suppliers (entity_id, name, address, phone, job, economicCode, initial_balance) VALUES (?, ?, ?, ?, ?, ?, ?)",
                'isssssd', [$this->entityId, $person['name'], $person['address'] ?? '', $person['phone'] ?? '', $person['job'] ?? '', $empty, $zero]
            );
        } else {
            $this->exec(
                "INSERT INTO customers (entity_id, name, address, phone, job, nationalId, initial_balance) VALUES (?, ?, ?, ?, ?, ?, ?)",
                'isssssd', [$this->entityId, $person['name'], $person['address'] ?? '', $person['phone'] ?? '', $person['job'] ?? '', $empty, $zero]
            );
        }
        $twin = (int)$this->conn->insert_id;
        $this->setLink($type, $id, $twin);
        return ['twin_id' => $twin, 'created' => true];
    }

    private function setLink(string $type, int $id, int $twinId): void {
        $customerId = $type === 'customer' ? $id : $twinId;
        $supplierId = $type === 'customer' ? $twinId : $id;
        $this->exec("UPDATE customers SET linked_supplier_id = ? WHERE id = ? AND entity_id = ?", 'iii', [$supplierId, $customerId, $this->entityId]);
    }

    public function unlink(string $type, int $id): bool {
        if ($type === 'customer') {
            $n = $this->exec("UPDATE customers SET linked_supplier_id = NULL WHERE id = ? AND entity_id = ?", 'ii', [$id, $this->entityId]);
        } else {
            $n = $this->exec("UPDATE customers SET linked_supplier_id = NULL WHERE linked_supplier_id = ? AND entity_id = ?", 'ii', [$id, $this->entityId]);
        }
        return $n > 0;
    }

    private function copyShared(string $fromType, int $fromId, string $toType, int $toId): void {
        $from = $this->getPerson($fromType, $fromId);
        if (!$from) {
            return;
        }
        $table = self::table($toType);
        $this->exec(
            "UPDATE `{$table}` SET name = ?, address = ?, phone = ?, job = ? WHERE id = ? AND entity_id = ?",
            'ssssii', [$from['name'], $from['address'] ?? '', $from['phone'] ?? '', $from['job'] ?? '', $toId, $this->entityId]
        );
    }

    private static function mergePhones($a, $b): string {
        $out = [];
        foreach ([(string)$a, (string)$b] as $s) {
            foreach (preg_split('#\s*/\s*#', $s) as $p) {
                $p = trim($p);
                if ($p !== '' && !in_array($p, $out, true)) {
                    $out[] = $p;
                }
            }
        }
        return implode(' / ', $out);
    }

    /** Documents of a person that a same-type merge moves, as [label => count]. */
    private function documentCounts(string $type, int $id): array {
        $checks = [];
        if ($type === 'customer') {
            $checks['فاکتور فروش'] = "SELECT COUNT(*) c FROM sales_invoices WHERE entity_id = ? AND customerId = ?";
            $checks['پیش‌فاکتور'] = "SELECT COUNT(*) c FROM quotations WHERE entity_id = ? AND customerId = ?";
        } else {
            $checks['فاکتور خرید'] = "SELECT COUNT(*) c FROM purchase_invoices WHERE entity_id = ? AND supplierId = ?";
        }
        $t = $this->conn->real_escape_string($type);
        foreach ([
            'تراکنش/پرداخت' => 'payments',
            'فاکتور مرجوعی' => 'return_invoices',
            'هزینه' => 'expenses',
            'درآمد' => 'incomes',
            'هزینه تکراری' => 'recurring_expenses',
            'سند دارایی' => 'asset_documents',
        ] as $label => $table) {
            $checks[$label] = "SELECT COUNT(*) c FROM `{$table}` WHERE entity_id = ? AND person_type = '{$t}' AND person_id = ?";
        }
        $counts = [];
        foreach ($checks as $label => $sql) {
            try {
                $n = (int)($this->fetchAll($sql, 'ii', [$this->entityId, $id])[0]['c'] ?? 0);
            } catch (Throwable $e) {
                $n = 0; // optional table missing on an old schema
            }
            if ($n > 0) {
                $counts[$label] = $n;
            }
        }
        return $counts;
    }

    /**
     * What merging $source into $target would do (for the confirmation dialog).
     * Returns ['error' => ...] when the merge is not allowed.
     */
    public function mergePlan(string $sourceType, int $sourceId, string $targetType, int $targetId): array {
        if (!self::isPersonType($sourceType) || !self::isPersonType($targetType)) {
            return ['error' => 'فقط مشتری و تأمین‌کننده قابل ادغام هستند.'];
        }
        if ($sourceType === $targetType && $sourceId === $targetId) {
            return ['error' => 'یک شخص را نمی‌توان با خودش ادغام کرد.'];
        }
        $source = $this->getPerson($sourceType, $sourceId);
        $target = $this->getPerson($targetType, $targetId);
        if (!$source || !$target) {
            return ['error' => 'شخص یافت نشد.'];
        }
        $plan = [
            'source' => ['type' => $sourceType, 'id' => $sourceId, 'name' => $source['name']],
            'target' => ['type' => $targetType, 'id' => $targetId, 'name' => $target['name']],
        ];

        if ($sourceType !== $targetType) {
            $sourceTwin = $this->twinId($sourceType, $sourceId);
            $targetTwin = $this->twinId($targetType, $targetId);
            if ($sourceTwin === $targetId) {
                return ['error' => 'این دو شخص از قبل به هم متصل هستند.'];
            }
            if ($sourceTwin) {
                return ['error' => '«' . $source['name'] . '» از قبل به ' . self::label($targetType) . ' دیگری متصل است.'];
            }
            if ($targetTwin) {
                return ['error' => '«' . $target['name'] . '» از قبل به ' . self::label($sourceType) . ' دیگری متصل است.'];
            }
            if ($this->nameTaken($sourceType, $target['name'], $sourceId)) {
                return ['error' => 'نام «' . $target['name'] . '» در لیست ' . self::label($sourceType) . '‌ها برای شخص دیگری ثبت شده است.'];
            }
            $plan['mode'] = 'link';
            return $plan;
        }

        $sourceTwin = $this->twinId($sourceType, $sourceId);
        $targetTwin = $this->twinId($targetType, $targetId);
        if ($sourceTwin && $targetTwin && $sourceTwin !== $targetTwin) {
            $other = self::label(self::otherType($sourceType));
            return ['error' => "هر دو شخص به {$other}‌های جداگانه متصل هستند؛ ابتدا آن دو {$other} را با هم ادغام کنید."];
        }
        $plan['mode'] = 'merge';
        $plan['documents'] = $this->documentCounts($sourceType, $sourceId);
        $plan['initial_balance'] = (float)$source['initial_balance'] + (float)$target['initial_balance'];
        $plan['moves_link'] = ($sourceTwin && !$targetTwin);
        return $plan;
    }

    /**
     * Merges $source into $target (see mergePlan). The target survives.
     * Returns the plan with 'success' => true, or ['error' => ...].
     */
    public function merge(string $sourceType, int $sourceId, string $targetType, int $targetId, bool $manageTransaction = true): array {
        $plan = $this->mergePlan($sourceType, $sourceId, $targetType, $targetId);
        if (!empty($plan['error'])) {
            return $plan;
        }
        if ($manageTransaction) {
            $this->conn->begin_transaction();
        }
        try {
            if ($plan['mode'] === 'link') {
                $this->linkPair($sourceType, $sourceId, $targetType, $targetId);
            } else {
                $this->mergeSameType($sourceType, $sourceId, $targetId);
            }
            if ($manageTransaction) {
                $this->conn->commit();
            }
        } catch (Throwable $e) {
            if ($manageTransaction) {
                $this->conn->rollback();
            }
            return ['error' => 'خطا در ادغام اشخاص: ' . $e->getMessage()];
        }
        $plan['success'] = true;
        return $plan;
    }

    /** Link a customer with a supplier; the target's profile wins, phones are combined. */
    private function linkPair(string $sourceType, int $sourceId, string $targetType, int $targetId): void {
        $source = $this->getPerson($sourceType, $sourceId);
        $target = $this->getPerson($targetType, $targetId);
        $phones = self::mergePhones($target['phone'] ?? '', $source['phone'] ?? '');
        $address = trim((string)($target['address'] ?? '')) !== '' ? $target['address'] : ($source['address'] ?? '');
        $job = trim((string)($target['job'] ?? '')) !== '' ? $target['job'] : ($source['job'] ?? '');
        $targetTable = self::table($targetType);
        $this->exec(
            "UPDATE `{$targetTable}` SET phone = ?, address = ?, job = ? WHERE id = ? AND entity_id = ?",
            'sssii', [$phones, $address, $job, $targetId, $this->entityId]
        );
        $this->setLink($targetType, $targetId, $sourceId);
        $this->copyShared($targetType, $targetId, $sourceType, $sourceId);
    }

    private function mergeSameType(string $type, int $sourceId, int $targetId): void {
        $e = $this->entityId;
        $source = $this->getPerson($type, $sourceId);
        $target = $this->getPerson($type, $targetId);

        if ($type === 'customer') {
            $this->exec("UPDATE sales_invoices SET customerId = ? WHERE entity_id = ? AND customerId = ?", 'iii', [$targetId, $e, $sourceId]);
            $this->exec("UPDATE quotations SET customerId = ? WHERE entity_id = ? AND customerId = ?", 'iii', [$targetId, $e, $sourceId]);
            $exists = $this->conn->query("SHOW TABLES LIKE 'crm_leads'");
            if ($exists && $exists->num_rows) {
                $this->exec("UPDATE crm_leads SET converted_customer_id = ? WHERE entity_id = ? AND converted_customer_id = ?", 'iii', [$targetId, $e, $sourceId]);
            }
        } else {
            $this->exec("UPDATE purchase_invoices SET supplierId = ? WHERE entity_id = ? AND supplierId = ?", 'iii', [$targetId, $e, $sourceId]);
        }
        // CRM history (calls, promises, tasks) follows the person too.
        foreach (['payments', 'return_invoices', 'expenses', 'incomes', 'recurring_expenses', 'asset_documents', 'crm_activities', 'crm_tasks'] as $table) {
            $exists = $this->conn->query("SHOW TABLES LIKE '{$table}'");
            if (!($exists && $exists->num_rows)) {
                continue;
            }
            $this->exec(
                "UPDATE `{$table}` SET person_id = ? WHERE entity_id = ? AND person_type = ? AND person_id = ?",
                'iisi', [$targetId, $e, $type, $sourceId]
            );
        }

        // Shop integration: the target keeps its own mapping; the source's
        // mapping moves over unless the target already has one for that system.
        $exists = $this->conn->query("SHOW TABLES LIKE 'integration_links'");
        if ($exists && $exists->num_rows) {
            $src = (string)$sourceId;
            $dst = (string)$targetId;
            $this->exec(
                "UPDATE IGNORE integration_links SET local_id = ? WHERE entity_type = ? AND business_entity_id = ? AND local_id = ?",
                'ssis', [$dst, $type, $e, $src]
            );
            $this->exec(
                "DELETE FROM integration_links WHERE entity_type = ? AND business_entity_id = ? AND local_id = ?",
                'sis', [$type, $e, $src]
            );
        }

        // Profile: target wins, blanks are filled from the source, phones combined.
        $initial = (float)$source['initial_balance'] + (float)$target['initial_balance'];
        $phones = self::mergePhones($target['phone'] ?? '', $source['phone'] ?? '');
        $pick = function ($field) use ($source, $target) {
            $t = trim((string)($target[$field] ?? ''));
            return $t !== '' ? $target[$field] : ($source[$field] ?? '');
        };
        $table = self::table($type);
        if ($type === 'customer') {
            $groupId = $target['group_id'] ?? $source['group_id'] ?? null;
            $groupId = $groupId !== null ? (int)$groupId : null;
            $this->exec(
                "UPDATE customers SET phone = ?, address = ?, job = ?, nationalId = ?, initial_balance = ?, group_id = ? WHERE id = ? AND entity_id = ?",
                'ssssdiii', [$phones, $pick('address'), $pick('job'), $pick('nationalId'), $initial, $groupId, $targetId, $e]
            );
        } else {
            $this->exec(
                "UPDATE suppliers SET phone = ?, address = ?, job = ?, economicCode = ?, initial_balance = ? WHERE id = ? AND entity_id = ?",
                'ssssdii', [$phones, $pick('address'), $pick('job'), $pick('economicCode'), $initial, $targetId, $e]
            );
        }

        // The source's link (if any) moves to the target.
        $sourceTwin = $this->twinId($type, $sourceId);
        $targetTwin = $this->twinId($type, $targetId);
        if ($sourceTwin) {
            $this->unlink($type, $sourceId);
            if (!$targetTwin) {
                $this->setLink($type, $targetId, $sourceTwin);
                $this->copyShared($type, $targetId, self::otherType($type), $sourceTwin);
            }
        }

        $this->exec("DELETE FROM `{$table}` WHERE id = ? AND entity_id = ?", 'ii', [$sourceId, $e]);
    }
}
