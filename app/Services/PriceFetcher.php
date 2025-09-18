<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class PriceFetcher
{
    public function __construct(private ?Client $http = null)
    {
        $this->http = $http ?: new Client([
            'timeout' => 12,
            'headers' => [
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'uk-UA,uk;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control'   => 'no-cache',
                'Pragma'          => 'no-cache',
            ],
            'allow_redirects' => true,
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

        $node = $c->filter('[data-testid="ad-price-container"] h3');
        if ($node->count() > 0) {
            $text = trim($node->first()->text(null, true));
            $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $text);

            if (preg_match('/(\d[\d \.,]{0,12})\s*(грн\.?|uah|usd|eur|₴|\$|€)/iu', $text, $m)) {
                $price = self::toIntPrice($m[1]);
                $cur   = $this->normalizeCurrency($m[2]);
                if ($price > 0) {
                    return [$price, $cur];
                }
            }

            if (preg_match('/(догов[оі]рна|безкоштовно)/iu', $text)) {
                return [null, null];
            }
        }

        if (preg_match('~data-testid=["\']ad-price-container["\'][^>]*>.*?<h3[^>]*>\s*([^<]+)\s*</h3>~si', $html, $m)) {
            $txt = html_entity_decode(trim($m[1]));
            $txt = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $txt);
            if (preg_match('/(\d[\d \.,]{0,12})\s*(грн\.?|uah|usd|eur|₴|\$|€)/iu', $txt, $mm)) {
                $price = self::toIntPrice($mm[1]);
                $cur   = $this->normalizeCurrency($mm[2]);
                if ($price > 0) {
                    return [$price, $cur];
                }
            }
        }

        return [null, null];
    }

    private function normalizeCurrency(string $raw): ?string
    {
        $sym = mb_strtolower(rtrim($raw, '.'), 'UTF-8');
        return match ($sym) {
            'грн','uah','₴' => 'UAH',
            'usd','$'       => 'USD',
            'eur','€'       => 'EUR',
            default         => null,
        };
    }

    private static function toIntPrice(string $raw): int
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') return 0;
        $digits = substr($digits, 0, 10);
        $val = (int) $digits;
        return ($val > 0 && $val < 1_000_000_000) ? $val : 0;
    }


}
