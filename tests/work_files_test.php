<?php
/**
 * میز کار files: content-based type detection, limits, all-or-nothing
 * storage outside uploads/, deletion from disk, notification links.
 * Run: php tests/work_files_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/api/config.php';
require_once $root . '/api/core/constants.php';
require_once $root . '/api/core/helpers.php';
require_once $root . '/api/models/WorkFiles.php';
require_once $root . '/api/core/CrmNotifier.php';

$passed = 0; $failed = 0;
function expect_true(bool $c, string $m): void { global $passed, $failed; if ($c) { $passed++; echo "[PASS] $m\n"; } else { $failed++; echo "[FAIL] $m\n"; } }

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
$conn->set_charset('utf8mb4');
db_sync_timezone($conn);
$entityId = 1;
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION = ['current_entity_id' => $entityId, 'allowed_entities' => [$entityId], 'user_id' => 1, 'username' => 'work-test'];
$token = 'wf' . substr(bin2hex(random_bytes(4)), 0, 6);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $token;
@mkdir($tmp);
$ownerId = 900000000 + random_int(1, 99999);
$mk = function (string $name, string $bytes) use ($tmp) { $p = $tmp . DIRECTORY_SEPARATOR . $name; file_put_contents($p, $bytes); return ['name' => $name, 'tmp_name' => $p, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK]; };
$countFiles = function () use ($entityId) { $d = WorkFiles::root() . DIRECTORY_SEPARATOR . $entityId; if (!is_dir($d)) { return 0; } $n = 0; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)) as $f) { $n++; } return $n; };
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

try {
    expect_true(strpos(WorkFiles::root(), 'storage') !== false && strpos(WorkFiles::root(), 'uploads') === false, 'storage root is under storage/, not uploads/');
    $d = WorkFiles::detect($mk('a.png', $png)['tmp_name'], 'a.png');
    expect_true($d !== null && $d['kind'] === 'image' && $d['ext'] === 'png', 'PNG detected as image');
    expect_true((WorkFiles::detect($mk('x.pdf', $pdf)['tmp_name'], 'x.pdf')['kind'] ?? '') === 'pdf', 'PDF detected as pdf');
    expect_true(WorkFiles::detect($mk('photo.jpg', "<?php echo 'hello'; ?>")['tmp_name'], 'photo.jpg') === null, 'PHP renamed to .jpg is refused');
    expect_true(WorkFiles::detect($mk('s.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')['tmp_name'], 's.svg') === null, 'SVG is refused');
    expect_true(WorkFiles::detect($mk('p.html', '<html><body>x</body></html>')['tmp_name'], 'p.html') === null, 'HTML is refused');

    $before = $countFiles();
    $ok = WorkFiles::store($conn, $entityId, 'project', $ownerId, 1, [$mk('logo.png', $png), $mk('doc.pdf', $pdf)], ['title' => 'لوگو'], false);
    expect_true(!empty($ok['success']) && count($ok['ids']) === 2, 'two valid files stored' . (!empty($ok['error']) ? ' — ' . $ok['error'] : ''));
    expect_true($countFiles() === $before + 2, 'two files written under storage/work/<entity>');
    $rows = WorkFiles::listFor($conn, $entityId, 'project', $ownerId);
    expect_true(count($rows) === 2 && in_array('image', array_column($rows, 'kind'), true), 'listFor returns both with kind');
    $row = WorkFiles::get($conn, $entityId, (int)$ok['ids'][0]);
    expect_true($row && is_file(WorkFiles::path($row)) && preg_match('/^[0-9a-f]{32}\.(png|pdf)$/', basename($row['stored_name'])), 'stored name is random hex with the detected extension');
    expect_true(WorkFiles::get($conn, $entityId + 999, (int)$ok['ids'][0]) === null, 'another entity cannot fetch the row');

    $before = $countFiles();
    $bad = WorkFiles::store($conn, $entityId, 'project', $ownerId, 1, [$mk('ok.png', $png), $mk('evil.jpg', '<?php echo 1;')], [], false);
    expect_true(!empty($bad['error']) && strpos($bad['error'], 'evil.jpg') !== false, 'batch with a bad file is refused and names it');
    expect_true($countFiles() === $before && count(WorkFiles::listFor($conn, $entityId, 'project', $ownerId)) === 2, 'nothing from the refused batch is kept');

    $eleven = [];
    for ($i = 0; $i < 11; $i++) { $eleven[] = $mk("p{$i}.png", $png); }
    expect_true((WorkFiles::store($conn, $entityId, 'project', $ownerId, 1, $eleven, [], false)['error'] ?? '') === 'حداکثر ۱۰ فایل در هر بار.', '11 files refused with the exact message');

    $big = $tmp . DIRECTORY_SEPARATOR . 'big.pdf';
    $h = fopen($big, 'wb'); fwrite($h, $pdf); fseek($h, 26 * 1024 * 1024); fwrite($h, "\n%%EOF\n"); fclose($h);
    $r = WorkFiles::store($conn, $entityId, 'project', $ownerId, 1, [['name' => 'big.pdf', 'tmp_name' => $big, 'size' => filesize($big), 'error' => UPLOAD_ERR_OK]], [], false);
    expect_true(!empty($r['error']) && strpos($r['error'], 'big.pdf') !== false, '26MB PDF refused');
    $e = WorkFiles::store($conn, $entityId, 'project', $ownerId, 1, [['name' => 'x.mp4', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE]], [], false);
    expect_true(!empty($e['error']) && strpos($e['error'], 'سقف سرور') !== false, 'server-size upload error gives a clear message');
    expect_true(WorkFiles::uploadLimitBytes() > 0, 'upload limit is known');

    $paths = array_map(fn($x) => WorkFiles::path($x), WorkFiles::listFor($conn, $entityId, 'project', $ownerId));
    WorkFiles::deleteRows($conn, WorkFiles::listFor($conn, $entityId, 'project', $ownerId));
    expect_true(count(WorkFiles::listFor($conn, $entityId, 'project', $ownerId)) === 0 && !array_filter($paths, 'is_file'), 'deleteRows removes rows and files');

    CrmNotifier::notify($conn, $entityId, 1, 'work_test', "{$token} لینک", 'متن', './index.php#work/project/1/task/2');
    $n = ($res = $conn->query("SELECT link FROM notifications WHERE title = '{$token} لینک'")) ? $res->fetch_assoc() : null;
    expect_true(($n['link'] ?? '') === './index.php#work/project/1/task/2', 'notification stores its in-app link');
} finally {
    WorkFiles::deleteRows($conn, WorkFiles::listFor($conn, $entityId, 'project', $ownerId));
    $conn->query("DELETE FROM notifications WHERE title LIKE '{$token}%'");
    array_map('unlink', glob($tmp . DIRECTORY_SEPARATOR . '*') ?: []);
    @rmdir($tmp);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
