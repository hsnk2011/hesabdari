<?php
// /api/controllers/ChatController.php
// Internal messenger (میز کار). The router checks use_messenger.
require_once __DIR__ . '/../models/WorkChat.php';

class ChatController {
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

    private function chat(): WorkChat {
        return new WorkChat($this->conn);
    }

    public function listConversations($data) {
        $this->guarded(fn() => ['success' => true, 'me' => $this->uid(), 'conversations' => $this->chat()->listFor($this->e(), $this->uid())]);
    }

    public function openDirect($data) {
        $this->guarded(fn() => $this->chat()->openDirect($this->e(), $this->uid(), (int)($data['user_id'] ?? 0)));
    }

    public function createGroup($data) {
        $this->guarded(fn() => $this->chat()->createGroup($this->e(), $this->uid(), $data['title'] ?? '', $data['description'] ?? null, (array)($data['member_ids'] ?? [])));
    }

    public function updateGroup($data) {
        $this->guarded(fn() => $this->chat()->updateGroup($this->e(), $this->uid(), (int)($data['id'] ?? 0), $data));
    }

    public function members($data) {
        $this->guarded(function () use ($data) {
            $c = $this->chat();
            $cid = (int)($data['id'] ?? 0);
            switch ($data['op'] ?? '') {
                case 'add':
                    return $c->addMembers($this->e(), $this->uid(), $cid, (array)($data['user_ids'] ?? []));
                case 'remove':
                    return $c->removeMember($this->e(), $this->uid(), $cid, (int)($data['user_id'] ?? 0));
                case 'admin':
                    return $c->setAdmin($this->e(), $this->uid(), $cid, (int)($data['user_id'] ?? 0), !empty($data['is_admin']));
            }
            return ['error' => 'عملیات نامعتبر است.'];
        });
    }

    public function leave($data) {
        $this->guarded(fn() => $this->chat()->leave($this->e(), $this->uid(), (int)($data['id'] ?? 0)));
    }

    // ---------- messages ----------

    public function messages($data) {
        $this->guarded(fn() => $this->chat()->messages($this->e(), $this->uid(), (int)($data['id'] ?? 0), [
            'since' => $data['since'] ?? null, 'before_id' => $data['before_id'] ?? null,
            'around_id' => $data['around_id'] ?? null, 'search' => $data['search'] ?? null,
        ]));
    }

    /** JSON or multipart (fields + file[]; voice=1 for a recorded voice note). */
    public function send($data) {
        $this->guarded(function () use ($data) {
            if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && strpos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart') === 0) {
                return ['error' => 'فایلی دریافت نشد؛ شاید حجم آن از سقف سرور (' . (int)floor(WorkFiles::uploadLimitBytes() / 1048576) . ' مگابایت) بیشتر است.'];
            }
            return $this->chat()->send($this->e(), $this->uid(), (int)($data['id'] ?? 0), [
                'body' => $data['body'] ?? '', 'reply_to_id' => $data['reply_to_id'] ?? null,
                'ref_type' => $data['ref_type'] ?? null, 'ref_id' => $data['ref_id'] ?? null, 'voice' => !empty($data['voice']),
            ], WorkFiles::normalize($_FILES['file'] ?? null));
        });
    }

    public function edit($data) {
        $this->guarded(fn() => $this->chat()->edit($this->e(), $this->uid(), (int)($data['message_id'] ?? 0), (string)($data['body'] ?? '')));
    }

    public function deleteMessage($data) {
        $this->guarded(fn() => $this->chat()->delete($this->e(), $this->uid(), (int)($data['message_id'] ?? 0)));
    }

    public function react($data) {
        $this->guarded(fn() => $this->chat()->react($this->e(), $this->uid(), (int)($data['message_id'] ?? 0), (string)($data['emoji'] ?? '')));
    }

    public function pin($data) {
        $this->guarded(fn() => $this->chat()->pin($this->e(), $this->uid(), (int)($data['id'] ?? 0), ((int)($data['message_id'] ?? 0)) ?: null));
    }

    public function read($data) {
        $this->guarded(fn() => $this->chat()->markRead($this->e(), $this->uid(), (int)($data['id'] ?? 0)));
    }

    public function forward($data) {
        $this->guarded(fn() => $this->chat()->forward($this->e(), $this->uid(), (int)($data['message_id'] ?? 0), (int)($data['to_id'] ?? 0)));
    }

    public function unread($data) {
        $this->guarded(fn() => ['success' => true] + $this->chat()->unreadTotal($this->e(), $this->uid()));
    }

    public function prefs($data) {
        $this->guarded(function () use ($data) {
            $c = $this->chat();
            $cid = (int)($data['id'] ?? 0);
            if (array_key_exists('muted', $data)) {
                $r = $c->setMuted($this->e(), $this->uid(), $cid, !empty($data['muted']));
                if (!empty($r['error'])) {
                    return $r;
                }
            }
            if (array_key_exists('pinned', $data)) {
                return $c->setPinned($this->e(), $this->uid(), $cid, !empty($data['pinned']));
            }
            return ['success' => true];
        });
    }
}
