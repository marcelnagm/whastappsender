<?php

namespace App\Services\Insights;

/**
 * The AI answers in a fixed JSON format; this class keeps only what the page knows how to draw (kpi, table, bars, text,
 * recommendation), cuts everything to safe sizes and turns every value into plain text. The page draws the blocks with
 * its own components and escapes the text, so nothing the AI writes is ever run as HTML.
 */
class InsightBlocks
{
    public const TYPES = ['kpi', 'table', 'bars', 'text', 'recommendation'];

    /** @return array{summary:string,blocks:array<int,array>}|null null when the answer is not usable JSON */
    public static function parse(string $raw): ?array
    {
        $raw = trim($raw);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $data = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            return null;
        }
        $blocks = self::sanitize((array) ($data['blocks'] ?? []));
        if (!$blocks) {
            return null;
        }

        return ['summary' => self::str($data['summary'] ?? '', 600), 'blocks' => $blocks];
    }

    public static function sanitize(array $blocks): array
    {
        $out = [];
        foreach (array_slice($blocks, 0, 30) as $b) {
            if (!is_array($b) || !in_array($b['type'] ?? '', self::TYPES, true)) {
                continue;
            }
            $one = match ($b['type']) {
                'kpi' => ['type' => 'kpi', 'label' => self::str($b['label'] ?? '', 60), 'value' => self::str($b['value'] ?? '', 40), 'hint' => self::str($b['hint'] ?? '', 120)],
                'text' => ['type' => 'text', 'title' => self::str($b['title'] ?? '', 120), 'body' => self::str($b['body'] ?? '', 1500)],
                'recommendation' => ['type' => 'recommendation', 'title' => self::str($b['title'] ?? '', 140), 'body' => self::str($b['body'] ?? '', 800),
                    'priority' => in_array($b['priority'] ?? '', ['high', 'medium', 'low'], true) ? $b['priority'] : 'medium'],
                'table' => self::table($b),
                'bars' => self::bars($b),
            };
            if ($one && self::hasContent($one)) {
                $out[] = $one;
            }
        }

        return $out;
    }

    private static function table(array $b): ?array
    {
        $cols = array_slice(array_map(fn ($c) => self::str($c, 60), (array) ($b['columns'] ?? [])), 0, 6);
        if (!$cols) {
            return null;
        }
        $rows = [];
        foreach (array_slice((array) ($b['rows'] ?? []), 0, 25) as $r) {
            $rows[] = array_map(fn ($c) => self::str($c, 120), array_pad(array_slice((array) $r, 0, count($cols)), count($cols), ''));
        }

        return ['type' => 'table', 'title' => self::str($b['title'] ?? '', 120), 'columns' => $cols, 'rows' => $rows];
    }

    private static function bars(array $b): ?array
    {
        $labels = array_values(array_slice(array_map(fn ($l) => self::str($l, 40), (array) ($b['labels'] ?? [])), 0, 15));
        $values = array_values(array_slice(array_map(fn ($v) => is_numeric($v) ? (float) $v : 0.0, (array) ($b['values'] ?? [])), 0, 15));
        $n = min(count($labels), count($values));
        if ($n === 0) {
            return null;
        }

        return ['type' => 'bars', 'title' => self::str($b['title'] ?? '', 120), 'labels' => array_slice($labels, 0, $n), 'values' => array_slice($values, 0, $n)];
    }

    private static function hasContent(array $b): bool
    {
        return match ($b['type']) {
            'kpi' => $b['label'] !== '' && $b['value'] !== '',
            'text', 'recommendation' => ($b['body'] ?? '') !== '' || ($b['title'] ?? '') !== '',
            default => true,
        };
    }

    /** Plain text only: tags and control characters removed, length capped. */
    public static function str(mixed $v, int $max): string
    {
        if (is_array($v) || is_object($v)) {
            return '';
        }
        $s = strip_tags((string) $v);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);

        return mb_substr(trim($s), 0, $max);
    }
}
