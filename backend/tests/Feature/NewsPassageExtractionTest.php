<?php

namespace Tests\Feature;

use App\Services\NewsService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guards the two filters that keep a scraped passage readable: scoping to
 * <article> so a press-release page's links to *other* releases stay out, and
 * dropping site furniture that is long enough to pass the length filter.
 */
class NewsPassageExtractionTest extends TestCase
{
    /** A paragraph long enough to clear the 40-char sentence filter. */
    private function body(string $lead): string
    {
        return $lead . ' ' . str_repeat('The quarterly figures were reviewed by the regional management team. ', 4);
    }

    public function test_article_scope_excludes_other_releases_on_the_page(): void
    {
        $html = '<html><body>'
            . '<p>' . $this->body('Navigation blurb outside the release body.') . '</p>'
            . '<article>'
            . '<p>' . $this->body('DALLAS, Oct. 21, 2026 /PRNewswire/ -- Acme today announced a new logistics hub.') . '</p>'
            . '<p>' . $this->body('The facility will serve retail clients across the region.') . '</p>'
            . '</article>'
            . '<p>' . $this->body('Acme also announced an unrelated partnership last month.') . '</p>'
            . '</body></html>';

        Http::fake(['example.com/*' => Http::response($html, 200)]);

        $passage = app(NewsService::class)->fetchPassage('https://example.com/release');

        $this->assertStringContainsString('new logistics hub', $passage);
        $this->assertStringContainsString('retail clients', $passage);
        $this->assertStringNotContainsString('Navigation blurb', $passage);
        $this->assertStringNotContainsString('unrelated partnership', $passage);
    }

    public function test_boilerplate_paragraphs_are_dropped(): void
    {
        $html = '<html><body>'
            . '<p>' . $this->body('EBay will lay off about nine percent of its workforce.') . '</p>'
            . '<p>' . $this->body('The company said it will keep hiring in priority areas.') . '</p>'
            . '<p>Got a confidential news tip? We want to hear from you and will follow up promptly.</p>'
            . '<p>Sign up for free newsletters and get more of our coverage delivered to your inbox.</p>'
            . '<p>&copy; 2026 Some Media, LLC. All Rights Reserved. A Some Media Company worldwide.</p>'
            . '<p>Data is a real-time snapshot. Data is delayed at least fifteen minutes everywhere.</p>'
            . '</body></html>';

        Http::fake(['example.com/*' => Http::response($html, 200)]);

        $passage = app(NewsService::class)->fetchPassage('https://example.com/story');

        $this->assertStringContainsString('lay off about nine percent', $passage);
        $this->assertStringContainsString('priority areas', $passage);
        $this->assertStringNotContainsString('confidential news tip', $passage);
        $this->assertStringNotContainsString('free newsletters', $passage);
        $this->assertStringNotContainsString('All Rights Reserved', $passage);
        $this->assertStringNotContainsString('real-time snapshot', $passage);
    }

    /** A thin <article> wrapper must not starve a passage the page does carry. */
    public function test_thin_article_wrapper_falls_back_to_whole_page(): void
    {
        $html = '<html><body>'
            . '<article><p>A short teaser that is still over forty characters long.</p></article>'
            . '<div>'
            . '<p>' . $this->body('The board approved the annual operating budget on Tuesday.') . '</p>'
            . '<p>' . $this->body('Executives outlined plans to expand the distribution network.') . '</p>'
            . '</div>'
            . '</body></html>';

        Http::fake(['example.com/*' => Http::response($html, 200)]);

        $passage = app(NewsService::class)->fetchPassage('https://example.com/story');

        $this->assertStringContainsString('annual operating budget', $passage);
        $this->assertStringContainsString('distribution network', $passage);
    }

    /** Feeds double-encode entities; a literal "&amp;" must not reach the title. */
    public function test_rss_entities_are_decoded(): void
    {
        $rss = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><item>'
            . '<title>Smith &amp;amp; Co. Receives $6.5 Million Grant</title>'
            . '<link>https://example.com/release</link>'
            . '<description>Smith &amp;amp; Co. announced the award.</description>'
            . '</item></channel></rss>';

        Http::fake(['feeds.example.com/*' => Http::response($rss, 200)]);
        config(['services.news.feeds' => ['press_release' => 'https://feeds.example.com/pr.xml']]);

        $articles = app(NewsService::class)->recentArticles('press_release');

        $this->assertSame('Smith & Co. Receives $6.5 Million Grant', $articles[0]['title']);
        $this->assertSame('Smith & Co. announced the award.', $articles[0]['summary']);
    }

    public function test_source_label_comes_from_the_feed_host(): void
    {
        config(['services.news.feeds' => [
            'press_release' => 'https://www.prnewswire.com/rss/x.rss',
            'us_business' => 'https://www.cnbc.com/id/1/device/rss/rss.html',
            'business' => 'https://feeds.bbci.co.uk/news/business/rss.xml',
            'mystery' => 'https://news.unlisted-host.test/rss.xml',
        ]]);

        $news = app(NewsService::class);

        $this->assertSame('press_release', $news->defaultTopic());
        $this->assertSame('PR Newswire', $news->sourceFor('press_release'));
        $this->assertSame('CNBC', $news->sourceFor('us_business'));
        $this->assertSame('BBC', $news->sourceFor('business'));
        $this->assertSame('news.unlisted-host.test', $news->sourceFor('mystery'));
    }
}
