<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use PDO;

class PlaylistConfigService
{
    public static function readConfig(): array
    {
        $pdo = Database::getInstance();
        $rows = $pdo->query("SELECT key, value FROM playlist_config")->fetchAll();
        $config = [];
        foreach ($rows as $row) {
            $config[$row['key']] = json_decode($row['value'], true) ?? $row['value'];
        }
        return array_merge(self::getDefaultConfig(), $config);
    }

    public static function saveConfig(array $config): array
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO playlist_config (key, value, updated_at) VALUES (?, ?, datetime('now'))");
            foreach ($config as $k => $v) {
                $stmt->execute([$k, is_string($v) ? $v : json_encode($v)]);
            }
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function parseInterfaceTxt(): array
    {
        $dataDir = dirname(__DIR__, 2) . '/data';
        $interfacePath = $dataDir . '/interface.txt';
        if (!file_exists($interfacePath)) {
            return [];
        }
        $content = file_get_contents($interfacePath);
        $lines = explode("\n", $content);
        $groups = [];

        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '' || str_starts_with($line, '#EXTM3U')) {
                continue;
            }
            if (str_starts_with($line, '#EXTINF:')) {
                preg_match('/tvg-id="([^"]*)"/', $line, $tvgIdMatch);
                preg_match('/tvg-name="([^"]*)"/', $line, $tvgNameMatch);
                preg_match('/tvg-logo="([^"]*)"/', $line, $tvgLogoMatch);
                preg_match('/group-title="([^"]*)"/', $line, $groupMatch);
                preg_match('/,(.+)$/', $line, $nameMatch);

                if (!empty($groupMatch[1]) && !empty($nameMatch[1]) && isset($lines[$i + 1])) {
                    $groupName = $groupMatch[1];
                    $channelName = trim($nameMatch[1]);
                    $url = trim($lines[$i + 1]);
                    $tvgName = $tvgNameMatch[1] ?? $channelName;
                    $logo = $tvgLogoMatch[1] ?? '';

                    if (!isset($groups[$groupName])) {
                        $groups[$groupName] = [];
                    }

                    // 从 URL 判断源类型
                    $srcType = preg_match('#/\d{5,}/?$#', $url) ? '咪咕' : '外部';

                    // 先在当前分组精确匹配
                    $existingIdx = null;
                    foreach ($groups[$groupName] as $idx => $existing) {
                        if ($existing['name'] === $channelName) {
                            $existingIdx = $idx;
                            break;
                        }
                    }

                    // 如果当前分组没匹配，且是央视/卫视相关分组，尝试跨分组模糊匹配
                    if ($existingIdx === null) {
                        $normName = self::normalizeChannelName($channelName);
                        foreach ($groups as $gName => &$gChannels) {
                            if (!self::isCctvOrWeishiGroup($gName) || !self::isCctvOrWeishiGroup($groupName)) {
                                continue;
                            }
                            foreach ($gChannels as $idx => $existing) {
                                if (self::normalizeChannelName($existing['name']) === $normName && $normName !== '') {
                                    // 找到匹配：将频道合并到匹配的分组中
                                    $gChannels[$idx]['sources'][] = [
                                        'url' => $url,
                                        'source' => $srcType,
                                    ];
                                    $existingIdx = '__merged__';
                                    break 2;
                                }
                            }
                        }
                        unset($gChannels);
                    }

                    if ($existingIdx === '__merged__') {
                        // 已合并到其他分组，跳过
                    } elseif ($existingIdx !== null) {
                        // 同分组同名：追加线路
                        $groups[$groupName][$existingIdx]['sources'][] = [
                            'url' => $url,
                            'source' => $srcType,
                        ];
                    } else {
                        // 新频道
                        $channelId = self::buildChannelId($groupName, $channelName, $tvgName, $url);
                        $groups[$groupName][] = [
                            'id' => $channelId,
                            'name' => $channelName,
                            'tvgId' => $tvgIdMatch[1] ?? '',
                            'tvgName' => $tvgName,
                            'logo' => $logo,
                            'url' => $url,
                            'originalGroup' => $groupName,
                            'sources' => [
                                ['url' => $url, 'source' => $srcType],
                            ],
                        ];
                    }
                    $i++;
                }
            }
        }

        return array_map(fn($name, $channels) => ['name' => $name, 'channels' => $channels], array_keys($groups), $groups);
    }

    private static function isCctvOrWeishiGroup(string $groupName): bool
    {
        return in_array($groupName, ['央视', 'CCTV', '卫视', '地方卫视'], true)
            || str_contains($groupName, '央视') || str_contains($groupName, '卫视');
    }

    private static function normalizeChannelName(string $name): string
    {
        // 去除分辨率/画质后缀：4K, HD, (720p), (1080p) 等
        $name = preg_replace('/\s*(4K|HD|SD|FHD|UHD)\s*/i', '', $name);
        $name = preg_replace('/\s*\(\d+p\)\s*/i', '', $name);
        $name = preg_replace('/\s*\[\S+\]\s*/', '', $name);
        $name = trim($name);

        // 央视：提取 CCTV{N} 或 CCTV{N}+ 核心名
        if (preg_match('/^CCTV[\s-]*(\d+\+?)/i', $name, $m)) {
            return 'CCTV' . $m[1];
        }

        // 卫视：保留省名+卫视
        if (preg_match('/^(.+?卫视)/', $name, $m)) {
            return $m[1];
        }

        return $name;
    }

    public static function applyConfig(array $groups, array $config): array
    {
        $channelMap = [];
        foreach ($groups as $group) {
            foreach ($group['channels'] as $channel) {
                $key = $group['name'] . '::' . $channel['id'];
                $channelMap[$key] = array_merge($channel, ['originalGroup' => $group['name']]);
            }
        }

        $resultGroups = [];
        $channelGroupMap = $config['channelGroupMap'] ?? [];

        foreach ($channelMap as $key => $channel) {
            $channelKey = $channel['originalGroup'] . '::' . $channel['id'];

            $renamedName = $config['channelRenameMap'][$channelKey] ?? null;
            if ($renamedName) {
                $channel['name'] = $renamedName;
            }

            if (in_array($channelKey, $config['hiddenChannels'] ?? [])) {
                continue;
            }

            $movedTo = $channelGroupMap[$channelKey] ?? null;
            $targetGroup = $movedTo ?? $channel['originalGroup'];

            if (!$movedTo && self::isGroupDeleted($channel['originalGroup'], $config['deletedGroups'] ?? [])) {
                continue;
            }

            if (!$movedTo && $targetGroup !== '未分组' && isset($config['groupRenameMap'][$targetGroup])) {
                $targetGroup = $config['groupRenameMap'][$targetGroup];
            }

            if (!isset($resultGroups[$targetGroup])) {
                $resultGroups[$targetGroup] = [];
            }
            $resultGroups[$targetGroup][] = $channel;
        }

        foreach (self::getCustomGroupNames($config) as $groupName) {
            if (!isset($resultGroups[$groupName])) {
                $resultGroups[$groupName] = [];
            }
        }

        $result = array_map(fn($name, $channels) => ['name' => $name, 'channels' => $channels], array_keys($resultGroups), $resultGroups);

        if (!empty($config['groupOrder'])) {
            usort($result, function ($a, $b) use ($config) {
                $indexA = array_search($a['name'], $config['groupOrder']);
                $indexB = array_search($b['name'], $config['groupOrder']);
                $indexA = $indexA === false ? PHP_INT_MAX : $indexA;
                $indexB = $indexB === false ? PHP_INT_MAX : $indexB;
                return $indexA <=> $indexB;
            });
        }

        $channelOrder = $config['channelOrder'] ?? [];
        foreach ($result as &$group) {
            $order = $channelOrder[$group['name']] ?? null;
            if (is_array($order) && !empty($order)) {
                usort($group['channels'], function ($a, $b) use ($order) {
                    $ia = array_search($a['originalGroup'] . '::' . $a['id'], $order);
                    $ib = array_search($b['originalGroup'] . '::' . $b['id'], $order);
                    $ia = $ia === false ? PHP_INT_MAX : $ia;
                    $ib = $ib === false ? PHP_INT_MAX : $ib;
                    return $ia <=> $ib;
                });
            }
        }
        unset($group);

        return $result;
    }

    private static function getBaseUrl(): string
    {
        $host = AppConfig::get('host', '');
        $pass = AppConfig::get('pass', '');
        if (!$host) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        }
        $baseUrl = $host;
        if ($pass && $baseUrl) {
            $baseUrl .= "/{$pass}";
        }
        return $baseUrl;
    }

    public static function getBaseUrlForOutput(): string
    {
        return self::getBaseUrl();
    }

    private static function replaceBaseUrl(string $url, string $baseUrl): string
    {
        if (!$baseUrl) return $url;
        if (preg_match('/\/(\d{5,})$/', $url, $m)) {
            return $baseUrl . '/' . $m[1];
        }
        if (preg_match('/\/(ext-[a-f0-9]+)$/', $url, $m)) {
            return $baseUrl . '/' . $m[1];
        }
        return $url;
    }

    public static function generateM3u8(array $groups): string
    {
        $baseUrl = self::getBaseUrl();

        $tvgUrl = $baseUrl ? "{$baseUrl}/playback.xml" : '/playback.xml';
        $content = "#EXTM3U x-tvg-url=\"{$tvgUrl}\" catchup=\"append\" catchup-source=\"?playbackbegin=\${(b)yyyyMMddHHmmss}&playbackend=\${(e)yyyyMMddHHmmss}\"\n";
        foreach ($groups as $group) {
            foreach ($group['channels'] as $channel) {
                $url = self::replaceBaseUrl($channel['url'], $baseUrl);
                $tvgId = $channel['tvgId'] ?? '';
                $tvgName = $channel['tvgName'] ?? $channel['name'];
                $logo = $channel['logo'] ?? '';
                $groupName = $group['name'];
                $name = $channel['name'];
                $content .= "#EXTINF:-1 tvg-id=\"{$tvgId}\" tvg-name=\"{$tvgName}\" tvg-logo=\"{$logo}\" group-title=\"{$groupName}\",{$name}\n";
                $content .= "{$url}\n";
            }
        }
        return $content;
    }

    public static function generateTxt(array $groups): string
    {
        $baseUrl = self::getBaseUrl();

        $content = '';
        foreach ($groups as $group) {
            $content .= $group['name'] . ",#genre#\n";
            foreach ($group['channels'] as $channel) {
                $url = self::replaceBaseUrl($channel['url'], $baseUrl);
                $content .= $channel['name'] . "," . $url . "\n";
            }
        }
        return $content;
    }

    public static function validateGroupConfig(array $groups, array $config): array
    {
        $renameMap = $config['groupRenameMap'] ?? [];
        $occupiedNames = ['未分组' => '__reserved__'];

        if (isset($renameMap['未分组']) && $renameMap['未分组'] !== '未分组') {
            return ['valid' => false, 'message' => '未分组不支持重命名'];
        }

        foreach ($groups as $group) {
            $targetName = $renameMap[$group['name']] ?? $group['name'];
            if (isset($occupiedNames[$targetName]) && $occupiedNames[$targetName] !== $group['name']) {
                return ['valid' => false, 'message' => "分组 \"{$targetName}\" 已存在"];
            }
            $occupiedNames[$targetName] = $group['name'];
        }

        foreach (self::getCustomGroupNames($config) as $customName) {
            if (isset($occupiedNames[$customName])) {
                return ['valid' => false, 'message' => "分组 \"{$customName}\" 已存在"];
            }
            $occupiedNames[$customName] = "custom:{$customName}";
        }

        return ['valid' => true];
    }

    private static function buildChannelId(string $groupName, string $channelName, string $tvgName, string $url): string
    {
        if ($url === '') {
            return substr(sha1("{$groupName}\n{$channelName}\n{$tvgName}"), 0, 16);
        }
        if (preg_match('/\/(\d{5,})(?:\?|$)/', $url, $m)) {
            return $m[1];
        }
        if (preg_match('/^\\$\\{replace\\}\\/([^\\/?#]+)/', $url, $m)) {
            return $m[1];
        }
        return 'ext-' . substr(sha1("{$groupName}\n{$channelName}\n{$tvgName}\n{$url}"), 0, 16);
    }

    private static function isGroupDeleted(string $groupName, array $deletedGroups): bool
    {
        foreach ($deletedGroups as $pattern) {
            $pattern = (string)$pattern;
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($groupName, substr($pattern, 0, -1))) {
                    return true;
                }
            } elseif ($pattern === $groupName) {
                return true;
            }
        }
        return false;
    }

    private static function getCustomGroupNames(array $config): array
    {
        $customGroups = $config['customGroups'] ?? [];
        $names = [];
        foreach ($customGroups as $group) {
            $name = is_string($group) ? $group : ($group['name'] ?? '');
            $name = trim($name);
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    private static function getDefaultConfig(): array
    {
        return [
            'channelGroupMap' => [],
            'channelRenameMap' => [],
            'channelOrder' => [],
            'hiddenChannels' => [],
            'customGroups' => [],
            'groupOrder' => [],
            'deletedGroups' => [],
            'groupRenameMap' => [],
            'channelSourceMap' => [],
        ];
    }
}
