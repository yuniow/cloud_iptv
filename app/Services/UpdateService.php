<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;

class UpdateService
{
    private static string $dataDir = '';

    private static function getDataDir(): string
    {
        if (self::$dataDir === '') {
            self::$dataDir = dirname(__DIR__, 2) . '/data';
        }
        return self::$dataDir;
    }

    private static function writeAtomically(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $content);
        rename($tmp, $path);
    }

    public static function runUpdate(int $hours, array $options = []): void
    {
        $startupMode = $options['startupMode'] ?? false;
        $regenerateOnly = $options['regenerateOnly'] ?? false;

        if ($regenerateOnly) {
            self::regeneratePlaylists();
            return;
        }

        $enableMigu = AppConfig::get('enableMigu', true);
        $enableBuiltInSources = AppConfig::get('enableBuiltInSources', true);
        $enableBuiltInSubscriptions = AppConfig::get('enableBuiltInSubscriptions', true);

        $allChannels = [];

        if ($enableMigu) {
            $channels = MiguService::refreshChannels();
            MiguService::setCachedChannels($channels);
            foreach ($channels as &$g) {
                foreach ($g['dataList'] as &$ch) { $ch['source'] = '咪咕'; }
            }
            unset($g, $ch);
            $allChannels = array_merge($allChannels, $channels);
        } else {
            $channels = MiguService::getCachedChannels();
            foreach ($channels as &$g) {
                foreach ($g['dataList'] as &$ch) { $ch['source'] = '咪咕'; }
            }
            unset($g, $ch);
            $allChannels = array_merge($allChannels, $channels);
        }

        if ($enableBuiltInSources) {
            $builtInChannels = BuiltInSourceService::getChannels();
            foreach ($builtInChannels as &$g) {
                foreach ($g['dataList'] as &$ch) { $ch['source'] = '内置'; }
            }
            unset($g, $ch);
            $allChannels = array_merge($allChannels, $builtInChannels);
        }

        if ($enableBuiltInSubscriptions) {
            $externalChannels = ExternalSourceService::getValidChannels();
            foreach ($externalChannels as &$g) {
                foreach ($g['dataList'] as &$ch) { $ch['source'] = '外部'; }
            }
            unset($g, $ch);
            $allChannels = array_merge($allChannels, $externalChannels);
        }

        self::generateInterfaceTxt($allChannels);
        self::generateInterfaceTXTTxt($allChannels);
        self::generatePlaybackXml($allChannels);
        self::generateChannelSources($allChannels);

        if ($enableMigu) {
            try {
                $sportsChannels = SportsService::fetchSportsChannels();
                if (!empty($sportsChannels)) {
                    self::appendSportsToPlaylists($sportsChannels);
                }
            } catch (\Exception $e) {
                // PE update failure is non-critical
            }
        }
    }

    public static function regeneratePlaylists(): void
    {
        $enableMigu = AppConfig::get('enableMigu', true);
        $enableBuiltInSources = AppConfig::get('enableBuiltInSources', true);
        $enableBuiltInSubscriptions = AppConfig::get('enableBuiltInSubscriptions', true);

        $allChannels = [];

        if ($enableMigu) {
            $channels = MiguService::getCachedChannels();
            if (empty($channels)) {
                $channels = MiguService::refreshChannels();
                MiguService::setCachedChannels($channels);
            }
            $allChannels = array_merge($allChannels, $channels);
        }

        if ($enableBuiltInSources) {
            $builtInChannels = BuiltInSourceService::getChannels();
            $allChannels = array_merge($allChannels, $builtInChannels);
        }

        if ($enableBuiltInSubscriptions) {
            $externalChannels = ExternalSourceService::getValidChannels();
            $allChannels = array_merge($allChannels, $externalChannels);
        }

        self::generateInterfaceTxt($allChannels);
        self::generateInterfaceTXTTxt($allChannels);

        if ($enableMigu) {
            try {
                $cached = SportsService::getCachedChannels();
                if (!empty($cached)) {
                    self::appendSportsToPlaylists($cached);
                }
            } catch (\Exception $e) {}
        }
    }

    public static function generateInterfaceTxt(array $allChannels): void
    {
        $pass = AppConfig::get('pass', '');
        $host = AppConfig::get('host', '');
        $port = AppConfig::get('port', 1905);
        $baseUrl = $host ?: "http://localhost:{$port}";
        if ($pass) {
            $baseUrl .= "/{$pass}";
        }

        $content = "#EXTM3U x-tvg-url=\"{$baseUrl}/playback.xml\" catchup=\"append\" catchup-source=\"?playbackbegin=\${(b)yyyyMMddHHmmss}&playbackend=\${(e)yyyyMMddHHmmss}\"\n";

        foreach ($allChannels as $group) {
            $groupName = $group['name'] ?? '未分组';
            foreach ($group['dataList'] as $channel) {
                $name = $channel['name'] ?? '';
                $logo = $channel['pics']['highResolutionH'] ?? $channel['logo'] ?? '';
                $pID = $channel['pID'] ?? '';
                $url = $channel['url'] ?? '';
                $playURL = $channel['playURL'] ?? '';

                if (!empty($playURL)) {
                    $playUrl = str_replace('${replace}', $baseUrl, $playURL);
                } elseif (!empty($url)) {
                    $playUrl = str_replace('${replace}', $baseUrl, $url);
                } elseif ($pID) {
                    $playUrl = "{$baseUrl}/{$pID}";
                } else {
                    continue;
                }

                $content .= "#EXTINF:-1 tvg-id=\"{$name}\" tvg-name=\"{$name}\" tvg-logo=\"{$logo}\" group-title=\"{$groupName}\",{$name}\n";
                $content .= "{$playUrl}\n";
            }
        }

        self::writeAtomically(self::getDataDir() . '/interface.txt', $content);
    }

    public static function generateInterfaceTXTTxt(array $allChannels): void
    {
        $pass = AppConfig::get('pass', '');
        $host = AppConfig::get('host', '');
        $port = AppConfig::get('port', 1905);
        $baseUrl = $host ?: "http://localhost:{$port}";
        if ($pass) {
            $baseUrl .= "/{$pass}";
        }

        $content = '';
        foreach ($allChannels as $group) {
            $groupName = $group['name'] ?? '未分组';
            $content .= "{$groupName},#genre#\n";
            foreach ($group['dataList'] as $channel) {
                $name = $channel['name'] ?? '';
                $pID = $channel['pID'] ?? '';
                $url = $channel['url'] ?? '';
                $playURL = $channel['playURL'] ?? '';

                if (!empty($playURL)) {
                    $playUrl = str_replace('${replace}', $baseUrl, $playURL);
                } elseif (!empty($url)) {
                    $playUrl = str_replace('${replace}', $baseUrl, $url);
                } elseif ($pID) {
                    $playUrl = "{$baseUrl}/{$pID}";
                } else {
                    continue;
                }

                $content .= "{$name},{$playUrl}\n";
            }
        }

        self::writeAtomically(self::getDataDir() . '/interfaceTXT.txt', $content);
    }

    public static function generateChannelSources(array $allChannels): void
    {
        $pass = AppConfig::get('pass', '');
        $host = AppConfig::get('host', '');
        $port = AppConfig::get('port', 1905);
        $baseUrl = $host ?: "http://localhost:{$port}";
        if ($pass) {
            $baseUrl .= "/{$pass}";
        }

        $sourceMap = [];

        foreach ($allChannels as $group) {
            $groupName = $group['name'] ?? '未分组';
            foreach ($group['dataList'] as $channel) {
                $name = $channel['name'] ?? '';
                if ($name === '') continue;

                $logo = $channel['pics']['highResolutionH'] ?? $channel['logo'] ?? '';
                $pID = $channel['pID'] ?? '';
                $url = $channel['url'] ?? '';
                $playURL = $channel['playURL'] ?? '';
                $source = $channel['source'] ?? '未知';

                if (!empty($playURL)) {
                    $playUrl = str_replace('${replace}', $baseUrl, $playURL);
                } elseif (!empty($url)) {
                    $playUrl = str_replace('${replace}', $baseUrl, $url);
                } elseif ($pID) {
                    $playUrl = "{$baseUrl}/{$pID}";
                } else {
                    continue;
                }

                $key = "{$groupName}::{$name}";
                if (!isset($sourceMap[$key])) {
                    $sourceMap[$key] = [
                        'groupName' => $groupName,
                        'channelName' => $name,
                        'logo' => $logo,
                        'sources' => [],
                    ];
                }

                $exists = false;
                foreach ($sourceMap[$key]['sources'] as $s) {
                    if ($s['url'] === $playUrl) { $exists = true; break; }
                }
                if (!$exists) {
                    $sourceMap[$key]['sources'][] = [
                        'url' => $playUrl,
                        'source' => $source,
                    ];
                }
            }
        }

        self::writeAtomically(self::getDataDir() . '/channel-sources.json', json_encode($sourceMap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public static function generatePlaybackXml(array $allChannels): void
    {
        $enableMigu = AppConfig::get('enableMigu', true);
        if (!$enableMigu) {
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<tv generator-info-name=\"CloudIPTV\"></tv>\n";
            self::writeAtomically(self::getDataDir() . '/playback.xml', $xml);
            return;
        }

        $xml = PlaybackService::generatePlaybackXml($allChannels);

        // Merge external EPG sources data
        try {
            $xml = \App\Services\EpgService::mergeToPlaybackXml($xml);
        } catch (\Exception $e) {}

        self::writeAtomically(self::getDataDir() . '/playback.xml', $xml);
    }

    private static function appendSportsToPlaylists(array $sportsGroups): void
    {
        $pass = AppConfig::get('pass', '');
        $host = AppConfig::get('host', '');
        $port = AppConfig::get('port', 1905);
        $baseUrl = $host ?: "http://localhost:{$port}";
        if ($pass) {
            $baseUrl .= "/{$pass}";
        }

        $m3uContent = '';
        $txtContent = '';

        foreach ($sportsGroups as $group) {
            $groupName = $group['name'] ?? '体育';
            $channels = $group['dataList'] ?? [];

            $txtContent .= "{$groupName},#genre#\n";

            foreach ($channels as $ch) {
                $name = $ch['name'] ?? '';
                $logo = $ch['logo'] ?? '';
                $pID = $ch['pID'] ?? '';
                $url = $ch['url'] ?? '';

                if ($pID === '' && $url === '') {
                    continue;
                }

                $playUrl = str_replace('${replace}', $baseUrl, $url ?: "{$baseUrl}/{$pID}");

                $m3uContent .= "#EXTINF:-1 tvg-id=\"{$name}\" tvg-name=\"{$name}\" tvg-logo=\"{$logo}\" group-title=\"{$groupName}\",{$name}\n";
                $m3uContent .= "{$playUrl}\n";
                $txtContent .= "{$name},{$playUrl}\n";
            }
        }

        $dataDir = self::getDataDir();
        file_put_contents($dataDir . '/interface.txt', $m3uContent, FILE_APPEND | LOCK_EX);
        file_put_contents($dataDir . '/interfaceTXT.txt', $txtContent, FILE_APPEND | LOCK_EX);
    }
}
