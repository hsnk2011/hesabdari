<?php
/**
 * میز کار project content: reports with @mentions and files, documents,
 * budget = linked expenses. Run: php tests/work_content_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/TeamMember.php';
require_once $root . '/api/models/WorkProject.php';
require_once $root . '/api/models/Expense.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$e = 1;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION = ['current_entity_id' => $e, 'allowed_entities' => [$e], 'user_id' => 1, 'username' => 'work-test'];
$token = 'wc' . substr(bin2hex(random_bytes(4)), 0, 6);
$one = fn(string $sql) => ($r = $conn->query($sql)) ? $r->fetch_assoc() : null;
$u = []; $pid = 0; $otherPid = 0; $accountId = 0; $expenseIds = [];
$png = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $token . '.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
$file = fn() => ['name' => 'عکس.png', 'tmp_name' => $png, 'size' => filesize($png), 'error' => 0];

try {
    $team = new TeamMember($conn);
    foreach (['a', 'b', 'c'] as $s) {
        $conn->query("INSERT INTO users (username, password_hash) VALUES ('{$token}{$s}', 'x')");
        $u[$s] = (int)$conn->insert_id;
        $team->save($e, ['full_name' => "{$token} {$s}", 'kind' => 'employee', 'user_id' => $u[$s]]);
    }
    $wp = new WorkProject($conn);
    $pid = (int)$wp->create($e, $u['a'], true, ['title' => "{$token} پروژه", 'budget' => 1000000, 'members' => [['user_id' => $u['b']], ['user_id' => $u['c']]]])['id'];
    $notes = fn(int $user, string $like) => (int)($one("SELECT COUNT(*) n FROM notifications WHERE user_id = {$user} AND type = 'work_project' AND title LIKE '" . $conn->real_escape_string($like) . "'")['n'] ?? 0);

    // Report with a mention and a file
    $r = $wp->addComment($e, $u['a'], false, $pid, "طرح آماده شد @[{$token} b](user:{$u['b']}) لطفاً ببین", [$file()], false);
    expect_true(!empty($r['success']), 'report saved' . (!empty($r['error']) ? ' — ' . $r['error'] : ''));
    $cid = (int)($r['id'] ?? 0);
    expect_true(($one("SELECT author_user_id a FROM project_comments WHERE id = {$cid}")['a'] ?? 0) == $u['a'], 'returned id is the report row');
    expect_true($notes($u['b'], '%شما را در گزارش%') === 1 && $notes($u['b'], 'گزارش جدید%') === 0, 'mentioned B gets the mention notice only');
    expect_true($notes($u['c'], 'گزارش جدید%') === 1, 'C gets «گزارش جدید»');
    expect_true((int)$one("SELECT COUNT(*) n FROM work_files WHERE owner_type = 'project' AND owner_id = {$pid} AND comment_id = {$cid}")['n'] === 1, 'report file is stored with the report id');
    expect_true(!empty($wp->addComment($e, $u['a'], false, $pid, '   ', [], false)['error']), 'empty report refused');
    expect_true(($wp->deleteComment($e, $u['c'], false, $cid, true)['statusCode'] ?? 0) === 403, 'another member cannot delete the report');
    expect_true(!empty($wp->editComment($e, $u['a'], false, $cid, 'متن ویرایش‌شده')['success']) && $one("SELECT edited_at FROM project_comments WHERE id = {$cid}")['edited_at'] !== null, 'author edits the report');
    $path = WorkFiles::path(WorkFiles::listFor($conn, $e, 'project', $pid)[0]);
    expect_true(!empty($wp->deleteComment($e, $u['a'], false, $cid, true)['success']) && !is_file($path), 'deleting with files removes the file from disk');

    // Documents
    $d = $wp->addDocuments($e, $u['b'], false, $pid, [$file()], 'قرارداد', 'نسخه امضاشده', false);
    expect_true(!empty($d['success']) && ($one("SELECT title FROM work_files WHERE id = " . (int)$d['ids'][0])['title'] ?? '') === 'قرارداد', 'document stored with its title');
    expect_true(($wp->deleteDocument($e, $u['c'], false, (int)$d['ids'][0])['statusCode'] ?? 0) === 403, 'other member cannot delete the document');
    expect_true(!empty($wp->deleteDocument($e, $u['a'], false, (int)$d['ids'][0])['success']), 'manager deletes the document');
    expect_true(!empty($wp->addDocuments($e, $u['b'], false, 999999999, [$file()], null, null, false)['error']), 'documents need a visible project');

    // Budget from expenses
    $conn->query("INSERT INTO accounts (name, is_cash, entity_id, initial_balance, current_balance) VALUES ('{$token}-acc', 1, {$e}, 0, 0)");
    $accountId = (int)$conn->insert_id;
    $conn->query("INSERT INTO projects (entity_id, title) VALUES (" . ($e + 1000) . ", '{$token} دیگر')");
    $otherPid = (int)$conn->insert_id;
    $ex = new Expense($conn);
    foreach ([[300000, $pid], [200000, $pid], [999, $otherPid]] as [$amt, $proj]) {
        $res = $ex->save(['category' => 'متفرقه', 'date' => date('Y-m-d'), 'amount' => $amt, 'description' => $token, 'account_id' => $accountId, 'project_id' => $proj]);
        $expenseIds[] = (int)($res['id'] ?? 0);
    }
    expect_true((int)$one("SELECT project_id p FROM expenses WHERE id = {$expenseIds[0]}")['p'] === $pid, 'expense saved with its project');
    expect_true($one("SELECT project_id p FROM expenses WHERE id = {$expenseIds[2]}")['p'] === null, "another entity's project is not stored on an expense");
    $b = $wp->budget($e, $pid);
    expect_true(abs($b['spent'] - 500000) < 0.01 && abs($b['remaining'] - 500000) < 0.01 && $b['over'] === false, 'budget 1,000,000: spent 500,000, remaining 500,000');
    $ex->save(['id' => $expenseIds[1], 'category' => 'متفرقه', 'date' => date('Y-m-d'), 'amount' => 900000, 'description' => $token, 'account_id' => $accountId, 'project_id' => $pid]);
    expect_true($wp->budget($e, $pid)['over'] === true, 'over budget is flagged');
    $ex->save(['id' => $expenseIds[1], 'category' => 'متفرقه', 'date' => date('Y-m-d'), 'amount' => 900000, 'description' => $token, 'account_id' => $accountId]);
    expect_true((int)$one("SELECT project_id p FROM expenses WHERE id = {$expenseIds[1]}")['p'] === $pid, 'an edit that does not send project_id keeps the link');
} finally {
    foreach ($expenseIds as $id) { if ($id) { $conn->query("DELETE FROM expenses WHERE id = {$id}"); } }
    if ($accountId) { $conn->query("DELETE FROM accounts WHERE id = {$accountId}"); }
    if ($otherPid) { $conn->query("DELETE FROM projects WHERE id = {$otherPid}"); }
    if ($pid) {
        WorkFiles::deleteRows($conn, WorkFiles::listFor($conn, $e, 'project', $pid));
        $conn->query("DELETE FROM project_comments WHERE project_id = {$pid}");
        $conn->query("DELETE FROM project_members WHERE project_id = {$pid}");
        $conn->query("DELETE FROM projects WHERE id = {$pid}");
    }
    $conn->query("DELETE FROM notifications WHERE user_id IN (" . (implode(',', $u) ?: '0') . ")");
    $conn->query("DELETE FROM team_members WHERE full_name LIKE '{$token}%'");
    foreach ($u as $id) { $conn->query("DELETE FROM users WHERE id = {$id}"); }
    @unlink($png);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
