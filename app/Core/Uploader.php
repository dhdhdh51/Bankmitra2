<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validated file storage for photos and documents.
 *
 * Files are written under uploads/<kind>/<Y>/<m>/<random>.<ext> - a date-sharded
 * tree keeps directory sizes manageable at half a million customers.
 *
 * Filenames are always regenerated. The client-supplied name is only kept in
 * the database column, never used on disk, which removes path traversal and
 * double-extension tricks entirely.
 */
final class Uploader
{
    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file $_FILES entry
     * @param list<string> $allowedMime
     *
     * @return array{path:string,original_name:string,mime:string,size:int,width:int|null,height:int|null}
     */
    public static function store(array $file, string $kind, array $allowedMime, int $maxBytes): array
    {
        self::assertUploadOk($file);

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            throw new \RuntimeException('The uploaded file is empty.');
        }
        if ($size > $maxBytes) {
            throw new \RuntimeException(sprintf(
                'File is too large (%s). Maximum allowed is %s.',
                self::humanBytes($size),
                self::humanBytes($maxBytes)
            ));
        }

        // Trust the sniffed type, not the browser-declared one.
        $mime = self::detectMime($tmpPath);
        if (!in_array($mime, $allowedMime, true)) {
            throw new \RuntimeException('Unsupported file type: ' . $mime);
        }

        $width = null;
        $height = null;
        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesize($tmpPath);
            if ($info === false) {
                throw new \RuntimeException('The uploaded image is not readable.');
            }
            $width = (int) $info[0];
            $height = (int) $info[1];
        }

        $extension = self::extensionForMime($mime);
        $relativeDir = sprintf('%s/%s/%s', $kind, date('Y'), date('m'));
        $absoluteDir = self::uploadRoot() . '/' . $relativeDir;

        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            throw new \RuntimeException('Unable to create the upload directory. Check folder permissions.');
        }

        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $absolutePath = $absoluteDir . '/' . $filename;

        $moved = is_uploaded_file($tmpPath)
            ? move_uploaded_file($tmpPath, $absolutePath)
            : rename($tmpPath, $absolutePath);

        if ($moved === false) {
            throw new \RuntimeException('Failed to save the uploaded file.');
        }

        @chmod($absolutePath, 0644);

        return [
            'path'          => $relativeDir . '/' . $filename,
            'original_name' => mb_substr(basename((string) ($file['name'] ?? $filename)), 0, 255),
            'mime'          => $mime,
            'size'          => $size,
            'width'         => $width,
            'height'        => $height,
        ];
    }

    /**
     * Stores a base64 data URL or raw base64 PNG - how a camera capture submits when
     * not using multipart.
     *
     * @return array{path:string,original_name:string,mime:string,size:int,width:int|null,height:int|null}
     */
    public static function storeBase64(string $data, string $kind, int $maxBytes, string $expectExtension = 'png'): array
    {
        if (preg_match('#^data:([\w/+.-]+);base64,#i', $data, $m) === 1) {
            $data = substr($data, strlen($m[0]));
        }

        $binary = base64_decode(strtr(trim($data), " \n\r\t", '++++'), true);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('The image data could not be decoded.');
        }

        $size = strlen($binary);
        if ($size > $maxBytes) {
            throw new \RuntimeException(sprintf(
                'Image is too large (%s). Maximum allowed is %s.',
                self::humanBytes($size),
                self::humanBytes($maxBytes)
            ));
        }

        $mime = self::detectMimeFromString($binary);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new \RuntimeException('Only PNG, JPEG or WebP images are accepted.');
        }

        $relativeDir = sprintf('%s/%s/%s', $kind, date('Y'), date('m'));
        $absoluteDir = self::uploadRoot() . '/' . $relativeDir;

        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            throw new \RuntimeException('Unable to create the upload directory. Check folder permissions.');
        }

        $extension = self::extensionForMime($mime) ?: $expectExtension;
        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $absolutePath = $absoluteDir . '/' . $filename;

        if (file_put_contents($absolutePath, $binary) === false) {
            throw new \RuntimeException('Failed to save the image.');
        }
        @chmod($absolutePath, 0644);

        $dimensions = @getimagesizefromstring($binary);

        return [
            'path'          => $relativeDir . '/' . $filename,
            'original_name' => $kind . '.' . $extension,
            'mime'          => $mime,
            'size'          => $size,
            'width'         => $dimensions === false ? null : (int) $dimensions[0],
            'height'        => $dimensions === false ? null : (int) $dimensions[1],
        ];
    }

    /**
     * Normalises a possibly-multi-file $_FILES entry into a list of single
     * entries, so callers handle "one file" and "many files" identically.
     *
     * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public static function normalizeMultiple(string $field): array
    {
        if (!isset($_FILES[$field])) {
            return [];
        }

        $entry = $_FILES[$field];

        if (!is_array($entry['name'])) {
            if ((int) $entry['error'] === UPLOAD_ERR_NO_FILE) {
                return [];
            }
            return [[
                'name'     => (string) $entry['name'],
                'type'     => (string) ($entry['type'] ?? ''),
                'tmp_name' => (string) $entry['tmp_name'],
                'error'    => (int) $entry['error'],
                'size'     => (int) $entry['size'],
            ]];
        }

        $files = [];
        $count = count($entry['name']);
        for ($i = 0; $i < $count; $i++) {
            if ((int) $entry['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name'     => (string) $entry['name'][$i],
                'type'     => (string) ($entry['type'][$i] ?? ''),
                'tmp_name' => (string) $entry['tmp_name'][$i],
                'error'    => (int) $entry['error'][$i],
                'size'     => (int) $entry['size'][$i],
            ];
        }

        return $files;
    }

    public static function hasUpload(string $field): bool
    {
        if (!isset($_FILES[$field])) {
            return false;
        }
        $error = $_FILES[$field]['error'];
        if (is_array($error)) {
            foreach ($error as $code) {
                if ((int) $code !== UPLOAD_ERR_NO_FILE) {
                    return true;
                }
            }
            return false;
        }
        return (int) $error !== UPLOAD_ERR_NO_FILE;
    }

    public static function delete(string $relativePath): void
    {
        $path = self::uploadRoot() . '/' . ltrim($relativePath, '/');
        $real = realpath($path);
        $root = realpath(self::uploadRoot());

        // Never delete outside the uploads root.
        if ($real === false || $root === false || !str_starts_with($real, $root)) {
            return;
        }
        @unlink($real);
    }

    public static function uploadRoot(): string
    {
        $root = (string) Config::get('paths.uploads', ROOT_PATH . '/uploads');
        return rtrim($root, '/');
    }

    public static function absolutePath(string $relativePath): string
    {
        return self::uploadRoot() . '/' . ltrim($relativePath, '/');
    }

    // -----------------------------------------------------------------------

    /** @param array<string,mixed> $file */
    private static function assertUploadOk(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_OK) {
            return;
        }

        throw new \RuntimeException(match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'The file exceeds the server upload limit (upload_max_filesize). Ask your host to raise it or upload a smaller file.',
            UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary upload directory configured.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
            default               => 'The upload failed (error code ' . $error . ').',
        });
    }

    private static function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return strtolower($mime);
                }
            }
        }

        $info = @getimagesize($path);
        if ($info !== false && isset($info['mime'])) {
            return strtolower((string) $info['mime']);
        }

        $contents = (string) file_get_contents($path, false, null, 0, 1024);
        return self::detectMimeFromString($contents);
    }

    private static function detectMimeFromString(string $binary): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_buffer($finfo, $binary);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return strtolower($mime);
                }
            }
        }

        if (str_starts_with($binary, "\x89PNG\x0d\x0a\x1a\x0a")) {
            return 'image/png';
        }
        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($binary, '%PDF')) {
            return 'application/pdf';
        }
        if (str_starts_with($binary, 'RIFF') && substr($binary, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return 'application/octet-stream';
    }

    private static function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'application/pdf' => 'pdf',
            default           => 'bin',
        };
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }
        return $bytes . ' B';
    }
}
