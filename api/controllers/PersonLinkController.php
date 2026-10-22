<?php
// /api/controllers/PersonLinkController.php

require_once __DIR__ . '/../models/PersonLink.php';

class PersonLinkController {
    private $conn;
    private $model;

    public function __construct($db) {
        $this->conn = $db;
        $this->model = new PersonLink($db);
    }

    /** Both persons' types must be manageable by the user. */
    private function readPair(array $data): ?array {
        $pair = [
            (string)($data['source_type'] ?? ''), (int)($data['source_id'] ?? 0),
            (string)($data['target_type'] ?? ''), (int)($data['target_id'] ?? 0),
        ];
        if (!PersonLink::isPersonType($pair[0]) || !PersonLink::isPersonType($pair[2]) || $pair[1] <= 0 || $pair[3] <= 0) {
            send_json(['error' => 'دو شخص معتبر برای ادغام انتخاب کنید.'], 400);
            return null;
        }
        foreach ([$pair[0], $pair[2]] as $type) {
            if (!PersonLink::canManage($type)) {
                send_json(['error' => 'شما دسترسی مدیریت ' . PersonLink::label($type) . '‌ها را ندارید.'], 403);
                return null;
            }
        }
        return $pair;
    }

    public function mergePlan($data) {
        $pair = $this->readPair($data);
        if (!$pair) {
            return;
        }
        $plan = $this->model->mergePlan(...$pair);
        // A refused merge is an answer for the dialog, not a failed request.
        send_json(!empty($plan['error']) ? ['blocked' => $plan['error']] : $plan);
    }

    public function merge($data) {
        $pair = $this->readPair($data);
        if (!$pair) {
            return;
        }
        if ($pair[0] === $pair[2]) {
            // The merged-away duplicate is deleted: keep its profile in the recycle bin.
            require_once __DIR__ . '/../core/DocumentTrash.php';
            DocumentTrash::captureBeforeAction($this->conn, 'delete_' . $pair[0], ['id' => $pair[1]]);
        }
        $result = $this->model->merge(...$pair);
        if (!empty($result['error'])) {
            send_json(['error' => $result['error']], 409);
            return;
        }
        $src = $result['source'];
        $dst = $result['target'];
        if ($result['mode'] === 'link') {
            $desc = PersonLink::label($src['type']) . " «{$src['name']}» (#{$src['id']}) و " . PersonLink::label($dst['type']) . " «{$dst['name']}» (#{$dst['id']}) به‌عنوان یک شخص (هم مشتری و هم تأمین‌کننده) متصل شدند.";
        } else {
            $moved = [];
            foreach ($result['documents'] as $label => $n) {
                $moved[] = "{$n} {$label}";
            }
            $desc = PersonLink::label($src['type']) . " «{$src['name']}» (#{$src['id']}) در «{$dst['name']}» (#{$dst['id']}) ادغام و حذف شد"
                . ($moved ? '؛ اسناد منتقل‌شده: ' . implode('، ', $moved) : '') . '.';
        }
        log_activity($this->conn, 'MERGE_PERSONS', $desc);
        send_json($result);
    }
}
