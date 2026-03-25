<?php

declare(strict_types=1);

final class MediaLibrary
{
    private string $uploadDir;
    private string $publicPath;
    private int $maxSize;

    private const ALLOWED_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'application/pdf' => 'pdf',
    ];

    private const THUMB_WIDTH = 300;
    private const THUMB_HEIGHT = 300;

    public function __construct(
        private PDO $pdo,
        string $dataDir,
        string $baseUrl = '',
    ) {
        $this->uploadDir = rtrim($dataDir, '/') . '/uploads';
        $this->publicPath = rtrim($baseUrl, '/') . '/uploads';
        $this->maxSize = (int)(env_value('MAX_UPLOAD_MB', '10') ?? '10') * 1024 * 1024;

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
        if (!is_dir($this->uploadDir . '/thumbs')) {
            mkdir($this->uploadDir . '/thumbs', 0755, true);
        }
    }

    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM media ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => $this->enrichRow($r), $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM media WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->enrichRow($row) : null;
    }

    /**
     * Handle file upload from $_FILES or from a raw file path.
     */
    public function upload(array $file, string $altText = '', string $tags = ''): array|string
    {
        // $file should have: tmp_name, name, size, type, error
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return 'Upload failed with error code ' . ($file['error'] ?? 'unknown');
        }

        $size = (int)($file['size'] ?? 0);
        if ($size > $this->maxSize) {
            return 'File exceeds maximum size of ' . ($this->maxSize / 1024 / 1024) . ' MB';
        }

        $mime = $file['type'] ?? '';
        // Always verify MIME type with finfo to prevent spoofing
        if (!empty($file['tmp_name'])) {
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $detectedMime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                if ($detectedMime) {
                    $mime = $detectedMime;
                }
            } elseif (function_exists('mime_content_type')) {
                $detectedMime = mime_content_type($file['tmp_name']);
                if ($detectedMime) {
                    $mime = $detectedMime;
                }
            }
        }

        if (!isset(self::ALLOWED_TYPES[$mime])) {
            return 'File type not allowed: ' . $mime;
        }

        $ext = self::ALLOWED_TYPES[$mime];
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $this->uploadDir . '/' . $filename;

        if (!empty($file['tmp_name'])) {
            if (is_uploaded_file($file['tmp_name'])) {
                move_uploaded_file($file['tmp_name'], $destPath);
            } else {
                // For programmatic uploads, verify source is within uploads or temp dir
                $realSrc = realpath($file['tmp_name']);
                $tmpDir = sys_get_temp_dir();
                if (!$realSrc || (!str_starts_with($realSrc, $tmpDir) && !str_starts_with($realSrc, $this->uploadDir))) {
                    return 'Invalid file source path';
                }
                copy($file['tmp_name'], $destPath);
            }
        } else {
            return 'No file data provided';
        }

        // generate thumbnail for images
        if (str_starts_with($mime, 'image/') && $mime !== 'image/gif') {
            $this->createThumbnail($destPath, $this->uploadDir . '/thumbs/' . $filename);
        }

        $stmt = $this->pdo->prepare('INSERT INTO media(filename, original_name, mime_type, size_bytes, alt_text, tags, created_at) VALUES(:f,:o,:m,:s,:a,:t,:c)');
        $stmt->execute([
            ':f' => $filename,
            ':o' => $file['name'] ?? $filename,
            ':m' => $mime,
            ':s' => $size,
            ':a' => $altText,
            ':t' => $tags,
            ':c' => gmdate(DATE_ATOM),
        ]);

        return $this->find((int)$this->pdo->lastInsertId());
    }

    public function delete(int $id): bool
    {
        $item = $this->find($id);
        if (!$item) {
            return false;
        }

        $filePath = $this->uploadDir . '/' . $item['filename'];
        $thumbPath = $this->uploadDir . '/thumbs/' . $item['filename'];

        if (is_file($filePath)) {
            unlink($filePath);
        }
        if (is_file($thumbPath)) {
            unlink($thumbPath);
        }

        $stmt = $this->pdo->prepare('DELETE FROM media WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function getFilePath(int $id): ?string
    {
        $item = $this->find($id);
        if (!$item) {
            return null;
        }
        $path = $this->uploadDir . '/' . $item['filename'];
        return is_file($path) ? $path : null;
    }

    private function enrichRow(array $row): array
    {
        $row['url'] = $this->publicPath . '/' . $row['filename'];
        $thumbFile = $this->uploadDir . '/thumbs/' . $row['filename'];
        $row['thumb_url'] = is_file($thumbFile) ? $this->publicPath . '/thumbs/' . $row['filename'] : $row['url'];
        return $row;
    }

    private function createThumbnail(string $source, string $dest): void
    {
        if (!extension_loaded('gd')) {
            // GD not available, skip thumbnail
            copy($source, $dest);
            return;
        }

        $info = getimagesize($source);
        if (!$info) {
            return;
        }

        [$origW, $origH, $type] = $info;

        $srcImage = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($source),
            IMAGETYPE_PNG => imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($source) : null,
            default => null,
        };

        if (!$srcImage) {
            copy($source, $dest);
            return;
        }

        // calculate crop dimensions (center crop to square, then resize)
        $size = min($origW, $origH);
        $srcX = (int)(($origW - $size) / 2);
        $srcY = (int)(($origH - $size) / 2);

        $thumb = imagecreatetruecolor(self::THUMB_WIDTH, self::THUMB_HEIGHT);

        // preserve transparency for PNG/WebP
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        imagecopyresampled($thumb, $srcImage, 0, 0, $srcX, $srcY, self::THUMB_WIDTH, self::THUMB_HEIGHT, $size, $size);

        match ($type) {
            IMAGETYPE_PNG => imagepng($thumb, $dest, 8),
            IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($thumb, $dest, 80) : imagejpeg($thumb, $dest, 85),
            default => imagejpeg($thumb, $dest, 85),
        };

        imagedestroy($srcImage);
        imagedestroy($thumb);
    }
}
