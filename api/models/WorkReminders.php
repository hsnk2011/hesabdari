<?php
// /api/models/WorkReminders.php
// Scheduled reminders for میز کار: due project tasks (daily after 08:00, like
// mz7 task_due_reminder) and meetings 30 minutes before they start.
require_once __DIR__ . '/TeamMember.php';
require_once __DIR__ . '/../core/CrmNotifier.php';

class WorkReminders {
    const HOUR = 8;
    const MEETING_LEAD_MINUTES = 30;
    const KIND_TEXT = ['tomorrow' => 'فردا موعد انجام است', 'today' => 'امروز موعد انجام است', 'overdue' => 'از موعد آن گذشته است'];

    /** tomorrow | today | overdue (up to 7 days) | null */
    public static function dueKind(string $due, string $today): ?string {
        $diff = (int)round((strtotime($due) - strtotime($today)) / 86400);
        if ($diff === 1) {
            return 'tomorrow';
        }
        if ($diff === 0) {
            return 'today';
        }
        return ($diff < 0 && $diff >= -7) ? 'overdue' : null;
    }

    /** Remind assignees of open tasks due tomorrow / today / recently overdue; once per task per day. */
    public static function dailyTasks($db, ?string $now = null): int {
        $now = $now ?: date('Y-m-d H:i:s');
        if ((int)date('G', strtotime($now)) < self::HOUR) {
            return 0;
        }
        $today = substr($now, 0, 10);
        $from = date('Y-m-d', strtotime($today . ' -7 days'));
        $to = date('Y-m-d', strtotime($today . ' +1 day'));
        $res = $db->query("SELECT t.id, t.title, t.due_date, t.assignee_user_id, t.project_id, p.entity_id, p.title AS project_title
            FROM project_tasks t JOIN projects p ON p.id = t.project_id
            WHERE t.status IN ('todo','in_progress') AND t.assignee_user_id IS NOT NULL
              AND p.status IN ('planning','active')
              AND t.due_date BETWEEN '{$from}' AND '{$to}'
              AND (t.reminded_on IS NULL OR t.reminded_on < '{$today}')");
        $sent = 0;
        $teams = [];
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $t) {
            $kind = self::dueKind($t['due_date'], $today);
            $e = (int)$t['entity_id'];
            $teams[$e] = $teams[$e] ?? TeamMember::userIdsForEntity($db, $e);
            if (!$kind || !in_array((int)$t['assignee_user_id'], $teams[$e], true)) {
                continue;
            }
            // Stamp first so a slow push never causes a second reminder.
            $db->query("UPDATE project_tasks SET reminded_on = '{$today}' WHERE id = " . (int)$t['id'] . " AND (reminded_on IS NULL OR reminded_on < '{$today}')");
            if ($db->affected_rows < 1) {
                continue;
            }
            CrmNotifier::notify($db, $e, (int)$t['assignee_user_id'], 'work_task', 'یادآوری کار: ' . self::KIND_TEXT[$kind],
                mb_substr($t['title'], 0, 140) . ' — ' . $t['project_title'], "./index.php#work/project/{$t['project_id']}/task/{$t['id']}", 'project_task', (int)$t['id']);
            $sent++;
        }
        return $sent;
    }

    /** Meetings starting within the next 30 minutes → attendees and creator, once. */
    public static function meetings($db, ?string $now = null): int {
        $now = $now ?: date('Y-m-d H:i:s');
        $until = date('Y-m-d H:i:s', strtotime($now) + self::MEETING_LEAD_MINUTES * 60);
        $res = $db->query("SELECT * FROM meetings WHERE reminded_at IS NULL AND starts_at BETWEEN '{$now}' AND '{$until}'");
        $sent = 0;
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $m) {
            $id = (int)$m['id'];
            $db->query("UPDATE meetings SET reminded_at = NOW() WHERE id = {$id} AND reminded_at IS NULL");
            if ($db->affected_rows < 1) {
                continue;
            }
            $e = (int)$m['entity_id'];
            $team = TeamMember::userIdsForEntity($db, $e);
            $r = $db->query("SELECT user_id FROM meeting_attendees WHERE meeting_id = {$id}");
            $people = array_map('intval', array_column($r ? $r->fetch_all(MYSQLI_ASSOC) : [], 'user_id'));
            $people[] = (int)$m['created_by'];
            foreach (array_unique($people) as $to) {
                if (!in_array($to, $team, true)) {
                    continue;
                }
                CrmNotifier::notify($db, $e, $to, 'work_meeting', "جلسه «{$m['title']}» ساعت " . substr($m['starts_at'], 11, 5) . ' شروع می‌شود',
                    $m['location'] ? 'محل: ' . $m['location'] : 'یادآوری جلسه', "./index.php#work/meeting/{$id}", 'meeting', $id);
                $sent++;
            }
        }
        return $sent;
    }
}
