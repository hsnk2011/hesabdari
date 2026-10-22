<?php
// /api/controllers/WorkController.php
// میز کار: projects, tasks, reports, documents, meetings and «کارهای من».
// The router checks use_projects; manage_projects (see/manage everything,
// create projects) is read from the session here.
require_once __DIR__ . '/../models/TeamMember.php';
require_once __DIR__ . '/../models/WorkProject.php';
require_once __DIR__ . '/../models/WorkTask.php';
require_once __DIR__ . '/../models/WorkFiles.php';
require_once __DIR__ . '/../models/WorkMeeting.php';
require_once __DIR__ . '/../models/MyWork.php';

class WorkController {
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

    private function seesAll(): bool {
        return $this->can('manage_projects');
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

    // ---------- projects ----------

    public function projects($data) {
        $this->guarded(fn() => [
            'success' => true,
            'projects' => (new WorkProject($this->conn))->listFor($this->e(), $this->uid(), $this->seesAll(), (string)($data['filter'] ?? 'active')),
            'can_create' => $this->seesAll(),
        ]);
    }

    public function project($data) {
        $this->guarded(function () use ($data) {
            $wp = new WorkProject($this->conn);
            $id = (int)($data['id'] ?? 0);
            $acc = $wp->access($this->e(), $this->uid(), $this->seesAll(), $id);
            if (!$acc) {
                return ['error' => 'پروژه یافت نشد.', 'statusCode' => 404];
            }
            return ['success' => true] + $this->projectDetail($wp, $acc);
        });
    }

    /** Everything the project sheet shows; later sections add tasks, reports, files and budget. */
    private function projectDetail(WorkProject $wp, array $acc): array {
        $p = $acc['project'];
        $tasks = (new WorkTask($this->conn))->listForProject($this->e(), (int)$p['id'], $this->uid());
        return [
            'project' => $p,
            'members' => $acc['members'],
            'can_manage' => $acc['canManage'],
            'is_member' => $acc['isMember'],
            'my_role' => $acc['myRole'],
            'me' => $this->uid(),
            'tasks' => $tasks['tasks'],
            'done_log' => $tasks['done_log'],
            'progress' => WorkProject::progress(array_column($tasks['tasks'], 'status')),
            'comments' => $wp->comments($this->e(), (int)$p['id']),
            'files' => WorkFiles::listFor($this->conn, $this->e(), 'project', (int)$p['id']),
            'budget' => $wp->budget($this->e(), (int)$p['id']),
            'upload_limit' => WorkFiles::uploadLimitBytes(),
        ];
    }

    // ---------- reports, documents, files ----------

    /** Files of a multipart request (field name "file" or "file[]"). */
    private function requestFiles(): array {
        return WorkFiles::normalize($_FILES['file'] ?? null);
    }

    /** A multipart POST whose body exceeded post_max_size arrives with no fields at all. */
    private function emptyUpload(): ?array {
        if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            return ['error' => 'فایلی دریافت نشد؛ شاید حجم آن از سقف سرور (' . (int)floor(WorkFiles::uploadLimitBytes() / 1048576) . ' مگابایت) بیشتر است.'];
        }
        return null;
    }

    public function commentSave($data) {
        $this->guarded(function () use ($data) {
            if ($err = $this->emptyUpload()) {
                return $err;
            }
            $wp = new WorkProject($this->conn);
            $id = (int)($data['id'] ?? 0);
            if ($id > 0) {
                return $wp->editComment($this->e(), $this->uid(), $this->seesAll(), $id, (string)($data['body'] ?? ''));
            }
            return $wp->addComment($this->e(), $this->uid(), $this->seesAll(), (int)($data['project_id'] ?? 0), (string)($data['body'] ?? ''), $this->requestFiles());
        });
    }

    public function commentDelete($data) {
        $this->guarded(fn() => (new WorkProject($this->conn))->deleteComment($this->e(), $this->uid(), $this->seesAll(), (int)($data['id'] ?? 0), !empty($data['with_files'])));
    }

    public function docUpload($data) {
        $this->guarded(function () use ($data) {
            if ($err = $this->emptyUpload()) {
                return $err;
            }
            return (new WorkProject($this->conn))->addDocuments($this->e(), $this->uid(), $this->seesAll(), (int)($data['project_id'] ?? 0), $this->requestFiles(), $data['title'] ?? null, $data['description'] ?? null);
        });
    }

    public function docDelete($data) {
        $this->guarded(fn() => (new WorkProject($this->conn))->deleteDocument($this->e(), $this->uid(), $this->seesAll(), (int)($data['id'] ?? 0)));
    }

    /** GET api.php?action=work_file&id=N — streams the file after checking who may see its owner. */
    public function file($data) {
        $id = (int)($data['id'] ?? ($_GET['id'] ?? 0));
        $row = WorkFiles::get($this->conn, $this->e(), $id);
        $allowed = false;
        if ($row && $row['owner_type'] === 'project') {
            $allowed = (new WorkProject($this->conn))->access($this->e(), $this->uid(), $this->seesAll(), (int)$row['owner_id']) !== null;
        } elseif ($row && $row['owner_type'] === 'meeting' && class_exists('WorkMeeting')) {
            $allowed = (new WorkMeeting($this->conn))->visible($this->e(), $this->uid(), $this->seesAll(), (int)$row['owner_id']) !== null;
        } elseif ($row && $row['owner_type'] === 'chat') {
            require_once __DIR__ . '/../models/WorkChat.php';
            $allowed = $this->can('use_messenger') && (new WorkChat($this->conn))->canSeeFile($this->e(), $this->uid(), $row);
        } elseif ($row && $row['owner_type'] === 'request') {
            require_once __DIR__ . '/../models/WorkRequest.php';
            $allowed = (new WorkRequest($this->conn))->detail($this->e(), $this->uid(), WorkRequest::permsFromSession(), (int)$row['owner_id'])['success'] ?? false;
        }
        if (!$allowed) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit('فایل یافت نشد.');
        }
        WorkFiles::stream($row);
    }

    // ---------- meetings ----------

    public function meetings($data) {
        $this->guarded(fn() => ['success' => true, 'items' => (new WorkMeeting($this->conn))->listFor($this->e(), $this->uid(), $this->seesAll(), (string)($data['filter'] ?? 'upcoming'))]);
    }

    public function meeting($data) {
        $this->guarded(fn() => (new WorkMeeting($this->conn))->detail($this->e(), $this->uid(), $this->seesAll(), (int)($data['id'] ?? 0)) + ['upload_limit' => WorkFiles::uploadLimitBytes()]);
    }

    public function meetingSave($data) {
        $this->guarded(function () use ($data) {
            $wm = new WorkMeeting($this->conn);
            $id = (int)($data['id'] ?? 0);
            return $id > 0
                ? $wm->update($this->e(), $this->uid(), $this->seesAll(), $id, $data)
                : $wm->create($this->e(), $this->uid(), $this->seesAll(), $data);
        });
    }

    public function meetingDelete($data) {
        $this->guarded(fn() => (new WorkMeeting($this->conn))->delete($this->e(), $this->uid(), $this->seesAll(), (int)($data['id'] ?? 0)));
    }

    public function meetingAction($data) {
        $this->guarded(function () use ($data) {
            $wm = new WorkMeeting($this->conn);
            $mid = (int)($data['meeting_id'] ?? 0);
            $aid = (int)($data['id'] ?? 0);
            switch ($data['op'] ?? '') {
                case 'add':
                    return $wm->addAction($this->e(), $this->uid(), $this->seesAll(), $mid, $data);
                case 'done':
                    return $wm->setActionDone($this->e(), $this->uid(), $this->seesAll(), $mid, $aid, !empty($data['done']));
                case 'to_task':
                    return $wm->actionToTask($this->e(), $this->uid(), $this->seesAll(), $mid, $aid);
                case 'delete':
                    return $wm->deleteAction($this->e(), $this->uid(), $this->seesAll(), $mid, $aid);
            }
            return ['error' => 'عملیات نامعتبر است.'];
        });
    }

    public function meetingFiles($data) {
        $this->guarded(function () use ($data) {
            $wm = new WorkMeeting($this->conn);
            if (($data['op'] ?? '') === 'delete') {
                return $wm->deleteFile($this->e(), $this->uid(), $this->seesAll(), (int)($data['id'] ?? 0));
            }
            if ($err = $this->emptyUpload()) {
                return $err;
            }
            return $wm->addFiles($this->e(), $this->uid(), $this->seesAll(), (int)($data['meeting_id'] ?? 0), $this->requestFiles());
        });
    }

    // ---------- my work ----------

    public function my($data) {
        $this->guarded(function () {
            $chat = ['total' => 0, 'chats' => 0];
            if ($this->can('use_messenger')) {
                require_once __DIR__ . '/../models/WorkChat.php';
                $chat = (new WorkChat($this->conn))->unreadTotal($this->e(), $this->uid());
            }
            return ['success' => true, 'me' => $this->uid(), 'can_projects' => $this->can('use_projects'), 'can_crm' => $this->can('use_crm'), 'chat_unread' => $chat]
                + MyWork::forUser($this->conn, $this->e(), $this->uid());
        });
    }

    /** Projects for the «پروژه» select of the expense form. */
    public function projectOptions($data) {
        // The expense form asks with manage_transactions only: with «میز کار» off it gets no projects and hides the field.
        if (!workspace_enabled($this->conn)) {
            send_json(['success' => true, 'projects' => []]);
            return;
        }
        $this->guarded(fn() => ['success' => true, 'projects' => (new WorkProject($this->conn))->expenseOptions($this->e())]);
    }

    // ---------- tasks ----------

    public function taskSave($data) {
        $this->guarded(function () use ($data) {
            $wt = new WorkTask($this->conn);
            $id = (int)($data['id'] ?? 0);
            return $id > 0
                ? $wt->update($this->e(), $this->uid(), $this->seesAll(), $id, $data)
                : $wt->create($this->e(), $this->uid(), $this->seesAll(), (int)($data['project_id'] ?? 0), $data);
        });
    }

    public function taskStatus($data) {
        $this->guarded(fn() => (new WorkTask($this->conn))->setStatus($this->e(), $this->uid(), $this->seesAll(), (int)($data['id'] ?? 0), (string)($data['status'] ?? '')));
    }

    public function taskComplete($data) {
        $this->guarded(fn() => (new WorkTask($this->conn))->complete($this->e(), $this->uid(), $this->seesAll(), (int)($data['id'] ?? 0), $data['note'] ?? null));
    }

    public function taskApprove($data) {
        $this->guarded(fn() => (new WorkTask($this->conn))->approve($this->e(), $this->uid(), $this->seesAll(), (int)($data['id'] ?? 0), $data['note'] ?? null));
    }

    public function taskReturn($data) {
        $this->guarded(fn() => (new WorkTask($this->conn))->sendBack($this->e(), $this->uid(), $this->seesAll(), (int)($data['id'] ?? 0), $data['reason'] ?? null));
    }

    public function taskReopen($data) {
        $this->guarded(fn() => (new WorkTask($this->conn))->reopen($this->e(), $this->uid(), $this->seesAll(), (int)($data['id'] ?? 0)));
    }

    public function taskDelete($data) {
        $this->guarded(fn() => (new WorkTask($this->conn))->delete($this->e(), $this->uid(), $this->seesAll(), (int)($data['id'] ?? 0)));
    }

    public function taskOrder($data) {
        $this->guarded(fn() => (new WorkTask($this->conn))->reorder($this->e(), $this->uid(), $this->seesAll(), (int)($data['project_id'] ?? 0), (array)($data['ids'] ?? [])));
    }

    public function taskCheck($data) {
        $this->guarded(function () use ($data) {
            $wt = new WorkTask($this->conn);
            switch ($data['op'] ?? '') {
                case 'add':
                    return $wt->addCheck($this->e(), $this->uid(), $this->seesAll(), (int)($data['task_id'] ?? 0), (string)($data['text'] ?? ''));
                case 'toggle':
                    return $wt->toggleCheck($this->e(), $this->uid(), $this->seesAll(), (int)($data['id'] ?? 0), !empty($data['done']));
                case 'delete':
                    return $wt->deleteCheck($this->e(), $this->uid(), $this->seesAll(), (int)($data['id'] ?? 0));
            }
            return ['error' => 'عملیات نامعتبر است.'];
        });
    }

    public function taskDeps($data) {
        $this->guarded(fn() => (new WorkTask($this->conn))->setDeps($this->e(), $this->uid(), $this->seesAll(), (int)($data['id'] ?? 0), (array)($data['depends_on'] ?? [])));
    }

    public function saveProject($data) {
        $this->guarded(function () use ($data) {
            $wp = new WorkProject($this->conn);
            $id = (int)($data['id'] ?? 0);
            return $id > 0
                ? $wp->update($this->e(), $this->uid(), $this->seesAll(), $id, $data)
                : $wp->create($this->e(), $this->uid(), $this->seesAll(), $data);
        });
    }

    public function deleteProject($data) {
        $this->guarded(fn() => (new WorkProject($this->conn))->delete($this->e(), $this->uid(), $this->seesAll(), (int)($data['id'] ?? 0)));
    }

    public function projectMember($data) {
        $this->guarded(function () use ($data) {
            $wp = new WorkProject($this->conn);
            $id = (int)($data['id'] ?? 0);
            $userId = (int)($data['user_id'] ?? 0);
            switch ($data['op'] ?? '') {
                case 'add':
                    return $wp->addMember($this->e(), $this->uid(), $this->seesAll(), $id, $userId, (string)($data['role'] ?? 'member'));
                case 'role':
                    return $wp->setMemberRole($this->e(), $this->uid(), $this->seesAll(), $id, $userId, (string)($data['role'] ?? ''));
                case 'remove':
                    return $wp->removeMember($this->e(), $this->uid(), $this->seesAll(), $id, $userId);
            }
            return ['error' => 'عملیات نامعتبر است.'];
        });
    }

    /** Active team members who can log in (pickers for members, assignees, attendees). */
    public function people($data) {
        $this->guarded(function () {
            $members = array_values(array_filter(
                (new TeamMember($this->conn))->list($this->e(), true),
                fn($m) => (int)$m['user_id'] > 0
            ));
            return ['success' => true, 'me' => $this->uid(), 'people' => array_map(fn($m) => [
                'user_id' => (int)$m['user_id'], 'full_name' => $m['full_name'], 'kind' => $m['kind'],
            ], $members)];
        });
    }
}
