<?php
// /api/controllers/OfficeController.php
// میز کار office work: cartable (requests and letters), staff files, ideas.
// The router checks use_cartable / manage_hr / use_ideas.
require_once __DIR__ . '/../models/WorkRequest.php';
require_once __DIR__ . '/../models/WorkFiles.php';
require_once __DIR__ . '/../models/HrPersonnel.php';
require_once __DIR__ . '/../models/WorkIdea.php';

class OfficeController {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    private function e(): int {
        return (int)$_SESSION['current_entity_id'];
    }

    private function uid(): int {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    private function can(string $permission): bool {
        return in_array($permission, $_SESSION['permissions'] ?? [], true);
    }

    private function guarded(callable $fn) {
        try {
            $result = $fn();
        } catch (Exception $ex) {
            $code = is_numeric($ex->getCode()) && $ex->getCode() >= 400 && $ex->getCode() < 600 ? (int)$ex->getCode() : 500;
            send_json(['error' => $ex->getMessage()], $code);
            return;
        }
        if (!empty($result['error'])) {
            send_json(['error' => $result['error']], $result['statusCode'] ?? 400);
        }
        send_json($result);
    }

    private function uploadTooBig(): ?array {
        if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && strpos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart') === 0) {
            return ['error' => 'فایلی دریافت نشد؛ شاید حجم آن از سقف سرور (' . (int)floor(WorkFiles::uploadLimitBytes() / 1048576) . ' مگابایت) بیشتر است.'];
        }
        return null;
    }

    // ---------- cartable ----------

    public function requests($data) {
        $this->guarded(function () use ($data) {
            $perms = WorkRequest::permsFromSession();
            return ['success' => true, 'perms' => $perms, 'categories' => WorkRequest::CATEGORIES,
                'requests' => (new WorkRequest($this->conn))->listFor($this->e(), $this->uid(), $perms, (string)($data['tab'] ?? 'mine_action'), $data['kind'] ?? null)];
        });
    }

    public function request($data) {
        $this->guarded(fn() => (new WorkRequest($this->conn))->detail($this->e(), $this->uid(), WorkRequest::permsFromSession(), (int)($data['id'] ?? 0))
            + ['upload_limit' => WorkFiles::uploadLimitBytes()]);
    }

    public function requestSave($data) {
        $this->guarded(function () use ($data) {
            if ($err = $this->uploadTooBig()) {
                return $err;
            }
            return (new WorkRequest($this->conn))->create($this->e(), $this->uid(), $data, WorkFiles::normalize($_FILES['file'] ?? null));
        });
    }

    public function requestAct($data) {
        $this->guarded(fn() => (new WorkRequest($this->conn))->act($this->e(), $this->uid(), WorkRequest::permsFromSession(), (int)($data['id'] ?? 0), (string)($data['action'] ?? ''), $data));
    }

    public function requestFiles($data) {
        $this->guarded(function () use ($data) {
            if ($err = $this->uploadTooBig()) {
                return $err;
            }
            return (new WorkRequest($this->conn))->addFiles($this->e(), $this->uid(), WorkRequest::permsFromSession(), (int)($data['id'] ?? 0), WorkFiles::normalize($_FILES['file'] ?? null));
        });
    }

    public function payOptions($data) {
        $this->guarded(fn() => (new WorkRequest($this->conn))->payOptions($this->e()));
    }

    // ---------- staff files ----------

    public function hrList($data) {
        $this->guarded(fn() => ['success' => true, 'kinds' => HrPersonnel::KINDS,
            'people' => (new HrPersonnel($this->conn))->list($this->e(), $data['q'] ?? null, !empty($data['inactive']))]);
    }

    public function hrSave($data) {
        $this->guarded(fn() => (new HrPersonnel($this->conn))->save($this->e(), $this->uid(), $data));
    }

    public function hrDetail($data) {
        $this->guarded(fn() => (new HrPersonnel($this->conn))->detail($this->e(), (int)($data['id'] ?? 0)) + ['kinds' => HrPersonnel::KINDS]);
    }

    public function hrRecord($data) {
        $this->guarded(function () use ($data) {
            $hr = new HrPersonnel($this->conn);
            if (($data['op'] ?? '') === 'delete') {
                return $hr->deleteRecord($this->e(), $this->uid(), (int)($data['record_id'] ?? 0));
            }
            return $hr->addRecord($this->e(), $this->uid(), (int)($data['id'] ?? 0), $data);
        });
    }

    public function hrImportTeam($data) {
        $this->guarded(fn() => ['success' => true, 'made' => (new HrPersonnel($this->conn))->importTeam($this->e(), $this->uid())]);
    }

    // ---------- ideas ----------

    public function ideas($data) {
        $this->guarded(fn() => ['success' => true, 'can_decide' => $this->can('manage_projects'),
            'ideas' => (new WorkIdea($this->conn))->listFor($this->e(), $this->uid(), $this->can('manage_projects'))]);
    }

    public function idea($data) {
        $this->guarded(fn() => (new WorkIdea($this->conn))->detail($this->e(), $this->uid(), $this->can('manage_projects'), (int)($data['id'] ?? 0)));
    }

    public function ideaSave($data) {
        $this->guarded(function () use ($data) {
            $wi = new WorkIdea($this->conn);
            $id = (int)($data['id'] ?? 0);
            return $id > 0 ? $wi->update($this->e(), $this->uid(), $id, $data) : $wi->create($this->e(), $this->uid(), $data);
        });
    }

    public function ideaDelete($data) {
        $this->guarded(fn() => (new WorkIdea($this->conn))->delete($this->e(), $this->uid(), (int)($data['id'] ?? 0)));
    }

    public function ideaComment($data) {
        $this->guarded(fn() => (new WorkIdea($this->conn))->comment($this->e(), $this->uid(), (int)($data['id'] ?? 0), (string)($data['body'] ?? '')));
    }

    public function ideaVote($data) {
        $this->guarded(fn() => (new WorkIdea($this->conn))->vote($this->e(), $this->uid(), (int)($data['id'] ?? 0), !empty($data['agree'])));
    }

    public function ideaDecide($data) {
        $this->guarded(fn() => (new WorkIdea($this->conn))->decide($this->e(), $this->uid(), $this->can('manage_projects'), (int)($data['id'] ?? 0), (string)($data['decision'] ?? ''), $data));
    }

    /** Staff and projects for the payment-request form. */
    public function hrOptions($data) {
        $this->guarded(function () {
            $e = $this->e();
            return ['success' => true, 'personnel' => (new HrPersonnel($this->conn))->options($e),
                'projects' => $this->conn->query("SELECT id, title FROM projects WHERE entity_id = {$e} AND status IN ('planning','active','on_hold') ORDER BY title")->fetch_all(MYSQLI_ASSOC),
                'suppliers' => $this->conn->query("SELECT id, name FROM suppliers WHERE entity_id = {$e} ORDER BY name LIMIT 1000")->fetch_all(MYSQLI_ASSOC)];
        });
    }
}
