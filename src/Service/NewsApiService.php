<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class NewsApiService
{
    private const GOOGLE_NEWS_RSS = 'https://news.google.com/rss/search';
    private const CACHE_TTL = 3600; // 1 hour

    private const SECTOR_KEYWORDS = [
        'Technologie' => 'technologie startup innovation numérique digital',
        'Santé' => 'santé médecine hôpital pharmaceutique',
        'Éducation' => 'éducation formation université enseignement',
        'Finance' => 'finance banque investissement économie',
        'Commerce' => 'commerce vente distribution export',
        'Agriculture' => 'agriculture agroalimentaire agricole récolte',
        'Tourisme' => 'tourisme voyage hôtellerie touristes',
        'Immobilier' => 'immobilier construction logement BTP',
        'Transport' => 'transport logistique mobilité',
        'Énergie' => 'énergie renouvelable solaire électricité',
        'Alimentation' => 'alimentation agroalimentaire restaurant',
        'Mode & Textile' => 'mode textile habillement confection',
        'Industrie' => 'industrie fabrication usine production',
        'Services' => 'services entreprise conseil',
        'Artisanat' => 'artisanat artisan patrimoine',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
    ) {
    }

    public function isConfigured(): bool
    {
        return true; // Google News RSS is free, no key needed
    }

    /** @return array<int, array<string, string>> */
    public function getNewsBySector(string $secteur, int $limit = 6): array
    {
        $cacheKey = 'news_tn_' . md5($secteur . '_v8_rss');

        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($secteur, $limit): array {
                $item->expiresAfter(self::CACHE_TTL);

                $articles = [];

                // Query 1: Sector + Tunisia
                $keywords = self::SECTOR_KEYWORDS[$secteur] ?? $secteur;
                $firstKeyword = explode(' ', $keywords)[0];
                $articles = array_merge($articles, $this->fetchGoogleNewsRss("$firstKeyword Tunisie", $limit));

                // Query 2: Sector + Afrique du Nord (broader, if we need more)
                if (count($articles) < $limit) {
                    $remaining = $limit - count($articles);
                    $northAfricaArticles = $this->fetchGoogleNewsRss("$firstKeyword Afrique du Nord", $remaining + 2);
                    // Avoid duplicates by title
                    $existingTitles = array_map(fn($a) => $a['title'], $articles);
                    foreach ($northAfricaArticles as $article) {
                        if (!in_array($article['title'], $existingTitles, true) && count($articles) < $limit) {
                            $articles[] = $article;
                            $existingTitles[] = $article['title'];
                        }
                    }
                }

                // Query 3: Second keyword + Tunisie for more variety
                if (count($articles) < $limit) {
                    $keywordParts = explode(' ', $keywords);
                    $secondKeyword = $keywordParts[1] ?? $keywordParts[0];
                    $remaining = $limit - count($articles);
                    $moreArticles = $this->fetchGoogleNewsRss("$secondKeyword Tunisie", $remaining + 2);
                    $existingTitles = array_map(fn($a) => $a['title'], $articles);
                    foreach ($moreArticles as $article) {
                        if (!in_array($article['title'], $existingTitles, true) && count($articles) < $limit) {
                            $articles[] = $article;
                            $existingTitles[] = $article['title'];
                        }
                    }
                }

                return array_slice($articles, 0, $limit);
            });
        } catch (\Throwable $e) {
            $this->logger->error('News service error: ' . $e->getMessage());
            return [];
        }
    }

    /** @return array<int, array<string, string|null>> */
    private function fetchGoogleNewsRss(string $query, int $limit): array
    {
        try {
            $url = self::GOOGLE_NEWS_RSS . '?' . http_build_query([
                'q' => $query,
                'hl' => 'fr',
                'gl' => 'TN',
                'ceid' => 'TN:fr',
            ]);

            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 10,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; NajahniBot/1.0)',
                    'Accept' => 'application/rss+xml, application/xml, text/xml',
                ],
            ]);

            $content = $response->getContent(false);

            if (empty($content)) {
                return [];
            }

            // Parse RSS XML
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($content);
            if ($xml === false) {
                $this->logger->warning('Failed to parse Google News RSS XML');
                return [];
            }

            $articles = [];
            $items = $xml->channel->item ?? [];

            foreach ($items as $item) {
                if (count($articles) >= $limit) {
                    break;
                }

                $title = (string)($item->title ?? '');
                $link = (string)($item->link ?? '');
                $pubDate = (string)($item->pubDate ?? '');
                $source = (string)($item->source ?? '');
                $description = strip_tags((string)($item->description ?? ''));

                // Clean up description (Google News often returns HTML snippet)
                $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');
                $description = trim(preg_replace('/\s+/', ' ', $description));

                if (empty($title) || empty($link)) {
                    continue;
                }

                $articles[] = [
                    'title' => $title,
                    'description' => $description ?: $title,
                    'url' => $link,
                    'source' => $source ?: 'Google News',
                    'publishedAt' => $pubDate ? date('Y-m-d\TH:i:s', strtotime($pubDate)) : null,
                    'image' => null,
                    'type' => 'news',
                ];
            }

            return $articles;
        } catch (\Throwable $e) {
            $this->logger->info('Google News RSS error for "' . $query . '": ' . $e->getMessage());
            return [];
        }
    }
}
