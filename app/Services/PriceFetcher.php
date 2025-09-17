<?php

namespace App\Services;

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

class PriceFetcher
{
    public function __construct(private ?Client $http = null)
    {
        $this->http = $http ?: new Client([
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; PriceWatcher/1.0)'
            ],
        ]);
    }

    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): array
    {
        $headers = [];
        if ($etag)         $headers['If-None-Match'] = $etag;
        if ($lastModified) $headers['If-Modified-Since'] = gmdate('D, d M Y H:i:s \G\M\T', strtotime($lastModified));

        $resp = $this->http->get($url, ['headers' => $headers]);
        if ($resp->getStatusCode() === 304) {
            return ['not_modified' => true];
        }

        $body = (string) $resp->getBody();
        $etag = $resp->getHeaderLine('ETag') ?: null;
        $lm   = $resp->getHeaderLine('Last-Modified') ?: null;

        [$price, $currency] = $this->parseHtml($body);

        return compact('price','currency','etag') + ['last_modified' => $lm];
    }

    private function parseHtml(string $html): array
    {
        $c = new Crawler($html);

        foreach ($c->filter('script[type="application/ld+json"]') as $node) {
            $json = json_decode($node->textContent, true);
            $items = is_array($json) ? $json : [$json];
            foreach ($items as $j) {
                $offers = $j['offers'] ?? null;
                if (!$offers) continue;
                $offersList = is_array($offers) && array_is_list($offers) ? $offers : [$offers];
                foreach ($offersList as $o) {
                    if (!isset($o['price'])) continue;
                    $price = (int) preg_replace('/\D+/', '', (string) $o['price']);
                    if ($price > 0) {
                        $cur = $o['priceCurrency'] ?? null;
                        return [$price, $cur ? strtoupper($cur) : null];
                    }
                }
            }
        }

        $nodes = $c->filter('[data-testid="ad-price-container"] h3, [data-testid="ad-price-container"]');
        if ($nodes->count()) {
            $text = trim($nodes->first()->text());
            $text = preg_replace('/\x{00A0}/u', ' ', $text);

            $price = (int) preg_replace('/\D+/', '', $text);

            $cur = null;
            if (preg_match('/(USD|EUR|UAH|\$|€|₴|грн)/iu', $text, $m)) {
                $sym = strtoupper($m[1]);
                $map = [
                    '$' => 'USD', 'USD' => 'USD',
                    '€' => 'EUR', 'EUR' => 'EUR',
                    '₴' => 'UAH', 'ГРН' => 'UAH', 'UAH' => 'UAH',
                ];
                $cur = $map[$sym] ?? $cur;
            }

            if ($price > 0) return [$price, $cur];
        }

        return [null, null];
    }

}
