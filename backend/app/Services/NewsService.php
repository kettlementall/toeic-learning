<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches recent BBC news articles for the news-reading quiz. Uses public RSS
 * feeds (no API key) to list recent items, then best-effort scrapes the article
 * page for the full passage, falling back to the RSS summary when scraping
 * fails. All failures degrade gracefully (empty/null) rather than throwing,
 * mirroring DictionaryService.
 */
class NewsService
{
    /** Minimum usable passage length; shorter -> fall back to the summary. */
    private const MIN_PASSAGE_CHARS = 400;

    /** Cap the passage so the AI prompt stays within a reasonable token budget. */
    private const MAX_PASSAGE_CHARS = 1800;

    /**
     * Topic keys offered to the user, mapped to BBC RSS feeds.
     *
     * @return array<string,string>
     */
    public function feeds(): array
    {
        return (array) config('services.news.feeds', []);
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
                'title' => $title,
                'url' => $url,
                'summary' => trim(strip_tags((string) $item->description)),
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
     * Pull readable paragraph text out of an article's HTML.
     */
    private function extractParagraphs(string $html): ?string
    {
        // drop script/style blocks so their contents don't leak into the text
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        if (! preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $html, $m)) {
            return null;
        }

        $paragraphs = [];
        foreach ($m[1] as $raw) {
            $text = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $text = preg_replace('/\s+/u', ' ', $text);
            // keep only real sentences; skip captions, bylines, boilerplate
            if (mb_strlen($text) >= 40) {
                $paragraphs[] = $text;
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
}
