<?php

class FileTypeDetector {
    public static function detectFileType($filePath) {
        $mimeType = self::getMimeType($filePath);
        $extension = strtolower(pathinfo((string)$filePath, PATHINFO_EXTENSION));

        $category = 'unknown';
        $type = 'unknown';

        // Prioritize explicit office extensions first (DOCX/XLSX are ZIP containers and finfo often reports application/zip)
        if (in_array($extension, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true)) {
            $category = 'document';
            $type = 'office';
        } elseif ($extension === 'pdf' || str_starts_with($mimeType, 'application/pdf')) {
            $category = 'document';
            $type = 'pdf';
        } elseif (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true) || str_starts_with($mimeType, 'image/')) {
            $category = 'image';
            $type = 'image';
        } elseif (str_starts_with($mimeType, 'video/')) {
            $category = 'video';
            $type = 'video';
        } elseif (str_starts_with($mimeType, 'audio/')) {
            $category = 'audio';
            $type = 'audio';
        } elseif (in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz'], true)) {
            $category = 'archive';
            $type = 'archive';
        } elseif (in_array($extension, ['txt', 'csv', 'md', 'json', 'xml', 'log', 'php', 'js', 'css', 'html'], true)) {
            $category = 'text';
            $type = 'text';
        } else {
            if (str_starts_with($mimeType, 'text/')) {
                $category = 'text';
                $type = 'text';
            } elseif (str_starts_with($mimeType, 'application/')) {
                $category = 'document';
                $type = 'other_document';
            }
        }

        return [
            'mime_type' => $mimeType,
            'extension' => $extension,
            'category' => $category,
            'type' => $type
        ];
    }

    public static function getMimeType($filePath) {
        $extension = strtolower(pathinfo((string)$filePath, PATHINFO_EXTENSION));
        $extMap = [
            'pdf'  => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc'  => 'application/msword',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls'  => 'application/vnd.ms-excel',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'txt'  => 'text/plain',
        ];

        if (function_exists('finfo_open') && is_file($filePath)) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = @finfo_file($finfo, $filePath);
                @finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }

        return $extMap[$extension] ?? 'application/octet-stream';
    }
}

?>
