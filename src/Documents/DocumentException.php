<?php
declare(strict_types=1);

namespace JobReady\Documents;

use RuntimeException;

final class DocumentException extends RuntimeException
{
    private const ERRORS = [
        'upload_missing' => [400, 'Choose one PDF or DOCX document to upload.'],
        'upload_failed' => [400, 'The upload did not complete. Please select the file again.'],
        'too_large' => [413, 'The document exceeds the 5 MB limit. Please choose a smaller file.'],
        'unsupported' => [415, 'Only PDF (.pdf) and modern Word (.docx) documents are supported.'],
        'malformed' => [422, 'This document is malformed or its contents do not match its file extension. Please export a new PDF or DOCX.'],
        'encrypted' => [422, 'Encrypted or password-protected documents are not supported. Please upload an unencrypted PDF or DOCX.'],
        'unsafe_archive' => [422, 'The Word document contains unsafe or unsupported archive entries. Please export a new DOCX.'],
        'too_complex' => [422, 'This document exceeds safe processing limits. Please export a simpler or shorter document, or paste its text.'],
        'no_text' => [422, 'No readable text could be extracted. Scanned or image-only PDFs need OCR first; you can also paste the CV text.'],
        'unavailable' => [503, 'Document extraction is unavailable on this server. You can still paste your CV text.'],
    ];

    public readonly int $status;
    public readonly string $reason;

    public function __construct(string $reason)
    {
        $this->reason = isset(self::ERRORS[$reason]) ? $reason : 'malformed';
        [$this->status, $message] = self::ERRORS[$this->reason];
        parent::__construct($message);
    }
}
