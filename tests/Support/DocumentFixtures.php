<?php
declare(strict_types=1);

/** Generated, fictional fixtures only; no personal documents or provider requests. */
final class DocumentFixtures
{
    public static function pdf(bool $text = true, bool $encrypted = false): string
    {
        $stream = $text ? "BT /F1 12 Tf 72 720 Td (Fictional candidate: PHP, MySQL and REST API inventory project.) Tj ET" : "q 200 0 0 60 72 650 cm /Im1 Do Q";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >>'
                . ($text ? '' : ' /XObject << /Im1 6 0 R >>') . ' >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "\nendstream",
        ];
        if (!$text) {
            $objects[] = "<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Length 3 >>\nstream\n\xff\xff\xff\nendstream";
        }
        $encryptReference = count($objects) + 1;
        if ($encrypted) {
            // Inert fixture flagged as encrypted; the parser must refuse it before extracting content.
            $objects[] = '<< /Filter /Standard /V 2 /R 3 /Length 128 /O <0000000000000000> /U <0000000000000000> /P -4 >>';
        }
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . count($offsets) . "\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . 'trailer << /Size ' . count($offsets) . ' /Root 1 0 R' . ($encrypted ? ' /Encrypt ' . $encryptReference . ' 0 R' : '') . ">>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    public static function docx(string $path, ?string $document = null, array $extraEntries = [], bool $encrypted = false): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot generate DOCX fixture.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', $document ?? self::wordXml('Fictional candidate: PHP, MySQL and REST API inventory project.'));
        foreach ($extraEntries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        if ($encrypted) {
            $zip->setPassword('fictional-test-password');
            if (!$zip->setEncryptionName('word/document.xml', ZipArchive::EM_AES_256)) {
                throw new RuntimeException('Encrypted archive fixtures are not supported by this libzip build.');
            }
        }
        $zip->close();
    }

    public static function wordXml(string $text): string
    {
        return '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'
            . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r></w:p></w:body></w:document>';
    }
}
