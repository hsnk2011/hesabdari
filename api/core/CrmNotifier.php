<?php
// /api/core/CrmNotifier.php
// Writes a notification row (for one user or the whole team) and delivers it
// as a Web Push to that user's devices. Push problems never break the caller.

require_once __DIR__ . '/WebPush.php';
require_once __DIR__ . '/../models/TeamMember.php';

class CrmNotifier {
    public static function notify($db, int $entityId, ?int $userId, string $type, string $title, string $message, string $url, ?string $refType = null, ?int $refId = null): void {
        $link = mb_substr($url, 0, 255);
        $stmt = $db->prepare("INSERT INTO notifications (entity_id, user_id, type, title, message, reference_type, reference_id, due_date, link) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)");
        $stmt->bind_param('iissssis', $entityId, $userId, $type, $title, $message, $refType, $refId, $link);
        $stmt->execute();
        $stmt->close();

        $userIds = $userId !== null ? [$userId] : TeamMember::userIdsForEntity($db, $entityId);
        if ($userIds) {
            self::push($db, $userIds, ['title' => $title, 'body' => $message, 'url' => $url]);
        }
    }

    /** Push to every subscription of the given users (any entity: a device belongs to a person). */
    public static function push($db, array $userIds, array $payload): int {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (!$userIds) {
            return 0;
        }
        try {
            $vapid = self::vapid($db);
        } catch (Throwable $e) {
            error_log('CrmNotifier: VAPID unavailable: ' . $e->getMessage());
            return 0;
        }
        $in = implode(',', $userIds);
        $res = $db->query("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id IN ({$in})");
        $sent = 0;
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $sub) {
            try {
                $result = WebPush::send($sub, $payload, $vapid);
            } catch (Throwable $e) {
                $result = ['status' => 0, 'gone' => false];
            }
            self::handleResult($db, (int)$sub['id'], $result);
            if ($result['status'] >= 200 && $result['status'] < 300) {
                $sent++;
            }
        }
        return $sent;
    }

    /** Keep the subscription table healthy after one delivery attempt. */
    public static function handleResult($db, int $subId, array $result): void {
        if (!empty($result['gone'])) {
            $db->query("DELETE FROM push_subscriptions WHERE id = {$subId}");
        } elseif ($result['status'] >= 200 && $result['status'] < 300) {
            $db->query("UPDATE push_subscriptions SET fail_count = 0, last_success_at = NOW() WHERE id = {$subId}");
        } else {
            $db->query("UPDATE push_subscriptions SET fail_count = fail_count + 1 WHERE id = {$subId}");
            // A device that keeps failing for weeks is dead; stop trying it.
            $db->query("DELETE FROM push_subscriptions WHERE id = {$subId} AND fail_count >= 50");
        }
    }

    /** VAPID key pair (created once) and subject. */
    public static function vapid($db): array {
        $read = function () use ($db) {
            $res = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('vapid_public', 'vapid_private_pem', 'vapid_subject')");
            $out = [];
            foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $r) {
                $out[$r['setting_key']] = $r['setting_value'];
            }
            return $out;
        };
        $s = $read();
        if (empty($s['vapid_public']) || empty($s['vapid_private_pem'])) {
            $keys = WebPush::generateVapidKeys();
            // INSERT IGNORE: two concurrent first calls keep one consistent pair.
            $stmt = $db->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('vapid_private_pem', ?), ('vapid_public', ?), ('vapid_subject', 'mailto:admin@localhost')");
            $stmt->bind_param('ss', $keys['private_pem'], $keys['public']);
            $stmt->execute();
            $stmt->close();
            $s = $read();
        }
        return [
            'public' => $s['vapid_public'],
            'private_pem' => $s['vapid_private_pem'],
            'subject' => $s['vapid_subject'] ?? 'mailto:admin@localhost',
        ];
    }
}
