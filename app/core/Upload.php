<?php
/**
 * 파일 업로드 보안 처리. DocumentRoot 밖(storage/uploads)에 랜덤 파일명으로 저장.
 * 다운로드는 Upload::send() 로 권한 검사 후 스트리밍한다 (직접 실행 불가).
 */
class Upload
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const DOC_EXT   = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'hwp', 'zip', 'txt'];

    public static function imageExts(): array { return self::IMAGE_EXT; }
    public static function docExts(): array { return array_merge(self::IMAGE_EXT, self::DOC_EXT); }

    /**
     * 업로드 파일 검증·저장. 성공 시 [stored_name, original_name, path, size, mime, ext].
     * 실패 시 RuntimeException.
     *
     * @param array  $file        $_FILES['x'] 항목
     * @param string $subdir      storage/uploads/{subdir}
     * @param array  $allowedExt  허용 확장자(소문자)
     */
    public static function save(array $file, string $subdir, array $allowedExt): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new RuntimeException('잘못된 업로드 요청입니다.');
        }
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new RuntimeException('파일 크기가 허용치를 초과했습니다.');
            case UPLOAD_ERR_NO_FILE:
                throw new RuntimeException('업로드된 파일이 없습니다.');
            default:
                throw new RuntimeException('파일 업로드에 실패했습니다.');
        }

        $max = (int) ($GLOBALS['config']['UPLOAD_MAX'] ?? 10485760);
        if ($file['size'] > $max) {
            throw new RuntimeException('파일 크기가 ' . round($max / 1048576) . 'MB 를 초과했습니다.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('유효하지 않은 업로드 경로입니다.');
        }

        $original = (string) $file['name'];
        // 이중 확장자/실행 파일 차단: 파일명 전체의 모든 점 구간을 검사
        $parts = explode('.', strtolower($original));
        array_shift($parts); // 본체 제외한 확장자 후보들
        $blacklist = ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'pht', 'cgi', 'pl', 'py', 'sh', 'exe', 'htaccess', 'js', 'html', 'htm', 'svg'];
        foreach ($parts as $p) {
            if (in_array($p, $blacklist, true)) {
                throw new RuntimeException('허용되지 않는 파일 형식입니다.');
            }
        }
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExt, true)) {
            throw new RuntimeException('허용되지 않는 확장자입니다: ' . Util::e($ext));
        }

        // MIME 재확인
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
        if (!self::mimeMatches($ext, $mime)) {
            throw new RuntimeException('파일 내용이 확장자와 일치하지 않습니다.');
        }

        $dir = UPLOAD_PATH . '/' . trim($subdir, '/');
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('저장 디렉터리를 만들 수 없습니다.');
        }
        $stored = Util::randomName(24) . '.' . $ext;
        $dest = $dir . '/' . $stored;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException('파일 저장에 실패했습니다.');
        }
        @chmod($dest, 0644);

        return [
            'stored_name'   => $stored,
            'original_name' => $original,
            'path'          => trim($subdir, '/') . '/' . $stored, // storage/uploads 기준 상대경로
            'size'          => (int) $file['size'],
            'mime'          => $mime,
            'ext'           => $ext,
        ];
    }

    private static function mimeMatches(string $ext, string $mime): bool
    {
        $map = [
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
            'gif' => ['image/gif'], 'webp' => ['image/webp'],
            'pdf' => ['application/pdf'],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
            'txt' => ['text/plain'],
            'doc' => ['application/msword', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
            'hwp' => ['application/x-hwp', 'application/haansofthwp', 'application/octet-stream'],
        ];
        if (!isset($map[$ext])) {
            return false;
        }
        return in_array($mime, $map[$ext], true);
    }

    /** project_files.id 로 권한 검사 후 파일 스트리밍. */
    public static function send(int $fileId, callable $authorize): never
    {
        $f = Db::one("SELECT * FROM project_files WHERE id = :id", [':id' => $fileId]);
        if (!$f) {
            http_response_code(404);
            exit('파일을 찾을 수 없습니다.');
        }
        if (!$authorize($f)) {
            http_response_code(403);
            exit('접근 권한이 없습니다.');
        }
        $full = UPLOAD_PATH . '/' . $f['path'];
        if (!is_file($full)) {
            http_response_code(404);
            exit('파일이 존재하지 않습니다.');
        }
        Audit::log('file_download', 'project_files', $fileId, null, null);
        header('Content-Type: ' . ($f['mime'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($full));
        header('Content-Disposition: attachment; filename="' . rawurlencode($f['original_name']) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($full);
        exit;
    }
}
