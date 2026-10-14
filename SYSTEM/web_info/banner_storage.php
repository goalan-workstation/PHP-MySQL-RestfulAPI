<?php
declare(strict_types=1);

const WEB_BANNER_MAX_BYTES = 5242880;
const WEB_BANNER_MAX_PIXELS = 16000000;
const WEB_BANNER_THUMBNAIL_MAX_WIDTH = 480;
const WEB_BANNER_THUMBNAIL_MAX_HEIGHT = 160;

function webBannerImageDirectory(): string
{
    $directory = __DIR__ . '/img';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('無法建立首頁橫幅圖片資料夾。');
    }

    return $directory;
}

function webBannerImageExtension(string $mime): string
{
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => throw new RuntimeException('圖片格式不支援，請上傳 JPG、PNG 或 WebP。'),
    };
}

function webBannerImageResource(string $path, string $mime)
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

function webBannerWriteImage($image, string $path, string $mime, int $quality): bool
{
    return match ($mime) {
        'image/jpeg' => imagejpeg($image, $path, $quality),
        'image/png' => imagepng($image, $path, 9),
        'image/webp' => function_exists('imagewebp') && imagewebp($image, $path, $quality),
        default => false,
    };
}

function webBannerThumbnailRelativePath(string $path): string
{
    if (preg_match('/\Aimg\/([a-f0-9]{32})\.(jpg|png|webp)\z/D', $path, $matches) !== 1) {
        throw new RuntimeException('首頁橫幅圖片路徑無效。');
    }

    return 'img/thumb_' . $matches[1] . '.' . $matches[2];
}

function webBannerCreateThumbnail(string $sourcePath, string $targetPath, string $mime): void
{
    $source = webBannerImageResource($sourcePath, $mime);
    try {
        $scale = min(
            1,
            WEB_BANNER_THUMBNAIL_MAX_WIDTH / imagesx($source),
            WEB_BANNER_THUMBNAIL_MAX_HEIGHT / imagesy($source)
        );
        $width = max(1, (int) floor(imagesx($source) * $scale));
        $height = max(1, (int) floor(imagesy($source) * $scale));
        $thumbnail = imagecreatetruecolor($width, $height);
        if ($thumbnail === false) {
            throw new RuntimeException('無法配置首頁橫幅縮圖記憶體。');
        }

        try {
            if ($mime !== 'image/jpeg') {
                imagealphablending($thumbnail, false);
                imagesavealpha($thumbnail, true);
                $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
                imagefilledrectangle($thumbnail, 0, 0, $width, $height, $transparent);
            }
            if (!imagecopyresampled(
                $thumbnail,
                $source,
                0,
                0,
                0,
                0,
                $width,
                $height,
                imagesx($source),
                imagesy($source)
            )) {
                throw new RuntimeException('首頁橫幅縮圖產生失敗。');
            }
            if (!webBannerWriteImage($thumbnail, $targetPath, $mime, 82)) {
                throw new RuntimeException('首頁橫幅縮圖儲存失敗。');
            }
        } finally {
            imagedestroy($thumbnail);
        }
    } finally {
        imagedestroy($source);
    }
}

function webBannerEnsureThumbnail(?string $path): ?string
{
    $sourcePath = webBannerManagedImagePath($path);
    if ($sourcePath === null || $path === null) {
        return null;
    }

    $thumbnailPath = webBannerThumbnailRelativePath($path);
    $fullThumbnailPath = __DIR__ . '/' . $thumbnailPath;
    if (!is_file($fullThumbnailPath)) {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => throw new RuntimeException('首頁橫幅圖片格式無效。'),
        };
        webBannerCreateThumbnail($sourcePath, $fullThumbnailPath, $mime);
    }

    return $thumbnailPath;
}

function webBannerCompressImage(
    string $sourcePath,
    string $targetPath,
    string $mime,
    int $width,
    int $height
): void {
    $source = webBannerImageResource($sourcePath, $mime);
    try {
        $scale = 1.0;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $targetWidth = max(1, (int) floor($width * $scale));
            $targetHeight = max(1, (int) floor($height * $scale));
            $image = $attempt === 0 ? $source : imagecreatetruecolor($targetWidth, $targetHeight);
            if ($image === false) {
                throw new RuntimeException('無法配置圖片處理記憶體。');
            }
            if ($image !== $source) {
                if ($mime !== 'image/jpeg') {
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
                    imagefilledrectangle($image, 0, 0, $targetWidth, $targetHeight, $transparent);
                }
                if (!imagecopyresampled(
                    $image,
                    $source,
                    0,
                    0,
                    0,
                    0,
                    $targetWidth,
                    $targetHeight,
                    imagesx($source),
                    imagesy($source)
                )) {
                    imagedestroy($image);
                    throw new RuntimeException('圖片縮放失敗。');
                }
            }

            try {
                $qualities = $mime === 'image/png' ? [100] : [85, 75, 65, 55, 45, 35];
                foreach ($qualities as $quality) {
                    if (!webBannerWriteImage($image, $targetPath, $mime, $quality)) {
                        throw new RuntimeException('圖片壓縮失敗。');
                    }
                    clearstatcache(true, $targetPath);
                    if (filesize($targetPath) <= WEB_BANNER_MAX_BYTES) {
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

function webBannerStageUpload(array $file): array
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
            UPLOAD_ERR_NO_FILE => '請選擇要上傳的圖片。',
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
    $extension = webBannerImageExtension($mime);
    if (
        $mime === 'image/webp'
        && (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp'))
    ) {
        throw new RuntimeException('目前伺服器的 GD 不支援 WebP 圖片。');
    }
    $width = (int) $info[0];
    $height = (int) $info[1];
    if ($width < 1 || $height < 1 || $width * $height > WEB_BANNER_MAX_PIXELS) {
        throw new RuntimeException('圖片尺寸過大，請先縮小後再上傳。');
    }

    $directory = webBannerImageDirectory();
    $stem = bin2hex(random_bytes(16));
    $relativePath = 'img/' . $stem . '.' . $extension;
    $stagedPath = $directory . '/.stage_' . $stem . '.' . $extension;
    if ($file['size'] > WEB_BANNER_MAX_BYTES) {
        webBannerCompressImage($file['tmp_name'], $stagedPath, $mime, $width, $height);
    } elseif (!move_uploaded_file($file['tmp_name'], $stagedPath)) {
        throw new RuntimeException('無法儲存上傳圖片。');
    }

    clearstatcache(true, $stagedPath);
    if (!is_file($stagedPath) || filesize($stagedPath) > WEB_BANNER_MAX_BYTES) {
        if (is_file($stagedPath)) {
            unlink($stagedPath);
        }
        throw new RuntimeException('圖片儲存後超過 5 MB，請縮小圖片後再上傳。');
    }

    $thumbnailRelativePath = webBannerThumbnailRelativePath($relativePath);
    $stagedThumbnailPath = $directory . '/.stage_thumb_' . $stem . '.' . $extension;
    try {
        webBannerCreateThumbnail($stagedPath, $stagedThumbnailPath, $mime);
    } catch (Throwable $error) {
        if (is_file($stagedPath)) {
            unlink($stagedPath);
        }
        if (is_file($stagedThumbnailPath)) {
            unlink($stagedThumbnailPath);
        }
        throw $error;
    }

    return [
        'path' => $relativePath,
        'staged_path' => $stagedPath,
        'thumbnail_path' => $thumbnailRelativePath,
        'staged_thumbnail_path' => $stagedThumbnailPath,
    ];
}

function webBannerPromoteImage(array $staged): void
{
    $targetPath = __DIR__ . '/' . $staged['path'];
    if (!rename($staged['staged_path'], $targetPath)) {
        throw new RuntimeException('無法完成首頁橫幅圖片儲存。');
    }

    $thumbnailTargetPath = __DIR__ . '/' . $staged['thumbnail_path'];
    if (!rename($staged['staged_thumbnail_path'], $thumbnailTargetPath)) {
        unlink($targetPath);
        throw new RuntimeException('無法完成首頁橫幅縮圖儲存。');
    }
}

function webBannerManagedImagePath(?string $path): ?string
{
    if (
        $path === null
        || preg_match('/\Aimg\/[a-f0-9]{32}\.(?:jpg|png|webp)\z/D', $path) !== 1
    ) {
        return null;
    }

    $fullPath = __DIR__ . '/' . $path;
    return is_file($fullPath) ? $fullPath : null;
}

function webBannerRemoveImage(?string $path): void
{
    $fullPath = webBannerManagedImagePath($path);
    $thumbnailPath = $fullPath === null || $path === null
        ? null
        : __DIR__ . '/' . webBannerThumbnailRelativePath($path);
    $failed = false;

    foreach ([$fullPath, $thumbnailPath] as $managedPath) {
        if ($managedPath !== null && is_file($managedPath) && !unlink($managedPath)) {
            $failed = true;
        }
    }

    if ($failed) {
        throw new RuntimeException('無法清除舊的首頁橫幅圖片。');
    }
}
