<?php
declare(strict_types=1);

const ALBUM_MAX_IMAGE_BYTES = 5242880;
const ALBUM_MAX_IMAGE_PIXELS = 16000000;
const ALBUM_MAX_SUB_IMAGES = 10;

function albumImageDirectory(bool $subImage = false): string
{
    $directory = __DIR__ . ($subImage ? '/../album_sub/img' : '/img');
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('無法建立相簿圖片資料夾。');
    }

    return $directory;
}

function albumImageMimeExtension(string $mime): string
{
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => throw new RuntimeException('圖片格式不支援，請上傳 JPG、PNG 或 WebP。'),
    };
}

function albumImageResource(string $path, string $mime)
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

function albumWriteImage($image, string $path, string $mime, int $quality = 82): bool
{
    return match ($mime) {
        'image/jpeg' => imagejpeg($image, $path, $quality),
        'image/png' => imagepng($image, $path, 9),
        'image/webp' => function_exists('imagewebp') && imagewebp($image, $path, $quality),
        default => false,
    };
}

function albumResizeImage($image, int $width, int $height, string $mime)
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

function albumPrepareOriginal(string $sourcePath, string $targetPath, string $mime, int $width, int $height): void
{
    if (filesize($sourcePath) <= ALBUM_MAX_IMAGE_BYTES) {
        if (!copy($sourcePath, $targetPath)) {
            throw new RuntimeException('無法暫存上傳圖片。');
        }

        return;
    }

    $source = albumImageResource($sourcePath, $mime);
    $scale = 1.0;
    try {
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $targetWidth = max(1, (int) floor($width * $scale));
            $targetHeight = max(1, (int) floor($height * $scale));
            $image = $attempt === 0
                ? $source
                : albumResizeImage($source, $targetWidth, $targetHeight, $mime);

            try {
                $qualities = $mime === 'image/png' ? [100] : [85, 75, 65, 55, 45, 35];
                foreach ($qualities as $quality) {
                    if (!albumWriteImage($image, $targetPath, $mime, $quality)) {
                        throw new RuntimeException('圖片壓縮失敗。');
                    }
                    clearstatcache(true, $targetPath);
                    if (filesize($targetPath) <= ALBUM_MAX_IMAGE_BYTES) {
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

function albumStageUpload(array $file, bool $subImage = false): array
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
            UPLOAD_ERR_NO_FILE => '請選擇要上傳的圖片。',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => '伺服器暫存圖片失敗。',
            UPLOAD_ERR_EXTENSION => '圖片上傳被伺服器擴充功能中止。',
            default => '圖片上傳失敗。',
        };
        throw new RuntimeException($message);
    }
    if (!is_uploaded_file($file['tmp_name']) || $file['size'] <= 0) {
        throw new RuntimeException('上傳圖片無效。');
    }
    $info = getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new RuntimeException('檔案不是有效圖片。');
    }
    $mime = $info['mime'] ?? '';
    $extension = albumImageMimeExtension($mime);
    if ($mime === 'image/webp' && !function_exists('imagecreatefromwebp')) {
        throw new RuntimeException('目前伺服器的 GD 不支援 WebP 圖片。');
    }
    $width = (int) $info[0];
    $height = (int) $info[1];
    if ($width < 1 || $height < 1 || $width * $height > ALBUM_MAX_IMAGE_PIXELS) {
        throw new RuntimeException('圖片尺寸過大，請先縮小後再上傳。');
    }

    $directory = albumImageDirectory($subImage);
    $stem = bin2hex(random_bytes(16));
    $prefix = $subImage ? '../album_sub/' : '';
    $relative = $prefix . 'img/' . $stem . '.' . $extension;
    $thumbnailRelative = $prefix . 'img/' . $stem . '_thumb.' . $extension;
    $stageOriginal = $directory . '/.stage_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $stageThumbnail = $directory . '/.stage_' . bin2hex(random_bytes(16)) . '.' . $extension;

    try {
        albumPrepareOriginal($file['tmp_name'], $stageOriginal, $mime, $width, $height);
        $source = albumImageResource($file['tmp_name'], $mime);
        $scale = min(1.0, 400 / max($width, $height));
        $thumbWidth = max(1, (int) round($width * $scale));
        $thumbHeight = max(1, (int) round($height * $scale));
        $thumbnail = imagecreatetruecolor($thumbWidth, $thumbHeight);
        if ($thumbnail === false) {
            throw new RuntimeException('無法配置縮圖處理記憶體。');
        }
        if ($mime !== 'image/jpeg') {
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
            $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
            imagefilledrectangle($thumbnail, 0, 0, $thumbWidth, $thumbHeight, $transparent);
        }
        try {
            if (!imagecopyresampled(
                $thumbnail,
                $source,
                0,
                0,
                0,
                0,
                $thumbWidth,
                $thumbHeight,
                $width,
                $height
            ) || !albumWriteImage($thumbnail, $stageThumbnail, $mime)) {
                throw new RuntimeException('縮圖產生失敗。');
            }
        } finally {
            imagedestroy($thumbnail);
            imagedestroy($source);
        }
    } catch (Throwable $error) {
        foreach ([$stageOriginal, $stageThumbnail] as $stagePath) {
            if (is_file($stagePath)) {
                unlink($stagePath);
            }
        }
        throw $error;
    }

    return [
        'original' => $relative,
        'thumbnail' => $thumbnailRelative,
        'stage_original' => $stageOriginal,
        'stage_thumbnail' => $stageThumbnail,
        'sub_image' => $subImage,
    ];
}

function albumPromoteImage(array $staged): void
{
    $directory = albumImageDirectory((bool) $staged['sub_image']);
    $targets = [
        $staged['stage_original'] => $directory . '/' . basename($staged['original']),
        $staged['stage_thumbnail'] => $directory . '/' . basename($staged['thumbnail']),
    ];
    $promoted = [];
    try {
        foreach ($targets as $stage => $target) {
            if (!rename($stage, $target)) {
                throw new RuntimeException('無法儲存相簿圖片。');
            }
            $promoted[] = $target;
        }
    } catch (Throwable $error) {
        foreach ($promoted as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        foreach (array_keys($targets) as $stage) {
            if (is_file($stage)) {
                unlink($stage);
            }
        }
        throw $error;
    }
}

function albumThumbnailPath(?string $path, bool $subImage = false): ?string
{
    $prefix = $subImage ? '../album_sub/' : '';
    if (!is_string($path)
        || !preg_match('~^' . preg_quote($prefix, '~') . 'img/([a-f0-9]{32})\.(jpg|png|webp)$~', $path, $matches)
    ) {
        return null;
    }

    return $prefix . 'img/' . $matches[1] . '_thumb.' . $matches[2];
}

function albumManagedImagePath(?string $path, bool $subImage = false): ?string
{
    $prefix = $subImage ? '../album_sub/' : '';
    if (!is_string($path)
        || !preg_match('~^' . preg_quote($prefix, '~') . 'img/[a-f0-9]{32}(?:_thumb)?\.(jpg|png|webp)$~', $path)
    ) {
        return null;
    }

    $base = $subImage ? __DIR__ . '/../album_sub/img/' : __DIR__ . '/img/';
    return $base . basename($path);
}

function albumRemoveImage(?string $path, bool $subImage = false): void
{
    foreach ([$path, albumThumbnailPath($path, $subImage)] as $storedPath) {
        $absolute = albumManagedImagePath($storedPath, $subImage);
        if ($absolute !== null && is_file($absolute) && !unlink($absolute)) {
            throw new RuntimeException('無法刪除相簿圖片檔案。');
        }
    }
}

function albumDecodeSubImages(string $json): array
{
    $images = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($images) || !array_is_list($images) || count($images) > ALBUM_MAX_SUB_IMAGES) {
        throw new RuntimeException('相簿的附加圖片資料格式無效。');
    }
    foreach ($images as $image) {
        if (!is_array($image)
            || !isset($image['original'])
            || albumManagedImagePath($image['original'], true) === null
        ) {
            throw new RuntimeException('相簿的附加圖片路徑無效。');
        }
    }

    return $images;
}
