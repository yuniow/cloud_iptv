<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\HttpHelper;

class BuiltInSourceService
{
    private static string $configPath = '';

    private static function getConfigPath(): string
    {
        if (self::$configPath === '') {
            self::$configPath = dirname(__DIR__, 2) . '/config/built-in-sources.json';
        }
        return self::$configPath;
    }

    public static function getSourceConfig(): array
    {
        $path = self::getConfigPath();
        if (!file_exists($path)) {
            return ['enabled' => true, 'sources' => []];
        }
        return json_decode(file_get_contents($path), true) ?? ['enabled' => true, 'sources' => []];
    }

    public static function getChannels(): array
    {
        $config = self::getSourceConfig();
        if (!($config['enabled'] ?? false)) {
            return [];
        }

        $channels = [];
        foreach ($config['sources'] as $source) {
            if (!($source['enabled'] ?? false)) {
                continue;
            }
            if (($source['mode'] ?? '') === 'fetch' && !empty($source['m3u8Url'])) {
                $group = $source['group'] ?? '未分组';
                if (!isset($channels[$group])) {
                    $channels[$group] = ['name' => $group, 'dataList' => []];
                }
                $channels[$group]['dataList'][] = [
                    'name' => $source['name'] ?? '',
                    'playURL' => $source['m3u8Url'],
                    'logo' => $source['logo'] ?? '',
                    'source' => 'built-in',
                ];
            }
        }
        return array_values($channels);
    }

    public static function refreshBuiltInSources(): void
    {
        $config = self::getSourceConfig();
        if (!($config['enabled'] ?? false)) {
            return;
        }

        $changed = false;
        foreach ($config['sources'] as &$source) {
            if (!($source['enabled'] ?? false)) {
                continue;
            }
            if (($source['mode'] ?? '') !== 'fetch' || empty($source['webUrl'])) {
                continue;
            }

            $html = HttpHelper::get($source['webUrl']);
            if (!$html) {
                continue;
            }

            $urls = [];
            if (preg_match_all('#https?://[^\s"\'<>]+\.m3u8[^\s"\'<>]*#i', $html, $matches)) {
                $urls = $matches[0];
            }

            if (!empty($urls)) {
                usort($urls, fn($a, $b) => strlen($b) - strlen($a));
                $source['m3u8Url'] = $urls[0];
                $source['lastUpdated'] = date('c');
                $changed = true;
            }
        }
        unset($source);

        if ($changed) {
            $path = self::getConfigPath();
            $tmp = $path . '.tmp';
            file_put_contents($tmp, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            rename($tmp, $path);
        }
    }
}
