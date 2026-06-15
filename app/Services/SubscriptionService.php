<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\HttpHelper;
use App\Helpers\EncodingHelper;

class SubscriptionService
{
    public function import(string $url, string $format = 'auto'): array
    {
        $response = HttpHelper::get($url, [], 30);
        if (!$response) {
            return ['success' => false, 'error' => '无法获取订阅内容', 'channels' => [], 'total' => 0];
        }

        $content = EncodingHelper::autoDecode($response);

        if ($format === 'auto') {
            $format = $this->autoDetectFormat($content, $url);
        }

        return match ($format) {
            'm3u', 'm3u8' => $this->parseM3u($content),
            'txt' => $this->parseTxt($content),
            default => ['success' => false, 'error' => '不支持的格式', 'channels' => [], 'total' => 0],
        };
    }

    private function autoDetectFormat(string $content, string $url): string
    {
        $lowerUrl = strtolower($url);
        if (str_ends_with($lowerUrl, '.m3u') || str_ends_with($lowerUrl, '.m3u8')) {
            return 'm3u';
        }
        if (str_ends_with($lowerUrl, '.txt')) {
            return 'txt';
        }

        if (str_contains($content, '#EXTM3U') || str_contains($content, '#EXTINF')) {
            return 'm3u';
        }
        if (preg_match('/^.*,.*#genre#$/m', $content) || preg_match('/^.*,https?:\/\/.*/m', $content)) {
            return 'txt';
        }

        return 'm3u';
    }

    public function parseM3u(string $content): array
    {
        $channels = [];
        $lines = explode("\n", $content);
        $currentChannel = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#EXTINF:')) {
                $currentChannel = $this->parseExtInf($line);
            } elseif (!str_starts_with($line, '#') && $currentChannel !== null) {
                $currentChannel['url'] = $line;
                $channels[] = $currentChannel;
                $currentChannel = null;
            } elseif (!str_starts_with($line, '#') && $this->isValidUrl($line)) {
                $channels[] = [
                    'name' => basename($line),
                    'url' => $line,
                    'group' => '未分组',
                ];
            }
        }

        return [
            'success' => true,
            'channels' => $channels,
            'total' => count($channels),
        ];
    }

    private function parseExtInf(string $line): array
    {
        $channel = [
            'name' => '',
            'url' => '',
            'group' => '未分组',
            'logo' => '',
            'tvg_id' => '',
            'tvg_name' => '',
        ];

        if (preg_match('/tvg-id="([^"]*)"/', $line, $m)) {
            $channel['tvg_id'] = $m[1];
        }
        if (preg_match('/tvg-name="([^"]*)"/', $line, $m)) {
            $channel['tvg_name'] = $m[1];
        }
        if (preg_match('/tvg-logo="([^"]*)"/', $line, $m)) {
            $channel['logo'] = $m[1];
        }
        if (preg_match('/group-title="([^"]*)"/', $line, $m)) {
            $channel['group'] = $m[1] ?: '未分组';
        }

        $lastComma = strrpos($line, ',');
        if ($lastComma !== false) {
            $name = substr($line, $lastComma + 1);
            $channel['name'] = trim($name);
        }

        return $channel;
    }

    public function parseTxt(string $content): array
    {
        $channels = [];
        $lines = explode("\n", $content);
        $currentGroup = '未分组';

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(.+),#genre#$/', $line, $m)) {
                $currentGroup = trim($m[1]);
                continue;
            }

            $parts = explode(',', $line, 2);
            if (count($parts) === 2) {
                $name = trim($parts[0]);
                $url = trim($parts[1]);

                if ($name !== '' && $this->isValidUrl($url)) {
                    $channels[] = [
                        'name' => $name,
                        'url' => $url,
                        'group' => $currentGroup,
                    ];
                }
            }
        }

        return [
            'success' => true,
            'channels' => $channels,
            'total' => count($channels),
        ];
    }

    private function isValidUrl(string $url): bool
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }
}
