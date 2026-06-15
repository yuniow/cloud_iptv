<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\HttpHelper;

class WebScraperService
{
    public static function extractM3u8FromWeb(string $url, array $options = []): ?array
    {
        $waitTime = $options['waitTime'] ?? 5000;

        $html = HttpHelper::get($url, [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);

        if (!$html) {
            return null;
        }

        $m3u8Links = [];

        if (preg_match_all('#https?://[^\s"\'<>]+\.m3u8[^\s"\'<>]*#i', $html, $matches)) {
            $m3u8Links = array_merge($m3u8Links, $matches[0]);
        }

        if (preg_match_all('#(?:src|source|url|href|file)\s*[=:]\s*["\']([^"\']*\.m3u8[^"\']*)["\']#i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                if (str_starts_with($url, '//')) {
                    $url = 'https:' . $url;
                } elseif (str_starts_with($url, '/')) {
                    $parsedUrl = parse_url($url);
                    $url = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '') . $url;
                }
                $m3u8Links[] = $url;
            }
        }

        $m3u8Links = array_unique($m3u8Links);

        if (empty($m3u8Links)) {
            return null;
        }

        return array_values($m3u8Links);
    }

    public static function validateM3u8(string $m3u8Url, array $options = []): bool
    {
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept' => 'application/vnd.apple.mpegurl, application/x-mpegURL, application/octet-stream, */*',
        ];

        if (isset($options['referer'])) {
            $headers['Referer'] = $options['referer'];
            $parsedUrl = parse_url($options['referer']);
            if (isset($parsedUrl['scheme'], $parsedUrl['host'])) {
                $headers['Origin'] = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
            }
        }

        $ch = curl_init($m3u8Url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "{$k}: {$v}", array_keys($headers), array_values($headers)),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_NOBODY => false,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $body = curl_multi_getcontent($ch);
        curl_close($ch);

        if ($httpCode !== 200) {
            return false;
        }

        $normalizedType = strtolower($contentType ?? '');
        if (str_contains($normalizedType, 'mpegurl') ||
            str_contains($normalizedType, 'application') ||
            str_contains($normalizedType, 'octet-stream') ||
            str_contains($normalizedType, 'text/plain')) {
            return true;
        }

        return str_contains($body ?? '', '#EXTM3U');
    }
}
