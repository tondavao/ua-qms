<?php

class PdfConverter {
    private static function isExecEnabled() {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = ini_get('disable_functions');
        if ($disabled) {
            $disabledList = array_map('trim', explode(',', $disabled));
            if (in_array('exec', $disabledList, true)) {
                return false;
            }
        }
        return true;
    }

    private static function findLibreOfficeBinary() {
        $candidates = [
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            '/usr/bin/libreoffice',
            '/usr/bin/soffice',
            '/usr/local/bin/libreoffice',
            '/usr/local/bin/soffice',
        ];
        foreach ($candidates as $path) {
            if (@is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    public static function convertToPdf($sourceFilePath, $outputDirectory) {
        if (!file_exists($sourceFilePath)) {
            error_log("Source file not found: " . $sourceFilePath);
            return false;
        }

        // Safely skip conversion if exec() is disabled or LibreOffice is not installed
        if (!self::isExecEnabled()) {
            return false;
        }

        $binary = self::findLibreOfficeBinary();
        if ($binary === null) {
            return false;
        }

        if (!is_dir($outputDirectory)) {
            @mkdir($outputDirectory, 0777, true);
        }

        $fileName = pathinfo($sourceFilePath, PATHINFO_FILENAME);
        $outputFilePath = rtrim($outputDirectory, "/\\") . DIRECTORY_SEPARATOR . $fileName . ".pdf";

        $command = escapeshellarg($binary) . " --headless --convert-to pdf:writer_pdf_Export --outdir " . escapeshellarg($outputDirectory) . " " . escapeshellarg($sourceFilePath) . " 2>&1";

        $output = [];
        $returnValue = 0;
        @exec($command, $output, $returnValue);

        if ($returnValue === 0 && file_exists($outputFilePath)) {
            return $outputFilePath;
        }

        error_log("PDF conversion failed for file: " . $sourceFilePath . " | Output: " . implode("\n", $output));
        return false;
    }
}

?>
