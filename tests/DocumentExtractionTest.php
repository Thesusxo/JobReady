<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/autoload.php';
require __DIR__ . '/Support/DocumentFixtures.php';

use JobReady\Documents\CvUpload;
use JobReady\Documents\DocumentException;
use JobReady\Documents\DocumentExtractor;
use JobReady\Documents\ExtractionProcess;

function documentCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/jobready-documents-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$process = new ExtractionProcess();
try {
    $pdf = $directory . '/fictional.pdf';
    file_put_contents($pdf, DocumentFixtures::pdf());
    documentCheck(str_contains($process->extract($pdf, 'pdf'), 'PHP, MySQL'), 'PDF text extraction failed.');
    $docx = $directory . '/fictional.docx';
    DocumentFixtures::docx($docx);
    documentCheck(str_contains($process->extract($docx, 'docx'), 'PHP, MySQL'), 'DOCX text extraction failed.');
    $unicode = $directory . '/unicode.docx';
    DocumentFixtures::docx($unicode, DocumentFixtures::wordXml('Fictional résumé: PHP and MySQL.'));
    documentCheck(str_contains($process->extract($unicode, 'docx'), 'résumé'), 'Unicode DOCX text was not preserved.');

    $emptyPdf = $directory . '/image-only.pdf';
    file_put_contents($emptyPdf, DocumentFixtures::pdf(false));
    $encryptedPdf = $directory . '/encrypted.pdf';
    file_put_contents($encryptedPdf, DocumentFixtures::pdf(true, true));
    $malformed = $directory . '/malformed.pdf';
    file_put_contents($malformed, "%PDF-1.4\nNot a valid document\n%%EOF\n");
    $spoofed = $directory . '/spoofed.pdf';
    file_put_contents($spoofed, 'This is plain text, not a PDF.');
    $slip = $directory . '/unsafe-path.docx';
    DocumentFixtures::docx($slip, null, ['../outside.txt' => 'Inert unsafe path fixture']);
    $bomb = $directory . '/compression-bomb.docx';
    DocumentFixtures::docx($bomb, null, ['word/large.txt' => str_repeat('X', 2 * 1024 * 1024)]);
    $xxe = $directory . '/entities.docx';
    $privateFixture = $directory . '/entity-data.txt';
    file_put_contents($privateFixture, 'FICTIONAL_ENTITY_SENTINEL');
    DocumentFixtures::docx($xxe, '<?xml version="1.0"?><!DOCTYPE document [<!ENTITY test SYSTEM "file://' . str_replace('\\', '/', $privateFixture) . '">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&test;</w:t></w:r></w:p></w:body></w:document>');
    $badXml = $directory . '/bad-xml.docx';
    DocumentFixtures::docx($badXml, '<document><broken>');
    $encryptedZip = $directory . '/encrypted.docx';
    DocumentFixtures::docx($encryptedZip, encrypted: true);
    $macro = $directory . '/macro.docx';
    DocumentFixtures::docx($macro, null, ['word/vbaProject.bin' => 'Inert macro marker, not executable code']);
    $symlink = $directory . '/symlink.docx';
    DocumentFixtures::docx($symlink, null, ['word/link' => 'Inert symlink fixture']);
    $zip = new ZipArchive();
    $zip->open($symlink);
    $zip->setExternalAttributesName('word/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();
    $large = $directory . '/oversized.pdf';
    $file = fopen($large, 'wb');
    fwrite($file, '%PDF-1.4');
    ftruncate($file, DocumentExtractor::MAX_UPLOAD_BYTES + 1);
    fclose($file);

    foreach ([
        [$emptyPdf, 'pdf', 'no_text'], [$encryptedPdf, 'pdf', 'encrypted'], [$malformed, 'pdf', 'malformed'],
        [$spoofed, 'pdf', 'malformed'], [$slip, 'docx', 'unsafe_archive'], [$bomb, 'docx', 'too_complex'],
        [$xxe, 'docx', 'unsafe_archive'], [$badXml, 'docx', 'malformed'], [$encryptedZip, 'docx', 'encrypted'],
        [$macro, 'docx', 'unsafe_archive'], [$symlink, 'docx', 'unsafe_archive'], [$large, 'pdf', 'too_large'],
    ] as [$path, $format, $expected]) {
        $rejected = false;
        try {
            $process->extract($path, $format);
        } catch (DocumentException $exception) {
            $rejected = true;
            documentCheck($exception->reason === $expected, 'Unexpected document rejection category: ' . $expected . '.');
            documentCheck(!str_contains($exception->getMessage(), 'FICTIONAL_ENTITY_SENTINEL'), 'Document contents escaped into errors.');
        }
        documentCheck($rejected, 'Unsafe document was accepted.');
    }
    documentCheck(!is_file(dirname($directory) . '/outside.txt'), 'An archive path was extracted to disk.');
    foreach ([UPLOAD_ERR_PARTIAL => 'upload_failed', UPLOAD_ERR_INI_SIZE => 'too_large', UPLOAD_ERR_NO_FILE => 'upload_missing'] as $uploadError => $expected) {
        try {
            (new CvUpload())->extract(['cv_file' => ['error' => $uploadError]], 0);
            throw new RuntimeException('Upload error was accepted.');
        } catch (DocumentException $exception) {
            documentCheck($exception->reason === $expected, 'Upload error handling failed.');
        }
    }
    try {
        (new CvUpload())->extract(['cv_file' => ['error' => UPLOAD_ERR_OK, 'name' => 'fictional.pdf', 'tmp_name' => $pdf, 'size' => filesize($pdf)]], 0);
        throw new RuntimeException('A non-uploaded local path was accepted.');
    } catch (DocumentException $exception) {
        documentCheck($exception->reason === 'upload_failed' && is_file($pdf), 'Non-uploaded files must not be read or deleted by the upload endpoint.');
    }
    echo "Document extraction/security tests passed (fictional PDF/DOCX, encryption, ZIP limits, XML safety, upload errors).\n";
} finally {
    foreach (new DirectoryIterator($directory) as $entry) {
        if (!$entry->isDot()) unlink($entry->getPathname());
    }
    rmdir($directory);
}
