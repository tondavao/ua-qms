<?php

require_once __DIR__ . "/file-type-detector.php";

class PreviewHandler {
    public static function getPreviewPath($filePath, $previewPath = null) {
        // If a dedicated preview_path exists and is valid, use it.
        if ($previewPath && file_exists($previewPath)) {
            return $previewPath;
        }

        // Otherwise, determine based on the original file.
        if (!file_exists($filePath)) {
            return false; // Original file doesn't exist either.
        }

        $fileInfo = FileTypeDetector::detectFileType($filePath);
        $category = $fileInfo["category"];
        $type = $fileInfo["type"];

        // For documents that are already PDF, or images, videos, audio, text, use the original file path.
        if ($type === 'pdf' || $category === 'image' || $category === 'video' || $category === 'audio' || $category === 'text') {
            return $filePath;
        }

        // For office documents that should have been converted, but preview_path is missing/invalid,
        // we might want to log this or attempt on-the-fly conversion (though that's resource-intensive).
        // For now, we'll return the original path, and the frontend will handle the
        // fallback to download or error message.
        return $filePath;
    }

    public static function getMimeTypeForPreview($filePath, $previewPath = null) {
        if ($previewPath && file_exists($previewPath)) {
            return FileTypeDetector::getMimeType($previewPath);
        } elseif (file_exists($filePath)) {
            return FileTypeDetector::getMimeType($filePath);
        }
        return 'application/octet-stream'; // Default unknown MIME type
    }
}

?>
