<?php
declare(strict_types=1);

namespace JobReady\Documents;

final class CvUpload
{
    /** @return array{text: string, format: string} */
    public function extract(array $files, int $contentLength): array
    {
        try {
            if ($contentLength > DocumentExtractor::MAX_UPLOAD_BYTES + 128 * 1024) {
                throw new DocumentException('too_large');
            }
            $file = $files['cv_file'] ?? null;
            if (count($files) !== 1 || !is_array($file) || !isset($file['error']) || !is_int($file['error'])) {
                throw new DocumentException('upload_missing');
            }
            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new DocumentException('too_large');
            }
            if ($file['error'] === UPLOAD_ERR_NO_FILE) {
                throw new DocumentException('upload_missing');
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new DocumentException('upload_failed');
            }
            if (!isset($file['tmp_name'], $file['name'], $file['size']) || !is_string($file['tmp_name'])
                || !is_string($file['name']) || !is_int($file['size']) || !is_uploaded_file($file['tmp_name'])) {
                throw new DocumentException('upload_failed');
            }
            if ($file['size'] > DocumentExtractor::MAX_UPLOAD_BYTES || filesize($file['tmp_name']) !== $file['size']) {
                throw new DocumentException('too_large');
            }
            if (strlen($file['name']) > 255 || preg_match('/[\x00-\x1F\/\\\\]/', $file['name'])) {
                throw new DocumentException('unsupported');
            }
            $format = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($format, ['pdf', 'docx'], true)) {
                throw new DocumentException('unsupported');
            }
            // Client MIME type is deliberately ignored; sniff bytes and inspect the actual format.
            DocumentExtractor::validateFormat($file['tmp_name'], $format);
            $text = (new ExtractionProcess())->extract($file['tmp_name'], $format);
            return ['text' => $text, 'format' => $format];
        } finally {
            // Delete only PHP-owned uploaded paths, including rejected/multiple-file uploads.
            foreach ($files as $file) {
                if (!is_array($file) || !isset($file['tmp_name'])) {
                    continue;
                }
                $paths = is_array($file['tmp_name'])
                    ? new \RecursiveIteratorIterator(new \RecursiveArrayIterator($file['tmp_name']))
                    : [$file['tmp_name']];
                foreach ($paths as $path) {
                    if (is_string($path) && is_uploaded_file($path)) {
                        @unlink($path);
                    }
                }
            }
        }
    }
}
