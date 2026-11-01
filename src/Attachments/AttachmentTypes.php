<?php

namespace Osos\Gaith\Sdk\Attachments;

/**
 * The file types a chat attachment can be, and how an upload is checked against them.
 *
 * A file's type comes from its contents (libmagic via ext-fileinfo), never from the browser's
 * claim. Office files are containers - OOXML (.docx/.xlsx) is a ZIP, legacy .doc/.xls an OLE
 * compound file - and text formats (.csv/.md/.txt) have no signature, so for those the contents
 * only prove the family and the extension picks the type. GAITH checks the bytes again on receipt.
 */
final class AttachmentTypes
{
    /** Extension => the media type sent to GAITH. */
    public const BY_EXTENSION = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv',
        'md' => 'text/markdown',
        'txt' => 'text/plain',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
    ];

    /** What libmagic may report for each container family. */
    private const ZIP = ['application/zip', 'application/octet-stream'];
    private const OLE = [
        'application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office',
        'application/msword', 'application/vnd.ms-excel',
    ];

    /** Types whose contents identify them on their own, so a wrong extension does not matter. */
    private const SELF_IDENTIFYING = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'image/bmp', 'image/tiff'];

    /**
     * Config entries as media types: accepts extensions ("xlsx", ".xlsx") or media types.
     *
     * @param mixed[] $entries
     * @return string[]
     */
    public static function normalize(array $entries): array
    {
        $types = [];
        foreach ($entries as $entry) {
            if (!is_string($entry) || ($entry = strtolower(trim($entry))) === '') {
                continue;
            }
            if (strpos($entry, '/') !== false) {
                $types[] = $entry;
            } elseif (isset(self::BY_EXTENSION[ltrim($entry, '.')])) {
                $types[] = self::BY_EXTENSION[ltrim($entry, '.')];
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * The media type to upload the file as, or null when it is not one of $allowed
     * (or its contents do not match its extension, e.g. a program renamed to .csv).
     *
     * @param string[] $allowed media types, as returned by normalize()
     */
    public static function detect(string $path, string $filename, array $allowed): ?string
    {
        $detected = self::contentType($path);

        return $detected === null ? null : self::forDetectedType($detected, $filename, $allowed);
    }

    /**
     * Same as detect(), for a caller that already read the contents' type, e.g. Laravel's
     * UploadedFile::getMimeType() (libmagic underneath).
     *
     * @param string[] $allowed media types, as returned by normalize()
     */
    public static function forDetectedType(string $detected, string $filename, array $allowed): ?string
    {
        $detected = strtolower(trim($detected));
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $byName = self::BY_EXTENSION[$extension] ?? null;
        if ($byName !== null && in_array($byName, $allowed, true) && self::contentFits($extension, $detected)) {
            return $byName;
        }

        // A picture or PDF saved under the wrong extension is still what its bytes say.
        if (in_array($detected, self::SELF_IDENTIFYING, true) && in_array($detected, $allowed, true)) {
            return $detected;
        }

        return null;
    }

    private static function contentType(string $path): ?string
    {
        if (!is_file($path) || !class_exists(\finfo::class)) {
            return null;
        }
        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($type) ? strtolower($type) : null;
    }

    /** Whether the detected contents are plausible for a file with this extension. */
    private static function contentFits(string $extension, string $detected): bool
    {
        switch ($extension) {
            case 'docx':
            case 'xlsx':
                return $detected === self::BY_EXTENSION[$extension] || in_array($detected, self::ZIP, true);
            case 'doc':
            case 'xls':
                return in_array($detected, self::OLE, true);
            case 'csv':
            case 'md':
            case 'txt':
                // Any text, but not an empty file.
                return strpos($detected, 'text/') === 0 || $detected === 'application/csv';
            case 'bmp':
                return in_array($detected, ['image/bmp', 'image/x-ms-bmp', 'image/x-bmp'], true);
            default:
                return $detected === self::BY_EXTENSION[$extension];
        }
    }
}
