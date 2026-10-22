<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches recent articles for the news-reading quiz from the RSS feeds in
 * config('services.news.feeds') — business news and corporate press releases,
 * the registers closest to TOEIC Part 7. Uses public RSS (no API key) to list
 * recent items, then best-effort scrapes the article page for the full passage,
 * falling back to the RSS summary when scraping fails. All failures degrade
 * gracefully (empty/null) rather than throwing, mirroring DictionaryService.
 */
class NewsService
{
    /** Minimum usable passage length; shorter -> fall back to the summary. */
    private const MIN_PASSAGE_CHARS = 400;

    /** Cap the passage so the AI prompt stays within a reasonable token budget. */
    private const MAX_PASSAGE_CHARS = 1800;

    /**
     * Human-readable publisher names, keyed by the feed host that serves them.
     * Used only for display, so an unlisted host falls back to its own domain.
     */
    private const SOURCE_NAMES = [
        'prnewswire.com' => 'PR Newswire',
        'cnbc.com' => 'CNBC',
        'bbci.co.uk' => 'BBC',
        'bbc.co.uk' => 'BBC',
        'bbc.com' => 'BBC',
    ];

    /**
     * Topic keys offered to the user, mapped to RSS feed URLs.
     *
     * @return array<string,string>
     */
    public function feeds(): array
    {
        return (array) config('services.news.feeds', []);
    }

    /**
     * The topic used when a request names one that isn't configured. Feeds are
     * ordered most-TOEIC-like first, so the first key is the safest default.
     */
    public function defaultTopic(): ?string
    {
        return array_key_first($this->feeds());
    }

    /**
     * Publisher name to show for a topic, derived from its feed host so adding
     * a feed needs no second config entry.
     */
    public function sourceFor(string $topic): string
    {
        $host = parse_url($this->feeds()[$topic] ?? '', PHP_URL_HOST) ?: '';
        $host = preg_replace('/^(www|feeds|feed)\./', '', strtolower($host)) ?? $host;

        return self::SOURCE_NAMES[$host] ?? ($host !== '' ? $host : 'Unknown');
    }

    /**
     * Pick one recent article for a topic, skipping URLs the user already did,
     * and attach a reading passage (scraped full text, or RSS summary fallback).
     *
     * @param  string[]  $excludeUrls
     * @return array{title:string,url:string,summary:string,published_at:?string,passage:string}|null
     */
    public function pickArticle(string $topic, array $excludeUrls = []): ?array
    {
        $articles = $this->recentArticles($topic);
        if (empty($articles)) {
            return null;
        }

        // drop already-seen articles, then shuffle for variety
        $exclude = array_map(fn ($u) => rtrim((string) $u, '/'), $excludeUrls);
        $articles = array_values(array_filter(
            $articles,
            fn ($a) => ! in_array(rtrim($a['url'], '/'), $exclude, true)
        )) ?: $articles; // if all were seen, allow repeats rather than fail
        shuffle($articles);

        foreach ($articles as $a) {
            $passage = $this->fetchPassage($a['url']);
            if ($passage === null) {
                $passage = trim($a['summary']);
            }
            if (mb_strlen($passage) >= 80) { // need at least a couple of sentences
                $a['passage'] = $passage;
                return $a;
            }
        }

        return null;
    }

    /**
     * List recent articles from a topic's RSS feed.
     *
     * @return array<int,array{title:string,url:string,summary:string,published_at:?string}>
     */
    public function recentArticles(string $topic, int $limit = 8): array
    {
        $feed = $this->feeds()[$topic] ?? null;
        if (! $feed) {
            return [];
        }

        try {
            $resp = Http::timeout(15)
                ->withHeaders(['User-Agent' => config('services.news.user_agent')])
                ->get($feed);
        } catch (\Throwable $e) {
            Log::error('News RSS fetch error: ' . $e->getMessage());
            return [];
        }

        if (! $resp->successful()) {
            Log::error('News RSS non-200: ' . $resp->status());
            return [];
        }

        return $this->parseRss($resp->body(), $limit);
    }

    /**
     * Parse an RSS document into article rows.
     *
     * @return array<int,array{title:string,url:string,summary:string,published_at:?string}>
     */
    private function parseRss(string $xml, int $limit): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);

        if ($doc === false || ! isset($doc->channel->item)) {
            Log::warning('News RSS parse failed');
            return [];
        }

        $out = [];
        foreach ($doc->channel->item as $item) {
            $url = trim((string) $item->link);
            $title = trim((string) $item->title);
            if ($url === '' || $title === '') {
                continue;
            }
            $out[] = [
                // Feeds double-encode entities, so SimpleXML's own decode leaves
                // a literal "&amp;" behind — common in press-release titles,
                // and it would reach both the quiz title and the AI prompt.
                'title' => html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'url' => $url,
                'summary' => html_entity_decode(trim(strip_tags((string) $item->description)), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'published_at' => trim((string) $item->pubDate) ?: null,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Best-effort: download the article page and extract its paragraph text.
     * Returns null when the page can't be fetched or yields too little text, so
     * the caller can fall back to the RSS summary.
     */
    public function fetchPassage(string $url): ?string
    {
        try {
            $resp = Http::timeout(15)
                ->withHeaders(['User-Agent' => config('services.news.user_agent')])
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('News article fetch error: ' . $e->getMessage());
            return null;
        }

        if (! $resp->successful()) {
            return null;
        }

        return $this->extractParagraphs($resp->body());
    }

    /**
     * Site furniture that survives the length filter because it reads like a
     * sentence: newsletter pitches, tip lines, copyright and market-data
     * disclaimers. Matching paragraphs are dropped individually rather than
     * truncating the rest, so an inline promo mid-article costs only itself.
     */
    private const BOILERPLATE = [
        '/confidential news tip/i',
        '/sign up for (free|our|the) newsletter/i',
        '/delivered to your inbox/i',
        '/all rights reserved/i',
        '/data is (a real-time snapshot|delayed)/i',
        '/do not sell or share my personal information/i',
        '/contributed to this report/i',
        '/\b(privacy policy|terms of (use|service)|cookie settings)\b/i',
        '/^(sign up|subscribe|follow us|read more|related:)\b/i',
    ];

    /**
     * Pull readable paragraph text out of an article's HTML.
     */
    private function extractParagraphs(string $html): ?string
    {
        // drop script/style blocks so their contents don't leak into the text
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        // Prefer the <article> element: on press-release pages the page body
        // also carries links to *other* releases, which would otherwise be
        // scraped into the same passage. Pages without one (CNBC) fall back to
        // the whole document, as does an <article> too thin to be the real body.
        $paragraphs = [];
        if (preg_match('#<article\b[^>]*>(.*?)</article>#is', $html, $scoped)) {
            $paragraphs = $this->paragraphsIn($scoped[1]);
        }
        if (mb_strlen(implode(' ', $paragraphs)) < self::MIN_PASSAGE_CHARS) {
            $whole = $this->paragraphsIn($html);
            if (mb_strlen(implode(' ', $whole)) > mb_strlen(implode(' ', $paragraphs))) {
                $paragraphs = $whole;
            }
        }

        if (empty($paragraphs)) {
            return null;
        }

        $passage = implode("\n\n", $paragraphs);
        if (mb_strlen($passage) < self::MIN_PASSAGE_CHARS) {
            return null;
        }
        if (mb_strlen($passage) > self::MAX_PASSAGE_CHARS) {
            $passage = mb_substr($passage, 0, self::MAX_PASSAGE_CHARS);
            // trim back to the last sentence boundary for cleanliness
            $cut = max(mb_strrpos($passage, '. '), mb_strrpos($passage, "\n"));
            if ($cut !== false && $cut > self::MIN_PASSAGE_CHARS) {
                $passage = mb_substr($passage, 0, $cut + 1);
            }
        }

        return trim($passage);
    }

    /**
     * Paragraph texts inside a chunk of HTML: real sentences only, with
     * captions, bylines and site furniture filtered out.
     *
     * @return string[]
     */
    private function paragraphsIn(string $html): array
    {
        if (! preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $html, $m)) {
            return [];
        }

        $paragraphs = [];
        foreach ($m[1] as $raw) {
            $text = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $text = preg_replace('/\s+/u', ' ', $text);
            // keep only real sentences; skip captions, bylines, boilerplate
            if (mb_strlen($text) < 40) {
                continue;
            }
            foreach (self::BOILERPLATE as $pattern) {
                if (preg_match($pattern, $text)) {
                    continue 2;
                }
            }
            $paragraphs[] = $text;
        }

        return $paragraphs;
    }
}
