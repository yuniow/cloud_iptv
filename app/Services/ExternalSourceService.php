<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\HttpHelper;
use App\Helpers\EncodingHelper;
use PDO;

class ExternalSourceService
{
    public static function loadSources(): array
    {
        $pdo = Database::getInstance();
        $rows = $pdo->query("SELECT * FROM external_sources ORDER BY created_at ASC")->fetchAll();

        $sources = [];
        foreach ($rows as $row) {
            $sources[] = [
                'name' => $row['name'],
                'group' => $row['group_name'],
                'mode' => $row['mode'],
                'webUrl' => $row['web_url'],
                'm3u8Url' => $row['m3u8_url'],
                'subscriptionUrl' => $row['subscription_url'],
                'logo' => $row['logo'],
                'enabled' => (bool)$row['enabled'],
                'autoRefresh' => (bool)$row['auto_refresh'],
                'refreshInterval' => (int)$row['refresh_interval'],
                'updateOnStartup' => (bool)$row['update_on_startup'],
                'lastUpdated' => $row['last_updated'],
                'extractOptions' => json_decode($row['extract_options'] ?? '{}', true),
                'parsedChannels' => $row['parsed_channels'] ? json_decode($row['parsed_channels'], true) : null,
                'description' => $row['description'],
                'proxy' => $row['proxy'] ?? '',
            ];
        }

        return [
            'enabled' => true,
            'includeInPlaylists' => true,
            'updateOnStartup' => true,
            'sources' => $sources,
        ];
    }

    public static function saveSources(array $config): array
    {
        try {
            $pdo = Database::getInstance();
            $pdo->exec("DELETE FROM external_sources");
            $stmt = $pdo->prepare("INSERT INTO external_sources (id, name, group_name, mode, web_url, m3u8_url, subscription_url, logo, enabled, auto_refresh, refresh_interval, update_on_startup, last_updated, extract_options, parsed_channels, description, proxy, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))");

            foreach ($config['sources'] ?? [] as $source) {
                $id = md5(($source['name'] ?? '') . ($source['subscriptionUrl'] ?? '') . ($source['webUrl'] ?? '') . ($source['m3u8Url'] ?? ''));
                $stmt->execute([
                    $id,
                    $source['name'] ?? '',
                    $source['group'] ?? '未分组',
                    $source['mode'] ?? 'direct',
                    $source['webUrl'] ?? '',
                    $source['m3u8Url'] ?? '',
                    $source['subscriptionUrl'] ?? '',
                    $source['logo'] ?? '',
                    ($source['enabled'] ?? true) ? 1 : 0,
                    ($source['autoRefresh'] ?? true) ? 1 : 0,
                    $source['refreshInterval'] ?? 240,
                    ($source['updateOnStartup'] ?? true) ? 1 : 0,
                    $source['lastUpdated'] ?? null,
                    json_encode($source['extractOptions'] ?? []),
                    isset($source['parsedChannels']) ? json_encode($source['parsedChannels']) : null,
                    $source['description'] ?? '',
                    $source['proxy'] ?? '',
                ]);
            }
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function addSource(array $sourceConfig): array
    {
        $config = self::loadSources();
        $config['sources'][] = [
            'name' => $sourceConfig['name'] ?? '',
            'group' => $sourceConfig['group'] ?? '其他',
            'mode' => $sourceConfig['mode'] ?? 'direct',
            'webUrl' => $sourceConfig['webUrl'] ?? '',
            'm3u8Url' => $sourceConfig['m3u8Url'] ?? '',
            'subscriptionUrl' => $sourceConfig['subscriptionUrl'] ?? '',
            'logo' => $sourceConfig['logo'] ?? '',
            'enabled' => ($sourceConfig['enabled'] ?? true) !== false,
            'autoRefresh' => ($sourceConfig['autoRefresh'] ?? true) !== false,
            'refreshInterval' => $sourceConfig['refreshInterval'] ?? 240,
            'updateOnStartup' => ($sourceConfig['updateOnStartup'] ?? true) !== false,
            'lastUpdated' => null,
            'parsedChannels' => null,
            'extractOptions' => $sourceConfig['extractOptions'] ?? ['waitTime' => 5000, 'headless' => true],
            'proxy' => $sourceConfig['proxy'] ?? '',
        ];
        return self::saveSources($config);
    }

    public static function removeSource(int $index): array
    {
        $config = self::loadSources();
        if (isset($config['sources'][$index])) {
            array_splice($config['sources'], $index, 1);
            self::saveSources($config);
            \App\Services\UpdateService::regeneratePlaylists();
            return ['success' => true];
        }
        return ['success' => false, 'message' => '索引无效'];
    }

    public static function updateSource(int $index): array
    {
        $config = self::loadSources();
        if (!isset($config['sources'][$index])) {
            return ['success' => false, 'message' => '索引无效'];
        }
        $source = &$config['sources'][$index];
        if (!$source['enabled']) {
            return ['success' => false, 'message' => '源已禁用'];
        }

        if (($source['mode'] ?? '') === 'subscription') {
            return self::updateSubscriptionSource($index);
        }

        if (empty($source['webUrl']) && !empty($source['m3u8Url'])) {
            $source['lastUpdated'] = date('c');
            self::saveSources($config);
            \App\Services\UpdateService::regeneratePlaylists();
            return ['success' => true, 'm3u8Url' => $source['m3u8Url']];
        }

        $html = HttpHelper::get($source['webUrl']);
        if (!$html) {
            return ['success' => false, 'message' => '无法获取网页内容'];
        }

        $urls = self::extractM3u8Urls($html);
        if (empty($urls)) {
            return ['success' => false, 'message' => '未能提取到m3u8链接'];
        }

        usort($urls, fn($a, $b) => strlen($b) - strlen($a));
        $bestUrl = $urls[0];

        $source['m3u8Url'] = $bestUrl;
        $source['lastUpdated'] = date('c');
        self::saveSources($config);
        \App\Services\UpdateService::regeneratePlaylists();
        return ['success' => true, 'm3u8Url' => $bestUrl];
    }

    public static function updateSubscriptionSource(int $index): array
    {
        $config = self::loadSources();
        if (!isset($config['sources'][$index])) {
            return ['success' => false, 'message' => '索引无效'];
        }
        $source = &$config['sources'][$index];
        if (empty($source['subscriptionUrl'])) {
            return ['success' => false, 'message' => '未填写订阅地址'];
        }

        $channels = self::fetchAndParseM3u($source['subscriptionUrl']);
        if ($channels === null) {
            return ['success' => false, 'message' => '获取订阅内容失败'];
        }

        $source['parsedChannels'] = $channels;
        $source['lastUpdated'] = date('c');
        self::saveSources($config);

        // 重建 interface.txt / txt / channel-sources.json
        \App\Services\UpdateService::regeneratePlaylists();

        return ['success' => true, 'channelCount' => count($channels)];
    }

    public static function setM3u8Url(int $index, string $m3u8Url): array
    {
        $config = self::loadSources();
        if (!isset($config['sources'][$index])) {
            return ['success' => false, 'message' => '索引无效'];
        }
        $config['sources'][$index]['m3u8Url'] = $m3u8Url;
        $config['sources'][$index]['lastUpdated'] = date('c');
        return self::saveSources($config);
    }

    public static function fetchAndParseM3u(string $url): ?array
    {
        $mirrors = self::getGithubMirrors($url);
        foreach ($mirrors as $mirrorUrl) {
            $content = HttpHelper::get($mirrorUrl);
            if ($content) {
                $decoded = EncodingHelper::autoDecode($content);
                $channels = self::parsePlaylistContent($decoded);
                if (!empty($channels)) {
                    return $channels;
                }
            }
        }
        return null;
    }

    public static function parsePlaylistContent(string $content): array
    {
        if (preg_match('/^﻿?#EXTM3U/i', $content) || preg_match('/#EXTINF:/i', $content)) {
            return self::parseM3uContent($content);
        }
        return self::parseTxtContent($content);
    }

    public static function parseM3uContent(string $content): array
    {
        $lines = array_values(array_map('trim', explode("\n", $content)));
        $lines = array_values(array_filter($lines));
        $channels = [];

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (!is_string($line) || !str_starts_with($line, '#EXTINF:')) {
                continue;
            }

            preg_match('/group-title="([^"]*)"/', $line, $groupMatch);
            preg_match('/tvg-logo="([^"]*)"/', $line, $logoMatch);
            preg_match('/,(.+)$/', $line, $nameMatch);

            $url = '';
            for ($j = $i + 1; $j < count($lines); $j++) {
                if (!str_starts_with($lines[$j], '#')) {
                    $url = $lines[$j];
                    break;
                }
            }

            if ($url && !empty($nameMatch[1])) {
                $channels[] = [
                    'name' => trim($nameMatch[1]),
                    'group' => $groupMatch[1] ?? '未分组',
                    'logo' => $logoMatch[1] ?? '',
                    'url' => $url,
                ];
            }
        }
        return $channels;
    }

    public static function parseTxtContent(string $content): array
    {
        $lines = array_values(array_map('trim', explode("\n", $content)));
        $lines = array_values(array_filter($lines));
        $channels = [];
        $currentGroup = '';

        foreach ($lines as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }
            $commaIndex = strpos($line, ',');
            if ($commaIndex === false) {
                continue;
            }
            $name = trim(substr($line, 0, $commaIndex));
            $rest = trim(substr($line, $commaIndex + 1));
            if ($name === '' || $rest === '') {
                continue;
            }
            if (strtolower($rest) === '#genre#') {
                $currentGroup = $name;
                continue;
            }
            $url = trim(explode('#', $rest)[0]);
            if ($url === '' || !str_contains($url, '://')) {
                continue;
            }
            $channels[] = [
                'name' => $name,
                'group' => $currentGroup,
                'logo' => '',
                'url' => $url,
            ];
        }
        return $channels;
    }

    public static function getValidChannels(): array
    {
        $config = self::loadSources();
        $groupMap = [];

        foreach ($config['sources'] as $source) {
            if (!($source['enabled'] ?? false)) {
                continue;
            }

            if (($source['mode'] ?? '') === 'subscription' && is_array($source['parsedChannels'] ?? null)) {
                foreach ($source['parsedChannels'] as $ch) {
                    $group = $ch['group'] ?? $source['group'] ?? '未分组';
                    if (!isset($groupMap[$group])) {
                        $groupMap[$group] = ['name' => $group, 'dataList' => []];
                    }
                    $groupMap[$group]['dataList'][] = [
                        'name' => $ch['name'] ?? '',
                        'url' => $ch['url'] ?? '',
                        'logo' => $ch['logo'] ?? '',
                    ];
                }
                continue;
            }

            if (!empty($source['m3u8Url'])) {
                $group = $source['group'] ?? '未分组';
                if (!isset($groupMap[$group])) {
                    $groupMap[$group] = ['name' => $group, 'dataList' => []];
                }
                $groupMap[$group]['dataList'][] = [
                    'name' => $source['name'] ?? '',
                    'url' => $source['m3u8Url'],
                    'logo' => $source['logo'] ?? '',
                ];
            }
        }
        return array_values($groupMap);
    }

    private static function extractM3u8Urls(string $html): array
    {
        $urls = [];
        if (preg_match_all('#https?://[^\s"\'<>]+\.m3u8[^\s"\'<>]*#i', $html, $matches)) {
            $urls = array_merge($urls, $matches[0]);
        }
        return array_unique($urls);
    }

    private static function getGithubMirrors(string $url): array
    {
        if (!str_contains($url, 'raw.githubusercontent.com')) {
            return [$url];
        }
        return [
            $url,
            str_replace('https://raw.githubusercontent.com/', 'https://ghfast.top/https://raw.githubusercontent.com/', $url),
            str_replace('https://raw.githubusercontent.com/', 'https://gh-proxy.com/https://raw.githubusercontent.com/', $url),
        ];
    }
}
