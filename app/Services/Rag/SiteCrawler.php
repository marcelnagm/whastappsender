<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Http;

/**
 * Reads the public pages of a company website (same host, a few levels deep, obeying robots.txt) and returns their
 * text. Every request is checked against PublicUrl and redirects are followed by hand, so a page cannot send the
 * crawler to an internal address.
 */
class SiteCrawler
{
    public const PAGE_MARK = "\n\n=== ";

    public function __construct(private TextExtractor $extractor)
    {
    }

    /**
     * @return array{text:string,pages:int}
     *
     * @throws RagException
     */
    public function crawl(string $startUrl, ?int $maxPages = null, int $maxDepth = 2): array
    {
        $maxPages = $maxPages ?? (int) config('rag.crawl_max_pages', 30);
        $start = $this->normalize($startUrl);
        if (!$start || !PublicUrl::allowed($start)) {
            throw new RagException(__('This address cannot be read (only public websites are allowed).'));
        }
        $host = strtolower(parse_url($start, PHP_URL_HOST));
        $disallow = $this->robots($start);

        $queue = [[$start, 0]];
        $seen = [$start => true];
        $out = [];
        while ($queue && count($out) < $maxPages) {
            [$url, $depth] = array_shift($queue);
            if ($this->blocked($url, $disallow)) {
                continue;
            }
            $html = $this->fetch($url);
            if ($html === null) {
                continue;
            }
            $title = $this->title($html) ?: $url;
            $text = $this->extractor->html($html);
            if (mb_strlen($text) > 80) {
                $out[] = self::PAGE_MARK . $title . ' — ' . $url . " ===\n" . $text;
            }
            if ($depth < $maxDepth) {
                foreach ($this->links($html, $url) as $link) {
                    if (!isset($seen[$link]) && strtolower((string) parse_url($link, PHP_URL_HOST)) === $host) {
                        $seen[$link] = true;
                        $queue[] = [$link, $depth + 1];
                    }
                }
            }
        }
        if (!$out) {
            throw new RagException(__('No readable text was found on this website.'));
        }

        return ['text' => mb_substr(trim(implode('', $out)), 0, (int) config('rag.max_source_chars', 400000)), 'pages' => count($out)];
    }

    private function fetch(string $url): ?string
    {
        for ($hop = 0; $hop < 4; $hop++) {
            if (!PublicUrl::allowed($url)) {
                return null;
            }
            try {
                $r = Http::timeout(15)->withoutRedirecting()->withHeaders(['User-Agent' => 'SAMA-Knowledge-Bot/1.0', 'Accept' => 'text/html'])->get($url);
            } catch (\Throwable $e) {
                return null;
            }
            if ($r->redirect() && ($loc = $r->header('Location'))) {
                $url = $this->absolute($loc, $url) ?? '';
                continue;
            }
            if (!$r->successful() || !str_contains(strtolower($r->header('Content-Type')), 'html')) {
                return null;
            }

            return mb_substr($r->body(), 0, 1_000_000);
        }

        return null;
    }

    private function robots(string $start): array
    {
        $p = parse_url($start);
        $robots = $p['scheme'] . '://' . $p['host'] . '/robots.txt';
        try {
            $r = Http::timeout(8)->withoutRedirecting()->get($robots);
        } catch (\Throwable $e) {
            return [];
        }
        if (!$r->successful()) {
            return [];
        }
        $rules = [];
        $mine = false;
        foreach (preg_split('/\R/', $r->body()) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if (stripos($line, 'user-agent:') === 0) {
                $mine = trim(substr($line, 11)) === '*';
            } elseif ($mine && stripos($line, 'disallow:') === 0 && ($path = trim(substr($line, 9))) !== '') {
                $rules[] = $path;
            }
        }

        return $rules;
    }

    private function blocked(string $url, array $disallow): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH) ?: '/';
        foreach ($disallow as $rule) {
            if (str_starts_with($path, $rule)) {
                return true;
            }
        }

        return false;
    }

    private function title(string $html): string
    {
        return preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    /** @return string[] */
    private function links(string $html, string $base): array
    {
        preg_match_all('/<a\s[^>]*href=["\']([^"\'#]+)["\']/i', $html, $m);
        $out = [];
        foreach ($m[1] as $href) {
            if (preg_match('#^(mailto:|tel:|javascript:)#i', $href) || preg_match('#\.(jpg|jpeg|png|gif|svg|webp|pdf|zip|mp4|css|js)(\?|$)#i', $href)) {
                continue;
            }
            if ($abs = $this->absolute($href, $base)) {
                $out[$abs] = true;
            }
        }

        return array_keys($out);
    }

    private function absolute(string $href, string $base): ?string
    {
        $b = parse_url($base);
        if (!$b) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $this->normalize($href);
        }
        $origin = $b['scheme'] . '://' . $b['host'];
        if (str_starts_with($href, '//')) {
            return $this->normalize($b['scheme'] . ':' . $href);
        }
        if (str_starts_with($href, '/')) {
            return $this->normalize($origin . $href);
        }
        $dir = rtrim(dirname($b['path'] ?? '/') === '.' ? '/' : dirname($b['path'] ?? '/'), '/');

        return $this->normalize($origin . $dir . '/' . $href);
    }

    private function normalize(string $url): ?string
    {
        $p = parse_url(trim($url));
        if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') . rtrim($p['path'] ?? '/', '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }
}
