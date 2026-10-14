<?php
declare(strict_types=1);

const PRODUCT_MAX_IMAGE_BYTES = 5242880;
const PRODUCT_MAX_IMAGE_PIXELS = 16000000;
const PRODUCT_MAX_GALLERY_IMAGES = 10;

function productImageDirectory(): string
{
    $directory = __DIR__ . '/img';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('無法建立產品圖片資料夾。');
    }

    return $directory;
}

function productImageExtension(string $mime): string
{
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => throw new RuntimeException('圖片格式不支援，請上傳 JPG、PNG 或 WebP。'),
    };
}

function productImageResource(string $path, string $mime)
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

function productWriteImage($image, string $path, string $mime, int $quality = 82): bool
{
    return match ($mime) {
        'image/jpeg' => imagejpeg($image, $path, $quality),
        'image/png' => imagepng($image, $path, 9),
        'image/webp' => function_exists('imagewebp') && imagewebp($image, $path, $quality),
        default => false,
    };
}

function productPrepareOriginal(string $sourcePath, string $targetPath, string $mime, int $width, int $height): void
{
    if (filesize($sourcePath) <= PRODUCT_MAX_IMAGE_BYTES) {
        if (!copy($sourcePath, $targetPath)) {
            throw new RuntimeException('無法暫存上傳圖片。');
        }
        return;
    }

    $source = productImageResource($sourcePath, $mime);
    try {
        $scale = 1.0;
        for ($attempt = 0; $attempt < 16; $attempt++) {
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
                if (!imagecopyresampled($image, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($source), imagesy($source))) {
                    if ($image !== $source) {
                        imagedestroy($image);
                    }
                    throw new RuntimeException('圖片縮放失敗。');
                }
            }

            try {
                $qualities = $mime === 'image/png' ? [100] : [85, 75, 65, 55, 45, 35];
                foreach ($qualities as $quality) {
                    if (!productWriteImage($image, $targetPath, $mime, $quality)) {
                        throw new RuntimeException('圖片壓縮失敗。');
                    }
                    clearstatcache(true, $targetPath);
                    if (filesize($targetPath) <= PRODUCT_MAX_IMAGE_BYTES) {
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

function productStageUpload(array $file): array
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
    $extension = productImageExtension($mime);
    if ($mime === 'image/webp' && !function_exists('imagecreatefromwebp')) {
        throw new RuntimeException('目前伺服器的 GD 不支援 WebP 圖片。');
    }
    $width = (int) $info[0];
    $height = (int) $info[1];
    if ($width < 1 || $height < 1 || $width * $height > PRODUCT_MAX_IMAGE_PIXELS) {
        throw new RuntimeException('圖片尺寸過大，請先縮小後再上傳。');
    }

    $directory = productImageDirectory();
    $stem = bin2hex(random_bytes(16));
    $original = 'img/' . $stem . '.' . $extension;
    $thumbnail = 'img/' . $stem . '_thumb.' . $extension;
    $stageOriginal = $directory . '/.stage_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $stageThumbnail = $directory . '/.stage_' . bin2hex(random_bytes(16)) . '.' . $extension;
    try {
        productPrepareOriginal($file['tmp_name'], $stageOriginal, $mime, $width, $height);
        $source = productImageResource($file['tmp_name'], $mime);
        try {
            $scale = min(1.0, 400 / max($width, $height));
            $thumbWidth = max(1, (int) round($width * $scale));
            $thumbHeight = max(1, (int) round($height * $scale));
            $thumbnailImage = imagecreatetruecolor($thumbWidth, $thumbHeight);
            if ($mime !== 'image/jpeg' && $thumbnailImage !== false) {
                imagealphablending($thumbnailImage, false);
                imagesavealpha($thumbnailImage, true);
                $transparent = imagecolorallocatealpha($thumbnailImage, 0, 0, 0, 127);
                imagefilledrectangle($thumbnailImage, 0, 0, $thumbWidth, $thumbHeight, $transparent);
            }
            if ($thumbnailImage === false || !imagecopyresampled(
                $thumbnailImage,
                $source,
                0,
                0,
                0,
                0,
                $thumbWidth,
                $thumbHeight,
                $width,
                $height
            )) {
                throw new RuntimeException('縮圖產生失敗。');
            }
            try {
                if (!productWriteImage($thumbnailImage, $stageThumbnail, $mime)) {
                    throw new RuntimeException('縮圖儲存失敗。');
                }
            } finally {
                imagedestroy($thumbnailImage);
            }
        } finally {
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
        'original' => $original,
        'thumbnail' => $thumbnail,
        'stage_original' => $stageOriginal,
        'stage_thumbnail' => $stageThumbnail,
    ];
}

function productPromoteImage(array $staged): void
{
    $directory = productImageDirectory();
    $targets = [
        $staged['stage_original'] => $directory . '/' . basename($staged['original']),
        $staged['stage_thumbnail'] => $directory . '/' . basename($staged['thumbnail']),
    ];
    $promoted = [];
    try {
        foreach ($targets as $stage => $target) {
            if (!rename($stage, $target)) {
                throw new RuntimeException('無法儲存產品圖片。');
            }
            $promoted[] = $target;
        }
    } catch (Throwable $error) {
        foreach (array_merge($promoted, array_keys($targets)) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        throw $error;
    }
}

function productThumbnailPath(?string $path): ?string
{
    return is_string($path) && preg_match('~^img/([a-f0-9]{32})\.(jpg|png|webp)$~', $path, $matches)
        ? 'img/' . $matches[1] . '_thumb.' . $matches[2]
        : null;
}

function productManagedImagePath(?string $path): ?string
{
    if (!is_string($path) || !preg_match('~^img/[a-f0-9]{32}(?:_thumb)?\.(jpg|png|webp)$~', $path)) {
        return null;
    }
    return __DIR__ . '/' . $path;
}

function productRemoveImage(?string $path): void
{
    foreach ([$path, productThumbnailPath($path)] as $storedPath) {
        $absolute = productManagedImagePath($storedPath);
        if ($absolute !== null && is_file($absolute) && !unlink($absolute)) {
            throw new RuntimeException('無法刪除產品圖片檔案。');
        }
    }
}

function productDecodeGallery(string $json): array
{
    $images = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($images) || !array_is_list($images) || count($images) > PRODUCT_MAX_GALLERY_IMAGES) {
        throw new RuntimeException('產品附加圖片資料格式無效。');
    }
    foreach ($images as $image) {
        if (!is_string($image) || productManagedImagePath($image) === null) {
            throw new RuntimeException('產品附加圖片路徑無效。');
        }
    }
    return $images;
}
