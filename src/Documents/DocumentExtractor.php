<?php
declare(strict_types=1);

namespace JobReady\Documents;

use DOMDocument;
use DOMXPath;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/** Invoked only inside the memory/time-limited extraction worker. */
final class DocumentExtractor
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    public const MAX_TEXT_BYTES = 64000;
    private const WORD_NAMESPACES = [
        'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
        'http://purl.oclc.org/ooxml/wordprocessingml/main',
    ];

    public static function validateFormat(string $path, string $format): void
    {
        $size = filesize($path);
        if ($size === false || $size === 0) {
            throw new DocumentException('malformed');
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new DocumentException('too_large');
        }
        $header = file_get_contents($path, false, null, 0, 16);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($format === 'pdf') {
            if (!is_string($header) || !preg_match('/^%PDF-(?:1\.[0-7]|2\.0)/', $header) || $mime !== 'application/pdf') {
                throw new DocumentException('malformed');
            }
        } elseif ($format === 'docx') {
            if (is_string($header) && str_starts_with($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
                throw new DocumentException('encrypted');
            }
            if (!is_string($header) || !str_starts_with($header, "PK\x03\x04")
                || !in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
                throw new DocumentException('malformed');
            }
        } else {
            throw new DocumentException('unsupported');
        }
    }

    public function extract(string $path, string $format): string
    {
        self::validateFormat($path, $format);
        $text = $format === 'pdf' ? $this->pdf($path) : $this->docx($path);
        if (preg_match('//u', $text) !== 1) {
            throw new DocumentException('malformed');
        }
        $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0E-\x1F\x7F]/u', '', $text);
        $text = trim(preg_replace('/\n{3,}/', "\n\n", $text));
        if (strlen($text) > self::MAX_TEXT_BYTES) {
            throw new DocumentException('too_complex');
        }
        if ($text === '' || preg_match('/[\p{L}\p{N}]/u', $text) !== 1) {
            throw new DocumentException('no_text');
        }
        return $text;
    }

    private function pdf(string $path): string
    {
        if (!class_exists(Parser::class)) {
            throw new DocumentException('unavailable');
        }
        $config = new Config();
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(4 * 1024 * 1024);
        $config->setIgnoreEncryption(false);
        try {
            $document = (new Parser([], $config))->parseFile($path);
            $pages = $document->getPages();
            if (count($pages) > 50) {
                throw new DocumentException('too_complex');
            }
            $text = '';
            foreach ($pages as $page) {
                $text .= $page->getText() . "\n";
                if (strlen($text) > self::MAX_TEXT_BYTES) {
                    throw new DocumentException('too_complex');
                }
            }
            return $text;
        } catch (DocumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Library text can contain document contents; use it only for internal classification.
            $encrypted = str_contains(strtolower($exception->getMessage()), 'secured')
                || str_contains(strtolower($exception->getMessage()), 'encrypt');
            throw new DocumentException($encrypted ? 'encrypted' : 'malformed');
        }
    }

    private function docx(string $path): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new DocumentException('unavailable');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new DocumentException('malformed');
        }
        try {
            if ($zip->numFiles > 500) {
                throw new DocumentException('too_complex');
            }
            $names = [];
            $totalSize = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new DocumentException('malformed');
                }
                $name = $stat['name'];
                if (isset($names[$name]) || str_contains($name, "\0") || str_contains($name, '\\')
                    || str_starts_with($name, '/') || str_contains($name, ':')
                    || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $name)) {
                    throw new DocumentException('unsafe_archive');
                }
                $names[$name] = true;
                if ($zip->getExternalAttributesIndex($index, $system, $attributes)
                    && (($attributes >> 16) & 0xF000) === 0xA000) {
                    throw new DocumentException('unsafe_archive');
                }
                if (($stat['encryption_method'] ?? 0) !== ZipArchive::EM_NONE) {
                    throw new DocumentException('encrypted');
                }
                if (!in_array($stat['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                    throw new DocumentException('unsafe_archive');
                }
                $totalSize += $stat['size'];
                if ($totalSize > 20 * 1024 * 1024 || $stat['size'] > 8 * 1024 * 1024
                    || ($stat['size'] > 65536 && $stat['size'] > 100 * max(1, $stat['comp_size']))) {
                    throw new DocumentException('too_complex');
                }
                if (str_ends_with(strtolower($name), 'vbaproject.bin') || str_starts_with($name, 'word/embeddings/')) {
                    throw new DocumentException('unsafe_archive');
                }
            }
            foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml'] as $required) {
                if (!isset($names[$required])) {
                    throw new DocumentException('malformed');
                }
            }
            $types = $this->xml($this->readEntry($zip, '[Content_Types].xml', 65536));
            $xpath = new DOMXPath($types);
            $xpath->registerNamespace('ct', 'http://schemas.openxmlformats.org/package/2006/content-types');
            if ($xpath->query('/ct:Types/ct:Override[@PartName="/word/document.xml" and @ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"]')->length !== 1) {
                throw new DocumentException('malformed');
            }
            $relations = $this->xml($this->readEntry($zip, '_rels/.rels', 65536));
            $xpath = new DOMXPath($relations);
            $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
            $main = $xpath->query('/r:Relationships/r:Relationship[@Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" or @Type="http://purl.oclc.org/ooxml/officeDocument/relationships/officeDocument"]');
            if ($main->length !== 1 || $main->item(0)->getAttribute('TargetMode') === 'External'
                || !in_array($main->item(0)->getAttribute('Target'), ['word/document.xml', '/word/document.xml'], true)) {
                throw new DocumentException('malformed');
            }
            $document = $this->xml($this->readEntry($zip, 'word/document.xml', 2 * 1024 * 1024));
            if ($document->documentElement?->localName !== 'document'
                || !in_array($document->documentElement->namespaceURI, self::WORD_NAMESPACES, true)) {
                throw new DocumentException('malformed');
            }
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('w', $document->documentElement->namespaceURI);
            $body = $xpath->query('/w:document/w:body')->item(0);
            if ($body === null) {
                throw new DocumentException('malformed');
            }
            $text = '';
            $walk = function (\DOMNode $node, int $depth = 0) use (&$walk, &$text): void {
                if ($depth > 100) {
                    throw new DocumentException('too_complex');
                }
                if (in_array($node->namespaceURI, self::WORD_NAMESPACES, true)) {
                    if ($node->localName === 't') {
                        $text .= $node->textContent;
                        return;
                    }
                    if (in_array($node->localName, ['br', 'cr', 'tab'], true)) {
                        $text .= $node->localName === 'tab' ? "\t" : "\n";
                    }
                }
                foreach ($node->childNodes as $child) {
                    $walk($child, $depth + 1);
                }
                if ($node->localName === 'p' && in_array($node->namespaceURI, self::WORD_NAMESPACES, true)) {
                    $text .= "\n";
                }
                if (strlen($text) > self::MAX_TEXT_BYTES) {
                    throw new DocumentException('too_complex');
                }
            };
            $walk($body);
            return $text;
        } finally {
            $zip->close();
        }
    }

    private function readEntry(ZipArchive $zip, string $name, int $limit): string
    {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] > $limit) {
            throw new DocumentException('too_complex');
        }
        // Bounded decompression; no archive paths are ever extracted to the filesystem.
        $data = $zip->getFromName($name, $limit + 1);
        if (!is_string($data) || strlen($data) !== $stat['size'] || strlen($data) > $limit) {
            throw new DocumentException('malformed');
        }
        return $data;
    }

    private function xml(string $content): DOMDocument
    {
        // No DTD/entity expansion, network access, huge-parser flags, or recovery mode.
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $content)) {
            throw new DocumentException('unsafe_archive');
        }
        $document = new DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        if (!$document->loadXML($content, LIBXML_NONET) || $document->doctype !== null) {
            throw new DocumentException('malformed');
        }
        return $document;
    }
}
