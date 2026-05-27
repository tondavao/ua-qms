<?php

class FileTypeDetector {
    public static function detectFileType($filePath) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $category = 'unknown';
        $type = 'unknown';

        // Determine category based on MIME type or extension
        if (str_starts_with($mimeType, 'application/pdf')) {
            $category = 'document';
            $type = 'pdf';
        } elseif (str_starts_with($mimeType, 'image/')) {
            $category = 'image';
            $type = 'image';
        } elseif (str_starts_with($mimeType, 'video/')) {
            $category = 'video';
            $type = 'video';
        } elseif (str_starts_with($mimeType, 'audio/')) {
            $category = 'audio';
            $type = 'audio';
        } elseif (in_array($extension, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'])) {
            $category = 'document';
            $type = 'office';
        } elseif (in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz'])) {
            $category = 'archive';
            $type = 'archive';
        } elseif (in_array($extension, ['txt', 'csv', 'md', 'json', 'xml', 'log', 'php', 'js', 'css', 'html'])) {
            $category = 'text';
            $type = 'text';
        } else {
            // Fallback for other document types or if MIME detection fails
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
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        return $mimeType;
    }
}

?>
