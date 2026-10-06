<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Api;

/**
 * The lead paragraph of a wiki page, from the Wikimedia REST API - free, no key.
 *
 * Used twice: against Wikivoyage, the travel guide, and against Wikipedia as
 * the fallback for a place that has no guide page yet.
 */
final class WikiSummary
{
    public function __construct(
        private readonly Http $http,
        private readonly string $host = 'en.wikivoyage.org',
    ) {
    }

    /**
     * @return array{title: string, description: string, extract: string, url: string, source: string}|null
     * @throws ApiUnavailable
     */
    public function summary(string $title): ?array
    {
        $page = \rawurlencode(\str_replace(' ', '_', \trim($title)));

        try {
            $data = $this->http->getJson("https://{$this->host}/api/rest_v1/page/summary/{$page}");
        } catch (ApiUnavailable $e) {
            if ($e->isNotFound()) {
                return null;
            }

            throw $e;
        }

        // A disambiguation page is not a guide; the caller should try something more specific.
        if (($data['type'] ?? '') === 'disambiguation') {
            return null;
        }

        /** @var array{desktop?: array{page?: string}} $urls */
        $urls = $data['content_urls'] ?? [];

        return [
            'title' => (string) ($data['title'] ?? $title),
            'description' => (string) ($data['description'] ?? ''),
            'extract' => self::trim((string) ($data['extract'] ?? ''), 900),
            'url' => (string) ($urls['desktop']['page'] ?? ''),
            'source' => $this->host,
        ];
    }

    private static function trim(string $text, int $limit): string
    {
        return \mb_strlen($text) <= $limit ? $text : \rtrim(\mb_substr($text, 0, $limit - 1)) . '…';
    }
}
