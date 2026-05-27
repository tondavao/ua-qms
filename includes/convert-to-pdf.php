<?php

class PdfConverter {
    public static function convertToPdf($sourceFilePath, $outputDirectory) {
        if (!file_exists($sourceFilePath)) {
            error_log("Source file not found: " . $sourceFilePath);
            return false;
        }

        if (!is_dir($outputDirectory)) {
            mkdir($outputDirectory, 0777, true);
        }

        $fileName = pathinfo($sourceFilePath, PATHINFO_FILENAME);
        $outputFilePath = $outputDirectory . DIRECTORY_SEPARATOR . $fileName . ".pdf";

        // Command to convert using LibreOffice in headless mode
        // -env:UserInstallation=file:///tmp/libreoffice_user is to prevent multiple instances from conflicting
        $command = "libreoffice --headless --convert-to pdf:writer_pdf_Export --outdir " . escapeshellarg($outputDirectory) . " " . escapeshellarg($sourceFilePath) . " 2>&1";
        
        $output = [];
        $returnValue = 0;
        exec($command, $output, $returnValue);

        if ($returnValue === 0 && file_exists($outputFilePath)) {
            return $outputFilePath;
        } else {
            error_log("PDF conversion failed for file: " . $sourceFilePath);
            error_log("Command: " . $command);
            error_log("Output: " . implode("\n", $output));
            return false;
        }
    }
}

?>
