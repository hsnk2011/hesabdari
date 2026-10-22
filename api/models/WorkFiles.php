<?php
// /api/models/WorkFiles.php
// Files of projects, project reports and meetings (میز کار). Stored under
// storage/work/ (denied to the web by .htaccess and the nginx rule) with random
// names; served only through api.php?action=work_file after an access check.
// The type is decided from the file's content, never from its name.

class WorkFiles {
    const MAX_FILES = 10;
    const MAX_BYTES = 25 * 1024 * 1024;
    const MAX_VIDEO_BYTES = 100 * 1024 * 1024;

    /** Detected MIME → [extension, kind]. Office files arrive as zip and are told apart by extension. */
    const TYPES = [
        'image/jpeg' => ['jpg', 'image'], 'image/png' => ['png', 'image'], 'image/webp' => ['webp', 'image'], 'image/gif' => ['gif', 'image'],
        'application/pdf' => ['pdf', 'pdf'],
        'audio/mpeg' => ['mp3', 'audio'], 'audio/mp4' => ['m4a', 'audio'], 'audio/x-m4a' => ['m4a', 'audio'],
        'audio/ogg' => ['ogg', 'audio'], 'application/ogg' => ['ogg', 'audio'], 'audio/webm' => ['webm', 'audio'],
        'video/mp4' => ['mp4', 'video'], 'video/quicktime' => ['mov', 'video'], 'video/webm' => ['webm', 'video'],
    ];
    const OFFICE = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public static function root(): string {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'work';
    }

    public static function path(array $row): string {
        return self::root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['stored_name']);
    }

    /** ['mime', 'ext', 'kind'] or null when the content is not an allowed type. */
    public static function detect(string $path, string $name): ?array {
        if (!is_file($path)) {
            return null;
        }
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($path);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (isset(self::TYPES[$mime])) {
            [$e, $kind] = self::TYPES[$mime];
            // An m4a/mp4 audio file is often reported as video/mp4.
            if ($mime === 'video/mp4' && in_array($ext, ['m4a', 'aac'], true)) {
                return ['mime' => 'audio/mp4', 'ext' => 'm4a', 'kind' => 'audio'];
            }
            return ['mime' => $mime, 'ext' => $e, 'kind' => $kind];
        }
        if (in_array($mime, self::OFFICE, true)) {
            return ['mime' => $mime, 'ext' => array_search($mime, self::OFFICE, true), 'kind' => 'office'];
        }
        if ($mime === 'application/zip' || $mime === 'application/x-zip-compressed') {
            if (isset(self::OFFICE[$ext])) {
                return ['mime' => self::OFFICE[$ext], 'ext' => $ext, 'kind' => 'office'];
            }
            return ['mime' => 'application/zip', 'ext' => 'zip', 'kind' => 'zip'];
        }
        return null;
    }

    private static function iniBytes(string $key): int {
        $v = trim((string)ini_get($key));
        if ($v === '' || $v === '0' || $v === '-1') {
            return PHP_INT_MAX;
        }
        $n = (int)$v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024;
            // no break
            case 'm': $n *= 1024;
            // no break
            case 'k': $n *= 1024;
        }
        return $n;
    }

    /** Largest single upload this server accepts (for the UI and error text). */
    public static function uploadLimitBytes(): int {
        return min(self::MAX_VIDEO_BYTES, self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size'));
    }

    /** PHP's $_FILES['file'] (single or multiple) → list of [name, tmp_name, size, error]. */
    public static function normalize($field): array {
        if (!is_array($field) || !isset($field['name'])) {
            return [];
        }
        if (!is_array($field['name'])) {
            return [$field];
        }
        $out = [];
        foreach ($field['name'] as $i => $name) {
            $out[] = ['name' => $name, 'tmp_name' => $field['tmp_name'][$i] ?? '', 'size' => (int)($field['size'][$i] ?? 0), 'error' => (int)($field['error'][$i] ?? UPLOAD_ERR_NO_FILE)];
        }
        return $out;
    }

    /**
     * Validate every file first, then move them; all or nothing.
     * $meta: comment_id, title, description. $fromUpload=false only for tests/CLI.
     */
    public static function store($db, int $entityId, string $ownerType, int $ownerId, int $userId, array $files, array $meta = [], bool $fromUpload = true): array {
        $files = array_values(array_filter($files, fn($f) => (int)($f['error'] ?? 0) !== UPLOAD_ERR_NO_FILE));
        if (!$files) {
            return ['error' => 'فایلی انتخاب نشده است.'];
        }
        if (count($files) > self::MAX_FILES) {
            return ['error' => 'حداکثر ۱۰ فایل در هر بار.'];
        }
        $checked = [];
        foreach ($files as $f) {
            $name = mb_substr(basename(str_replace('\\', '/', (string)$f['name'])), 0, 255) ?: 'file';
            $err = (int)($f['error'] ?? UPLOAD_ERR_OK);
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || ($err === UPLOAD_ERR_OK && empty($f['tmp_name']))) {
                return ['error' => 'فایلی دریافت نشد؛ شاید حجم آن از سقف سرور (' . (int)floor(self::uploadLimitBytes() / 1048576) . ' مگابایت) بیشتر است.'];
            }
            if ($err !== UPLOAD_ERR_OK || ($fromUpload && !is_uploaded_file($f['tmp_name']))) {
                return ['error' => "ارسال فایل «{$name}» ناقص ماند؛ دوباره تلاش کنید."];
            }
            $type = self::detect($f['tmp_name'], $name);
            if ($type === null) {
                return ['error' => "نوع فایل «{$name}» مجاز نیست."];
            }
            $size = (int)filesize($f['tmp_name']);
            if ($size > ($type['kind'] === 'video' ? self::MAX_VIDEO_BYTES : self::MAX_BYTES)) {
                return ['error' => "حجم فایل «{$name}» بیش از حد مجاز است."];
            }
            $checked[] = $f + ['clean_name' => $name, 'type' => $type, 'real_size' => $size];
        }

        $dir = $entityId . '/' . date('Y');
        $absDir = self::root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            return ['error' => 'پوشه ذخیره فایل قابل ساختن نیست.'];
        }
        $moved = [];
        $ids = [];
        $single = count($checked) === 1;
        $commentId = isset($meta['comment_id']) ? (int)$meta['comment_id'] : null;
        $desc = trim((string)($meta['description'] ?? '')) ?: null;
        try {
            foreach ($checked as $f) {
                $stored = $dir . '/' . bin2hex(random_bytes(16)) . '.' . $f['type']['ext'];
                $abs = self::root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $stored);
                $ok = $fromUpload ? move_uploaded_file($f['tmp_name'], $abs) : copy($f['tmp_name'], $abs);
                if (!$ok) {
                    throw new RuntimeException("ذخیره فایل «{$f['clean_name']}» ناموفق بود.");
                }
                $moved[] = $abs;
                $title = ($single && trim((string)($meta['title'] ?? '')) !== '') ? mb_substr(trim($meta['title']), 0, 200) : mb_substr(preg_replace('/\.[^.]+$/', '', $f['clean_name']), 0, 200);
                $stmt = $db->prepare("INSERT INTO work_files (entity_id, owner_type, owner_id, comment_id, title, description, file_name, stored_name, size, mime, kind, uploaded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('isiissssissi', $entityId, $ownerType, $ownerId, $commentId, $title, $desc, $f['clean_name'], $stored, $f['real_size'], $f['type']['mime'], $f['type']['kind'], $userId);
                $stmt->execute();
                $ids[] = (int)$db->insert_id;
            }
        } catch (Throwable $e) {
            foreach ($moved as $abs) {
                @unlink($abs);
            }
            if ($ids) {
                $db->query("DELETE FROM work_files WHERE id IN (" . implode(',', $ids) . ")");
            }
            return ['error' => $e instanceof RuntimeException ? $e->getMessage() : 'ذخیره فایل ناموفق بود.'];
        }
        return ['success' => true, 'ids' => $ids];
    }

    public static function listFor($db, int $entityId, string $ownerType, int $ownerId): array {
        $stmt = $db->prepare("SELECT f.*, tm.full_name AS uploader_name FROM work_files f
            LEFT JOIN team_members tm ON tm.entity_id = f.entity_id AND tm.user_id = f.uploaded_by
            WHERE f.entity_id = ? AND f.owner_type = ? AND f.owner_id = ? ORDER BY f.created_at DESC, f.id DESC");
        $stmt->bind_param('isi', $entityId, $ownerType, $ownerId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    public static function get($db, int $entityId, int $id): ?array {
        $stmt = $db->prepare("SELECT * FROM work_files WHERE id = ? AND entity_id = ?");
        $stmt->bind_param('ii', $id, $entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt)[0] ?? null;
    }

    /** Delete rows and their files on disk. */
    public static function deleteRows($db, array $rows): void {
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int)$r['id'];
            @unlink(self::path($r));
        }
        if ($ids) {
            $db->query("DELETE FROM work_files WHERE id IN (" . implode(',', $ids) . ")");
        }
    }

    /** Send the file (Range for video/audio). Images, PDF, video and audio inline; the rest as a download. */
    public static function stream(array $row): void {
        $path = self::path($row);
        if (!is_file($path)) {
            http_response_code(404);
            exit('فایل یافت نشد.');
        }
        $size = (int)filesize($path);
        $inline = in_array($row['kind'], ['image', 'pdf', 'video', 'audio'], true);
        $name = rawurlencode($row['file_name']);
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: ' . $row['mime']);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'");
        header('Cache-Control: private, max-age=3600');
        header('Accept-Ranges: bytes');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''{$name}");
        $start = 0;
        $end = $size - 1;
        if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $start = max(0, $size - (int)$m[2]);
            } else {
                $start = (int)$m[1];
                if ($m[2] !== '') {
                    $end = min($end, (int)$m[2]);
                }
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */{$size}");
                exit;
            }
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }
        header('Content-Length: ' . ($end - $start + 1));
        $fh = fopen($path, 'rb');
        fseek($fh, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fh)) {
            $chunk = fread($fh, (int)min(65536, $left));
            echo $chunk;
            $left -= strlen($chunk);
            flush();
        }
        fclose($fh);
        exit;
    }
}
