<?php
// /api/models/CrmLead.php
// Potential customers: a simple pipeline (new → contacted → quoted → won/lost)
// and conversion into a real customer that carries the lead's history along.
require_once __DIR__ . '/Customer.php';
require_once __DIR__ . '/TeamMember.php';

class CrmLead {
    const STAGES = ['new', 'contacted', 'quoted', 'won', 'lost'];

    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function save(int $entityId, array $d): array {
        $id = (int)($d['id'] ?? 0);
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'نام سرنخ الزامی است.'];
        }
        $phone = trim((string)($d['phone'] ?? '')) ?: null;
        $source = mb_substr(trim((string)($d['source'] ?? '')), 0, 100) ?: null;
        $interest = trim((string)($d['interest'] ?? '')) ?: null;
        $notes = trim((string)($d['notes'] ?? '')) ?: null;
        $owner = (int)($d['owner_user_id'] ?? 0) ?: null;
        if ($owner !== null && !in_array($owner, TeamMember::userIdsForEntity($this->conn, $entityId), true)) {
            return ['error' => 'مسئول انتخاب‌شده عضو فعال تیم نیست.'];
        }
        if ($id > 0) {
            $stmt = $this->conn->prepare("UPDATE crm_leads SET name = ?, phone = ?, source = ?, interest = ?, notes = ?, owner_user_id = ? WHERE id = ? AND entity_id = ?");
            $stmt->bind_param('sssssiii', $name, $phone, $source, $interest, $notes, $owner, $id, $entityId);
            $stmt->execute();
            if ($stmt->affected_rows < 1 && !$this->get($entityId, $id)) {
                return ['error' => 'سرنخ یافت نشد.', 'statusCode' => 404];
            }
            log_activity($this->conn, 'crm_lead_update', "ویرایش سرنخ: {$name}");
            return ['success' => true, 'id' => $id];
        }
        $stmt = $this->conn->prepare("INSERT INTO crm_leads (entity_id, name, phone, source, interest, notes, owner_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isssssi', $entityId, $name, $phone, $source, $interest, $notes, $owner);
        $stmt->execute();
        $newId = (int)$this->conn->insert_id; // before log_activity, which inserts its own row
        log_activity($this->conn, 'crm_lead_create', "سرنخ جدید: {$name}");
        return ['success' => true, 'id' => $newId];
    }

    public function get(int $entityId, int $id): ?array {
        $stmt = $this->conn->prepare("SELECT * FROM crm_leads WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $id, $entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt)[0] ?? null;
    }

    public function setStage(int $entityId, int $id, string $stage, ?string $lostReason = null): array {
        if (!in_array($stage, self::STAGES, true)) {
            return ['error' => 'مرحله نامعتبر است.'];
        }
        $lead = $this->get($entityId, $id);
        if (!$lead) {
            return ['error' => 'سرنخ یافت نشد.', 'statusCode' => 404];
        }
        if ($stage === 'won' && empty($lead['converted_customer_id'])) {
            return ['error' => 'برای «خرید کرد»، سرنخ را به مشتری تبدیل کنید.'];
        }
        $reason = trim((string)$lostReason);
        if ($stage === 'lost' && $reason === '') {
            return ['error' => 'دلیل انصراف را بنویسید.'];
        }
        $reasonValue = $stage === 'lost' ? mb_substr($reason, 0, 255) : null;
        $stmt = $this->conn->prepare("UPDATE crm_leads SET stage = ?, lost_reason = ? WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ssii', $stage, $reasonValue, $id, $entityId);
        $stmt->execute();
        log_activity($this->conn, 'crm_lead_stage', "سرنخ #{$id}: {$stage}");
        return ['success' => true];
    }

    /** Leads grouped by stage, each with its last activity and owner name. */
    public function listByStage(int $entityId, ?string $q = null): array {
        $sql = "SELECT l.*, tm.full_name AS owner_name,
                    la.created_at AS last_activity_at, la.outcome AS last_activity_outcome, la.body AS last_activity_body
                FROM crm_leads l
                LEFT JOIN team_members tm ON tm.entity_id = l.entity_id AND tm.user_id = l.owner_user_id
                LEFT JOIN crm_activities la ON la.id = (
                    SELECT a.id FROM crm_activities a
                    WHERE a.entity_id = l.entity_id AND a.person_type = 'lead' AND a.person_id = l.id
                    ORDER BY a.created_at DESC, a.id DESC LIMIT 1)
                WHERE l.entity_id = ?";
        $types = 'i';
        $params = [$entityId];
        if ($q !== null && trim($q) !== '') {
            $like = '%' . trim($q) . '%';
            $sql .= " AND (l.name LIKE ? OR l.phone LIKE ? OR l.interest LIKE ?)";
            $types .= 'sss';
            array_push($params, $like, $like, $like);
        }
        // Won/lost are history: only the recent ones are shown.
        $sql .= " AND (l.stage NOT IN ('won', 'lost') OR l.updated_at >= DATE_SUB(NOW(), INTERVAL 90 DAY))
                  ORDER BY COALESCE(la.created_at, l.created_at) DESC LIMIT 500";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $out = array_fill_keys(self::STAGES, []);
        foreach (db_stmt_to_assoc_array($stmt) as $row) {
            $out[$row['stage']][] = $row;
        }
        return $out;
    }

    /**
     * Create the customer (through the normal customer rules), then mark the
     * lead won and move its activities and tasks to the customer.
     * Customer::save may run DDL (implicit commit), so it is not wrapped in the
     * same transaction; on its failure nothing about the lead has changed.
     */
    public function convert(int $entityId, int $leadId, array $customerData): array {
        $lead = $this->get($entityId, $leadId);
        if (!$lead) {
            return ['error' => 'سرنخ یافت نشد.', 'statusCode' => 404];
        }
        if (!empty($lead['converted_customer_id'])) {
            return ['error' => 'این سرنخ قبلاً به مشتری تبدیل شده است.'];
        }
        $customerData += ['name' => $lead['name'], 'phone' => $lead['phone']];
        unset($customerData['id']);
        $saved = (new Customer($this->conn))->save($customerData);
        if (empty($saved['success'])) {
            return ['error' => $saved['error'] ?? 'ثبت مشتری ناموفق بود.', 'statusCode' => $saved['statusCode'] ?? 400];
        }
        $customerId = (int)$saved['id'];
        $owner = $lead['owner_user_id'] !== null ? (int)$lead['owner_user_id'] : null;

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare("UPDATE crm_leads SET stage = 'won', converted_customer_id = ?, lost_reason = NULL WHERE id = ? AND entity_id = ?");
            $stmt->bind_param('iii', $customerId, $leadId, $entityId);
            $stmt->execute();
            foreach (['crm_activities', 'crm_tasks'] as $table) {
                $stmt = $this->conn->prepare("UPDATE {$table} SET person_type = 'customer', person_id = ? WHERE entity_id = ? AND person_type = 'lead' AND person_id = ?");
                $stmt->bind_param('iii', $customerId, $entityId, $leadId);
                $stmt->execute();
            }
            if ($owner !== null) {
                $stmt = $this->conn->prepare("UPDATE customers SET owner_user_id = ? WHERE id = ? AND entity_id = ?");
                $stmt->bind_param('iii', $owner, $customerId, $entityId);
                $stmt->execute();
            }
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
        log_activity($this->conn, 'crm_lead_convert', "تبدیل سرنخ «{$lead['name']}» به مشتری #{$customerId}");
        return ['success' => true, 'customer_id' => $customerId];
    }
}
