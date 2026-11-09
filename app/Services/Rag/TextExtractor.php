<?php

namespace App\Services\Rag;

/** Plain text out of an uploaded file: txt, md, csv, json, html, docx and (with pdftotext installed) pdf. */
class TextExtractor
{
    public const EXTENSIONS = ['txt', 'md', 'csv', 'json', 'html', 'htm', 'docx', 'pdf'];

    /** @throws RagException */
    public function extract(string $path, string $extension): string
    {
        $ext = strtolower($extension);
        $text = match ($ext) {
            'txt', 'md', 'csv', 'json' => (string) file_get_contents($path),
            'html', 'htm' => $this->html((string) file_get_contents($path)),
            'docx' => $this->docx($path),
            'pdf' => $this->pdf($path),
            default => throw new RagException(__('Unsupported file type: :ext', ['ext' => $ext])),
        };
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }
        $text = trim(preg_replace("/[ \t]+\n/", "\n", $text));
        if ($text === '') {
            throw new RagException(__('No text could be read from this file.'));
        }

        return mb_substr($text, 0, (int) config('rag.max_source_chars', 400000));
    }

    public function html(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg)\b.*?</\1>#is', ' ', $html);
        $html = preg_replace('#</(p|div|li|h[1-6]|tr|br|section|article)>|<br\s*/?>#i', "\n", $html);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function docx(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true || ($xml = $zip->getFromName('word/document.xml')) === false) {
            throw new RagException(__('The file is not a valid .docx.'));
        }
        $zip->close();
        $xml = preg_replace('#</w:p>#', "\n\n", $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function pdf(string $path): string
    {
        $bin = trim((string) @shell_exec('command -v pdftotext 2>/dev/null'));
        if ($bin === '') {
            throw new RagException(__('PDF reading needs "pdftotext" (package poppler-utils) on the server.'));
        }
        $out = (string) @shell_exec(escapeshellarg($bin) . ' -enc UTF-8 -layout ' . escapeshellarg($path) . ' - 2>/dev/null');

        return $out;
    }
}
