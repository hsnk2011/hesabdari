<?php
// /api/controllers/CrmController.php
// CRM for a small team: members, push subscriptions, activities and promises,
// tasks, leads, customer timeline and debtors. Permissions (use_crm /
// manage_crm_team) are checked by the router.
require_once __DIR__ . '/../models/TeamMember.php';
require_once __DIR__ . '/../models/CrmPerson.php';
require_once __DIR__ . '/../models/CrmActivity.php';
require_once __DIR__ . '/../models/CrmTask.php';
require_once __DIR__ . '/../models/CrmLead.php';
require_once __DIR__ . '/../models/CrmTimeline.php';
require_once __DIR__ . '/../core/CrmNotifier.php';

class CrmController {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    private function entityId(): int {
        return (int)$_SESSION['current_entity_id'];
    }

    private function userId(): int {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    private function can(string $permission): bool {
        return in_array($permission, $_SESSION['permissions'] ?? [], true);
    }

    private function guarded(callable $fn) {
        try {
            $result = $fn();
        } catch (Exception $e) {
            $code = is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600 ? (int)$e->getCode() : 500;
            send_json(['error' => $e->getMessage()], $code);
            return;
        }
        if (!empty($result['error'])) {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 400);
        }
        send_json($result);
    }

    // ---------- Team ----------

    public function listTeam($data) {
        $this->guarded(function () use ($data) {
            $team = new TeamMember($this->conn);
            $out = [
                'success' => true,
                'members' => $team->list($this->entityId(), empty($data['include_inactive'])),
                'me' => $this->userId(),
                'can_manage' => $this->can('manage_crm_team'),
            ];
            if ($out['can_manage']) {
                $out['users'] = $this->conn->query("SELECT id, username FROM users ORDER BY username")->fetch_all(MYSQLI_ASSOC);
                $stmt = $this->conn->prepare("SELECT id, name FROM partners WHERE entity_id = ? ORDER BY name");
                $eid = $this->entityId();
                $stmt->bind_param('i', $eid);
                $stmt->execute();
                $out['partners'] = db_stmt_to_assoc_array($stmt);
            }
            return $out;
        });
    }

    public function saveTeamMember($data) {
        $this->guarded(fn() => (new TeamMember($this->conn))->save($this->entityId(), $data));
    }

    public function deactivateTeamMember($data) {
        $this->guarded(fn() => (new TeamMember($this->conn))->deactivate($this->entityId(), (int)($data['id'] ?? 0)));
    }

    // ---------- Activities & tasks ----------

    public function logActivity($data) {
        $this->guarded(fn() => (new CrmActivity($this->conn))->create($this->entityId(), $this->userId(), $data));
    }

    public function personActivities($data) {
        $this->guarded(function () use ($data) {
            $type = (string)($data['person_type'] ?? '');
            $id = (int)($data['person_id'] ?? 0);
            if (!CrmPerson::exists($this->conn, $this->entityId(), $type, $id)) {
                return ['error' => 'شخص انتخاب‌شده یافت نشد.', 'statusCode' => 404];
            }
            return [
                'success' => true,
                'activities' => (new CrmActivity($this->conn))->listForPerson($this->entityId(), $type, $id),
                'tasks' => (new CrmTask($this->conn))->listForPerson($this->entityId(), $type, $id),
            ];
        });
    }

    public function saveTask($data) {
        $this->guarded(function () use ($data) {
            $tasks = new CrmTask($this->conn);
            $id = (int)($data['id'] ?? 0);
            return $id > 0
                ? $tasks->update($this->entityId(), $id, $data, $this->userId())
                : $tasks->create($this->entityId(), $this->userId(), $data);
        });
    }

    public function getTask($data) {
        $this->guarded(function () use ($data) {
            $task = (new CrmTask($this->conn))->get($this->entityId(), (int)($data['id'] ?? 0));
            return $task ? ['success' => true, 'task' => $task] : ['error' => 'کار یافت نشد.', 'statusCode' => 404];
        });
    }

    public function setTaskStatus($data) {
        $this->guarded(fn() => (new CrmTask($this->conn))->setStatus($this->entityId(), (int)($data['id'] ?? 0), (string)($data['status'] ?? ''), $this->userId()));
    }

    public function myTasks($data) {
        $this->guarded(fn() => ['success' => true] + (new CrmTask($this->conn))->listForUser($this->entityId(), $this->userId()));
    }

    public function teamTasks($data) {
        $this->guarded(fn() => ['success' => true, 'tasks' => (new CrmTask($this->conn))->listTeam($this->entityId(), [
            'assignee_user_id' => (int)($data['assignee_user_id'] ?? 0),
            'status' => (string)($data['status'] ?? 'open'),
        ])]);
    }

    // ---------- Leads ----------

    public function saveLead($data) {
        $this->guarded(fn() => (new CrmLead($this->conn))->save($this->entityId(), $data));
    }

    public function setLeadStage($data) {
        $this->guarded(fn() => (new CrmLead($this->conn))->setStage($this->entityId(), (int)($data['id'] ?? 0), (string)($data['stage'] ?? ''), $data['lost_reason'] ?? null));
    }

    public function listLeads($data) {
        $this->guarded(fn() => ['success' => true, 'stages' => (new CrmLead($this->conn))->listByStage($this->entityId(), $data['q'] ?? null)]);
    }

    public function getLead($data) {
        $this->guarded(function () use ($data) {
            $lead = (new CrmLead($this->conn))->get($this->entityId(), (int)($data['id'] ?? 0));
            return $lead ? ['success' => true, 'lead' => $lead] : ['error' => 'سرنخ یافت نشد.', 'statusCode' => 404];
        });
    }

    public function convertLead($data) {
        if (!$this->can('manage_customers')) {
            send_json(['error' => 'برای تبدیل سرنخ به مشتری، دسترسی مدیریت مشتریان لازم است.'], 403);
            return;
        }
        $this->guarded(fn() => (new CrmLead($this->conn))->convert($this->entityId(), (int)($data['id'] ?? 0), (array)($data['customer'] ?? [])));
    }

    // ---------- Customer file & debtors ----------

    public function customerTimeline($data) {
        $this->guarded(fn() => (new CrmTimeline($this->conn))->forCustomer(
            $this->entityId(), (int)($data['customer_id'] ?? 0), (int)($data['page'] ?? 1), (int)($data['limit'] ?? 30)
        ));
    }

    public function debtors($data) {
        $this->guarded(fn() => ['success' => true, 'debtors' => (new CrmTimeline($this->conn))->debtors($this->entityId())]);
    }

    public function setCustomerOwner($data) {
        $this->guarded(function () use ($data) {
            $eid = $this->entityId();
            $customerId = (int)($data['customer_id'] ?? 0);
            $owner = (int)($data['owner_user_id'] ?? 0) ?: null;
            if (!CrmPerson::exists($this->conn, $eid, 'customer', $customerId)) {
                return ['error' => 'مشتری یافت نشد.', 'statusCode' => 404];
            }
            if ($owner !== null && !in_array($owner, TeamMember::userIdsForEntity($this->conn, $eid), true)) {
                return ['error' => 'مسئول انتخاب‌شده عضو فعال تیم نیست.'];
            }
            $stmt = $this->conn->prepare("UPDATE customers SET owner_user_id = ? WHERE id = ? AND entity_id = ?");
            $stmt->bind_param('iii', $owner, $customerId, $eid);
            $stmt->execute();
            log_activity($this->conn, 'crm_customer_owner', "تعیین مسئول مشتری #{$customerId}");
            return ['success' => true];
        });
    }

    // ---------- Push ----------

    public function pushPublicKey($data) {
        $this->guarded(fn() => ['success' => true, 'public_key' => CrmNotifier::vapid($this->conn)['public']]);
    }

    public function pushSubscribe($data) {
        $this->guarded(function () use ($data) {
            $endpoint = trim((string)($data['endpoint'] ?? ''));
            $p256dh = trim((string)($data['keys']['p256dh'] ?? ''));
            $auth = trim((string)($data['keys']['auth'] ?? ''));
            if (!preg_match('#^https://[^\s]+$#', $endpoint) || strlen($endpoint) > 500
                || strlen(WebPush::b64urlDecode($p256dh)) !== 65 || strlen(WebPush::b64urlDecode($auth)) !== 16) {
                return ['error' => 'اطلاعات اشتراک نوتیفیکیشن نامعتبر است.'];
            }
            $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $eid = $this->entityId();
            $uid = $this->userId();
            $stmt = $this->conn->prepare(
                "INSERT INTO push_subscriptions (entity_id, user_id, endpoint, p256dh, auth, user_agent) VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE entity_id = VALUES(entity_id), user_id = VALUES(user_id), p256dh = VALUES(p256dh),
                    auth = VALUES(auth), user_agent = VALUES(user_agent), fail_count = 0"
            );
            $stmt->bind_param('iissss', $eid, $uid, $endpoint, $p256dh, $auth, $ua);
            $stmt->execute();
            return ['success' => true];
        });
    }

    public function pushUnsubscribe($data) {
        $this->guarded(function () use ($data) {
            $endpoint = trim((string)($data['endpoint'] ?? ''));
            $uid = $this->userId();
            $stmt = $this->conn->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?");
            $stmt->bind_param('si', $endpoint, $uid);
            $stmt->execute();
            return ['success' => true];
        });
    }

    public function pushTest($data) {
        $this->guarded(function () {
            $sent = CrmNotifier::push($this->conn, [$this->userId()], [
                'title' => 'آزمایش نوتیفیکیشن',
                'body' => 'نوتیفیکیشن روی این دستگاه فعال است.',
                'url' => './index.php#crm',
            ]);
            return $sent > 0 ? ['success' => true, 'sent' => $sent] : ['error' => 'هیچ دستگاه فعالی برای ارسال پیدا نشد.'];
        });
    }
}
