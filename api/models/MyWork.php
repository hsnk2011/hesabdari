<?php
// /api/models/MyWork.php
// «کارهای من»: one list of everything a person has to do — project tasks,
// meeting decisions and customer follow-ups — plus tasks waiting for their
// approval as a project manager and today's meetings.
require_once __DIR__ . '/TeamMember.php';

class MyWork {
    public static function forUser($db, int $e, int $uid): array {
        $out = ['awaiting_review' => [], 'overdue' => [], 'today' => [], 'upcoming' => [], 'meetings_today' => [], 'cartable' => []];
        if (!in_array($uid, TeamMember::userIdsForEntity($db, $e), true)) {
            return $out;
        }
        $today = date('Y-m-d');
        $items = [];

        // Project tasks assigned to me (not waiting for review, not done) in live projects.
        $res = $db->query("SELECT t.id, t.title, t.status, t.due_date, t.project_id, p.title AS project_title
            FROM project_tasks t JOIN projects p ON p.id = t.project_id
            JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = {$uid}
            WHERE p.entity_id = {$e} AND t.assignee_user_id = {$uid} AND t.status IN ('todo','in_progress')
              AND p.status IN ('planning','active','on_hold')");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $t) {
            $items[] = ['source' => 'project', 'id' => (int)$t['id'], 'title' => $t['title'], 'due' => $t['due_date'], 'status' => $t['status'],
                'context' => $t['project_title'], 'link' => "./index.php#work/project/{$t['project_id']}/task/{$t['id']}"];
        }

        // Open meeting decisions assigned to me that did not become a task.
        $res = $db->query("SELECT a.id, a.text, a.due_date, a.meeting_id, m.title AS meeting_title
            FROM meeting_actions a JOIN meetings m ON m.id = a.meeting_id
            WHERE m.entity_id = {$e} AND a.assignee_user_id = {$uid} AND a.done = 0 AND a.task_id IS NULL");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $a) {
            $items[] = ['source' => 'meeting', 'id' => (int)$a['id'], 'title' => $a['text'], 'due' => $a['due_date'], 'status' => 'open',
                'context' => $a['meeting_title'], 'link' => "./index.php#work/meeting/{$a['meeting_id']}/action/{$a['id']}"];
        }

        // Customer follow-ups (CRM).
        $res = $db->query("SELECT t.id, t.title, t.due_at, t.kind,
                COALESCE(c.name, l.name, s.name, ct.name) AS person_name
            FROM crm_tasks t
            LEFT JOIN customers c ON t.person_type = 'customer' AND c.id = t.person_id
            LEFT JOIN crm_leads l ON t.person_type = 'lead' AND l.id = t.person_id
            LEFT JOIN suppliers s ON t.person_type = 'supplier' AND s.id = t.person_id
            LEFT JOIN contacts ct ON t.person_type = 'contact' AND ct.id = t.person_id
            WHERE t.entity_id = {$e} AND t.assignee_user_id = {$uid} AND t.status = 'open'");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $t) {
            $items[] = ['source' => 'crm', 'id' => (int)$t['id'], 'title' => $t['title'], 'due' => $t['due_at'], 'status' => $t['kind'],
                'context' => $t['person_name'], 'link' => "./index.php#crm/task/{$t['id']}"];
        }

        foreach ($items as $it) {
            $day = $it['due'] ? substr($it['due'], 0, 10) : null;
            $bucket = $day === null ? 'upcoming' : ($day < $today ? 'overdue' : ($day === $today ? 'today' : 'upcoming'));
            $out[$bucket][] = $it;
        }
        foreach (['overdue', 'today', 'upcoming'] as $b) {
            usort($out[$b], fn($x, $y) => strcmp((string)($x['due'] ?: '9999'), (string)($y['due'] ?: '9999')));
        }

        // Tasks waiting for my approval as a project manager.
        $res = $db->query("SELECT t.id, t.title, t.project_id, t.completion_note, t.completed_at, p.title AS project_title, tm.full_name AS completed_by_name
            FROM project_tasks t JOIN projects p ON p.id = t.project_id
            JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = {$uid} AND pm.role = 'manager'
            LEFT JOIN team_members tm ON tm.entity_id = {$e} AND tm.user_id = t.completed_by
            WHERE p.entity_id = {$e} AND t.status = 'review' ORDER BY t.updated_at");
        foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $t) {
            $out['awaiting_review'][] = ['source' => 'project', 'id' => (int)$t['id'], 'title' => $t['title'], 'context' => $t['project_title'],
                'by' => $t['completed_by_name'], 'note' => $t['completion_note'], 'link' => "./index.php#work/project/{$t['project_id']}/task/{$t['id']}"];
        }

        // Today's meetings I created or attend.
        $res = $db->query("SELECT m.id, m.title, m.starts_at, m.location FROM meetings m
            WHERE m.entity_id = {$e} AND DATE(m.starts_at) = '{$today}'
              AND (m.created_by = {$uid} OR EXISTS (SELECT 1 FROM meeting_attendees x WHERE x.meeting_id = m.id AND x.user_id = {$uid}))
            ORDER BY m.starts_at");
        $out['meetings_today'] = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

        // Cartable: payments to approve or pay, letters I hold.
        require_once __DIR__ . '/WorkRequest.php';
        $wr = new WorkRequest($db);
        foreach ($wr->listFor($e, $uid, $wr->userPerms($uid), 'mine_action', null) as $r) {
            $out['cartable'][] = ['source' => 'request', 'id' => (int)$r['id'],
                'title' => ($r['kind'] === 'payment' ? 'درخواست پرداخت' : 'نامه') . " #{$r['number']}: {$r['title']}",
                'due' => $r['due_date'], 'status' => $r['status'], 'kind' => $r['kind'], 'amount' => $r['amount'] !== null ? (float)$r['amount'] : null,
                'context' => $r['created_by_name'], 'link' => "./index.php#work/request/{$r['id']}"];
        }
        return $out;
    }
}
