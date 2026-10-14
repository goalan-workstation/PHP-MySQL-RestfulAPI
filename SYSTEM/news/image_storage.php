<?php
declare(strict_types=1);

const NEWS_MAX_IMAGE_BYTES = 5242880;
const NEWS_MAX_IMAGE_PIXELS = 16000000;

function newsImageDirectory(): string
{
    $directory = __DIR__ . '/img';

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('無法建立消息圖片資料夾。');
    }

    return $directory;
}

function newsImageExtension(string $mime): string
{
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => throw new RuntimeException('圖片格式不支援，請上傳 JPG、PNG 或 WebP。'),
    };
}

function newsCreateImage(string $path, string $mime)
{
    $image = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($path),
        'image/png' => imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
        default => false,
    };

    if ($image === false) {
        throw new RuntimeException('無法讀取圖片，請確認檔案沒有損毀。');
    }

    return $image;
}

function newsResizeImage($image, int $width, int $height, string $mime)
{
    $resized = imagecreatetruecolor($width, $height);
    if ($resized === false) {
        throw new RuntimeException('無法配置圖片處理記憶體。');
    }

    if ($mime === 'image/jpeg') {
        $background = imagecolorallocate($resized, 255, 255, 255);
        imagefilledrectangle($resized, 0, 0, $width, $height, $background);
    } else {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $width, $height, $transparent);
    }

    if (!imagecopyresampled(
        $resized,
        $image,
        0,
        0,
        0,
        0,
        $width,
        $height,
        imagesx($image),
        imagesy($image)
    )) {
        imagedestroy($resized);
        throw new RuntimeException('圖片縮放失敗。');
    }

    return $resized;
}

function newsWriteImage($image, string $path, string $mime, int $quality): bool
{
    return match ($mime) {
        'image/jpeg' => imagejpeg($image, $path, $quality),
        'image/png' => imagepng($image, $path, 9),
        'image/webp' => function_exists('imagewebp') && imagewebp($image, $path, $quality),
        default => false,
    };
}

function newsPrepareOriginal(string $sourcePath, string $targetPath, string $mime, int $width, int $height): void
{
    if (filesize($sourcePath) <= NEWS_MAX_IMAGE_BYTES) {
        if (!copy($sourcePath, $targetPath)) {
            throw new RuntimeException('無法暫存上傳圖片。');
        }

        return;
    }

    $source = newsCreateImage($sourcePath, $mime);
    $scale = 1.0;

    try {
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $targetWidth = max(1, (int) floor($width * $scale));
            $targetHeight = max(1, (int) floor($height * $scale));
            $image = $attempt === 0
                ? $source
                : newsResizeImage($source, $targetWidth, $targetHeight, $mime);

            try {
                foreach ($mime === 'image/png' ? [100] : [85, 75, 65, 55, 45, 35] as $quality) {
                    if (!newsWriteImage($image, $targetPath, $mime, $quality)) {
                        throw new RuntimeException('圖片壓縮失敗。');
                    }
                    clearstatcache(true, $targetPath);
                    if (filesize($targetPath) <= NEWS_MAX_IMAGE_BYTES) {
                        return;
                    }
                }
            } finally {
                if ($image !== $source) {
                    imagedestroy($image);
                }
            }

            $scale *= 0.85;
        }
    } finally {
        imagedestroy($source);
    }

    if (is_file($targetPath)) {
        unlink($targetPath);
    }
    throw new RuntimeException('圖片壓縮後仍超過 5 MB，請縮小圖片後再上傳。');
}

function newsPrepareThumbnail(
    string $sourcePath,
    string $targetPath,
    string $mime,
    int $width,
    int $height
): void {
    $source = newsCreateImage($sourcePath, $mime);
    $scale = min(1.0, 400 / max($width, $height));
    $thumbnail = newsResizeImage(
        $source,
        max(1, (int) round($width * $scale)),
        max(1, (int) round($height * $scale)),
        $mime
    );

    try {
        if (!newsWriteImage($thumbnail, $targetPath, $mime, 82)) {
            throw new RuntimeException('縮圖產生失敗。');
        }
    } finally {
        imagedestroy($thumbnail);
        imagedestroy($source);
    }
}

function newsStageUpload(array $file, ?string $existingPath): array
{
    if (!extension_loaded('gd')) {
        throw new RuntimeException('圖片處理功能尚未啟用，請啟用 PHP GD 並重新啟動 Apache。');
    }

    if (!isset($file['error'], $file['tmp_name'], $file['size']) || !is_int($file['error'])) {
        throw new RuntimeException('上傳圖片資料無效。');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '圖片超過伺服器允許的上傳大小。',
            UPLOAD_ERR_PARTIAL => '圖片未完整上傳，請重新選擇。',
            UPLOAD_ERR_NO_FILE => '請選擇要上傳的消息圖片。',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => '伺服器暫存圖片失敗。',
            UPLOAD_ERR_EXTENSION => '圖片上傳被伺服器擴充功能中止。',
            default => '圖片上傳失敗。',
        });
    }
    if (!is_uploaded_file($file['tmp_name']) || $file['size'] <= 0) {
        throw new RuntimeException('上傳圖片無效。');
    }

    $info = getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new RuntimeException('檔案不是有效圖片。');
    }

    $mime = $info['mime'] ?? '';
    $extension = newsImageExtension($mime);
    if ($mime === 'image/webp' && !function_exists('imagecreatefromwebp')) {
        throw new RuntimeException('目前伺服器的 GD 不支援 WebP 圖片。');
    }

    $width = (int) $info[0];
    $height = (int) $info[1];
    if ($width < 1 || $height < 1 || $width * $height > NEWS_MAX_IMAGE_PIXELS) {
        throw new RuntimeException('圖片尺寸過大，請先縮小後再上傳。');
    }

    $directory = newsImageDirectory();
    $stem = null;
    if (is_string($existingPath)
        && preg_match('~^img/([a-f0-9]{32})\.(jpg|png|webp)$~', $existingPath, $matches)
    ) {
        $stem = $matches[1];
    }
    $stem ??= bin2hex(random_bytes(16));

    $originalPath = 'img/' . $stem . '.' . $extension;
    $thumbnailPath = 'img/' . $stem . '_thumb.' . $extension;
    $stageOriginal = $directory . '/.stage_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $stageThumbnail = $directory . '/.stage_' . bin2hex(random_bytes(16)) . '.' . $extension;

    try {
        newsPrepareOriginal($file['tmp_name'], $stageOriginal, $mime, $width, $height);
        newsPrepareThumbnail($file['tmp_name'], $stageThumbnail, $mime, $width, $height);
    } catch (Throwable $error) {
        if (is_file($stageOriginal)) {
            unlink($stageOriginal);
        }
        if (is_file($stageThumbnail)) {
            unlink($stageThumbnail);
        }
        throw $error;
    }

    return [
        'original' => $originalPath,
        'thumbnail' => $thumbnailPath,
        'stage_original' => $stageOriginal,
        'stage_thumbnail' => $stageThumbnail,
    ];
}

function newsManagedImagePath(?string $path): ?string
{
    if (!is_string($path)
        || !preg_match('~^img/[a-f0-9]{32}(?:_thumb)?\.(jpg|png|webp)$~', $path)
    ) {
        return null;
    }

    return __DIR__ . '/' . $path;
}

function newsThumbnailRelativePath(?string $path): ?string
{
    if (!is_string($path)
        || !preg_match('~^img/([a-f0-9]{32})\.(jpg|png|webp)$~', $path, $matches)
    ) {
        return null;
    }

    return 'img/' . $matches[1] . '_thumb.' . $matches[2];
}

function newsRemoveStoredImage(?string $path): void
{
    $paths = [$path, newsThumbnailRelativePath($path)];
    foreach ($paths as $storedPath) {
        $absolutePath = newsManagedImagePath($storedPath);
        if ($absolutePath !== null && is_file($absolutePath) && !unlink($absolutePath)) {
            throw new RuntimeException('無法刪除消息圖片檔案。');
        }
    }
}

function newsPromoteImages(array $staged, ?string $oldPath, callable $save): void
{
    $targets = [
        $staged['stage_original'] => __DIR__ . '/' . $staged['original'],
        $staged['stage_thumbnail'] => __DIR__ . '/' . $staged['thumbnail'],
    ];
    $backups = [];
    $promoted = [];

    try {
        foreach ($targets as $stage => $target) {
            if (is_file($target)) {
                $backup = $target . '.backup_' . bin2hex(random_bytes(8));
                if (!rename($target, $backup)) {
                    throw new RuntimeException('無法替換舊消息圖片。');
                }
                $backups[$target] = $backup;
            }
            if (!rename($stage, $target)) {
                throw new RuntimeException('無法儲存消息圖片。');
            }
            $promoted[] = $target;
        }

        $save();
    } catch (Throwable $error) {
        foreach ($promoted as $target) {
            if (is_file($target)) {
                unlink($target);
            }
        }
        foreach ($backups as $target => $backup) {
            if (is_file($backup)) {
                rename($backup, $target);
            }
        }
        foreach (array_keys($targets) as $stage) {
            if (is_file($stage)) {
                unlink($stage);
            }
        }
        throw $error;
    }

    foreach ($backups as $backup) {
        if (is_file($backup) && !unlink($backup)) {
            throw new RuntimeException('已儲存消息，但無法清理舊圖片暫存檔。');
        }
    }

    $oldAbsolutePath = newsManagedImagePath($oldPath);
    if ($oldAbsolutePath !== null && !in_array($oldAbsolutePath, $promoted, true)) {
        newsRemoveStoredImage($oldPath);
    }
}
