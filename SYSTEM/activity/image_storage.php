<?php
declare(strict_types=1);

const ACTIVITY_MAX_IMAGE_BYTES = 5242880;
const ACTIVITY_MAX_IMAGE_PIXELS = 16000000;

function activityImageDirectory(): string
{
    $directory = __DIR__ . '/img';

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('無法建立活動圖片資料夾。');
    }

    return $directory;
}

function activityImageMimeExtension(string $mime): string
{
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => throw new RuntimeException('圖片格式不支援，請上傳 JPG、PNG 或 WebP。'),
    };
}

function activityCreateImageResource(string $path, string $mime)
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

function activityWriteImage($image, string $path, string $mime, int $quality): bool
{
    return match ($mime) {
        'image/jpeg' => imagejpeg($image, $path, $quality),
        'image/png' => imagepng($image, $path, 9),
        'image/webp' => function_exists('imagewebp') && imagewebp($image, $path, $quality),
        default => false,
    };
}

function activityResizeImage($image, int $width, int $height, string $mime)
{
    $resized = imagecreatetruecolor($width, $height);
    if ($resized === false) {
        throw new RuntimeException('無法配置圖片處理記憶體。');
    }

    if ($mime !== 'image/jpeg') {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $width, $height, $transparent);
    } else {
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefilledrectangle($resized, 0, 0, $width, $height, $white);
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

function activityPrepareOriginal(string $sourcePath, string $targetPath, string $mime, int $width, int $height): void
{
    if (filesize($sourcePath) <= ACTIVITY_MAX_IMAGE_BYTES) {
        if (!copy($sourcePath, $targetPath)) {
            throw new RuntimeException('無法暫存上傳圖片。');
        }

        return;
    }

    $source = activityCreateImageResource($sourcePath, $mime);
    $scale = 1.0;

    try {
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $targetWidth = max(1, (int) floor($width * $scale));
            $targetHeight = max(1, (int) floor($height * $scale));
            $image = $attempt === 0 && $scale === 1.0
                ? $source
                : activityResizeImage($source, $targetWidth, $targetHeight, $mime);

            try {
                $qualities = $mime === 'image/png' ? [100] : [85, 75, 65, 55, 45, 35];
                foreach ($qualities as $quality) {
                    if (!activityWriteImage($image, $targetPath, $mime, $quality)) {
                        throw new RuntimeException('圖片壓縮失敗。');
                    }

                    clearstatcache(true, $targetPath);
                    if (filesize($targetPath) <= ACTIVITY_MAX_IMAGE_BYTES) {
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

function activityPrepareThumbnail(
    string $sourcePath,
    string $targetPath,
    string $mime,
    int $width,
    int $height
): void {
    $source = activityCreateImageResource($sourcePath, $mime);
    $scale = min(1.0, 400 / max($width, $height));
    $thumbWidth = max(1, (int) round($width * $scale));
    $thumbHeight = max(1, (int) round($height * $scale));
    $thumbnail = activityResizeImage($source, $thumbWidth, $thumbHeight, $mime);

    try {
        if (!activityWriteImage($thumbnail, $targetPath, $mime, 82)) {
            throw new RuntimeException('縮圖產生失敗。');
        }
    } finally {
        imagedestroy($thumbnail);
        imagedestroy($source);
    }
}

function activityStageUpload(array $file, ?string $existingOriginalPath): array
{
    if (!extension_loaded('gd')) {
        throw new RuntimeException('圖片處理功能尚未啟用，請啟用 PHP GD 並重新啟動 Apache。');
    }

    if (!isset($file['error'], $file['tmp_name'], $file['size']) || !is_int($file['error'])) {
        throw new RuntimeException('上傳圖片資料無效。');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '圖片超過伺服器允許的上傳大小。',
            UPLOAD_ERR_PARTIAL => '圖片未完整上傳，請重新選擇。',
            UPLOAD_ERR_NO_FILE => '請選擇要上傳的活動圖片。',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => '伺服器暫存圖片失敗。',
            UPLOAD_ERR_EXTENSION => '圖片上傳被伺服器擴充功能中止。',
            default => '圖片上傳失敗。',
        };
        throw new RuntimeException($message);
    }
    if (!is_uploaded_file($file['tmp_name']) || $file['size'] <= 0) {
        throw new RuntimeException('上傳圖片無效。');
    }

    $imageInfo = getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        throw new RuntimeException('檔案不是有效圖片。');
    }

    $mime = $imageInfo['mime'] ?? '';
    $extension = activityImageMimeExtension($mime);
    if ($mime === 'image/webp' && !function_exists('imagecreatefromwebp')) {
        throw new RuntimeException('目前伺服器的 GD 不支援 WebP 圖片。');
    }

    $width = (int) $imageInfo[0];
    $height = (int) $imageInfo[1];
    if ($width < 1 || $height < 1 || $width * $height > ACTIVITY_MAX_IMAGE_PIXELS) {
        throw new RuntimeException('圖片尺寸過大，請先縮小後再上傳。');
    }

    $directory = activityImageDirectory();
    $stem = null;
    if (is_string($existingOriginalPath)
        && preg_match('~^img/([a-f0-9]{32})\.(jpg|png|webp)$~', $existingOriginalPath, $matches)
    ) {
        $stem = $matches[1];
    }
    $stem ??= bin2hex(random_bytes(16));

    $originalPath = 'img/' . $stem . '.' . $extension;
    $thumbnailPath = 'img/' . $stem . '_thumb.' . $extension;
    $stageOriginal = $directory . '/.stage_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $stageThumbnail = $directory . '/.stage_' . bin2hex(random_bytes(16)) . '.' . $extension;

    try {
        activityPrepareOriginal($file['tmp_name'], $stageOriginal, $mime, $width, $height);
        activityPrepareThumbnail($file['tmp_name'], $stageThumbnail, $mime, $width, $height);
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

function activityManagedImagePath(?string $path): ?string
{
    if (!is_string($path)
        || !preg_match('~^img/[a-f0-9]{32}(?:_thumb)?\.(jpg|png|webp)$~', $path)
    ) {
        return null;
    }

    return __DIR__ . '/' . $path;
}

function activityRemoveStoredImage(?string $path): void
{
    $absolutePath = activityManagedImagePath($path);
    if ($absolutePath !== null && is_file($absolutePath) && !unlink($absolutePath)) {
        throw new RuntimeException('無法刪除活動圖片檔案。');
    }
}

function activityPromoteImages(array $staged, array $oldPaths, callable $save): void
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
                    throw new RuntimeException('無法替換舊活動圖片。');
                }
                $backups[$target] = $backup;
            }
            if (!rename($stage, $target)) {
                throw new RuntimeException('無法儲存活動圖片。');
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
            throw new RuntimeException('已儲存活動資料，但無法清理舊圖片暫存檔。');
        }
    }

    foreach ($oldPaths as $oldPath) {
        $absolutePath = activityManagedImagePath($oldPath);
        if ($absolutePath !== null && !in_array($absolutePath, $promoted, true)) {
            activityRemoveStoredImage($oldPath);
        }
    }
}
