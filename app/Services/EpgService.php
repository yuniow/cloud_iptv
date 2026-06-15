<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\HttpHelper;

class EpgService
{
    private static string $programsPath = '';
    private static ?array $programsCache = null;

    private static function getProgramsPath(): string
    {
        if (self::$programsPath === '') {
            self::$programsPath = dirname(__DIR__, 2) . '/data/epg-programs.json';
        }
        return self::$programsPath;
    }

    public static function getSourceList(): array
    {
        $pdo = Database::getInstance();
        return $pdo->query("SELECT * FROM epg_sources ORDER BY id ASC")->fetchAll();
    }

    public static function addSource(string $name, string $url): array
    {
        try {
            $pdo = Database::getInstance();
            $pdo->prepare("INSERT INTO epg_sources (name, url) VALUES (?, ?)")->execute([$name, $url]);
            return ['success' => true, 'id' => $pdo->lastInsertId()];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function removeSource(int $id): array
    {
        try {
            $pdo = Database::getInstance();
            $pdo->prepare("DELETE FROM epg_sources WHERE id = ?")->execute([$id]);
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function toggleSource(int $id, bool $enabled): array
    {
        try {
            $pdo = Database::getInstance();
            $pdo->prepare("UPDATE epg_sources SET enabled = ? WHERE id = ?")->execute([$enabled ? 1 : 0, $id]);
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function fetchAllSources(): array
    {
        $pdo = Database::getInstance();
        $sources = $pdo->query("SELECT * FROM epg_sources WHERE enabled = 1")->fetchAll();
        $totalPrograms = 0;
        $results = [];

        foreach ($sources as $source) {
            $result = self::fetchSource($source);
            $totalPrograms += $result['programCount'];
            $results[] = $result;
        }

        return $results;
    }

    public static function fetchSource(array $source): array
    {
        $url = $source['url'] ?? '';
        $name = $source['name'] ?? '';
        $id = $source['id'] ?? 0;

        $response = HttpHelper::get($url, [], 30);
        if (!$response || strlen($response) < 100) {
            return ['id' => $id, 'name' => $name, 'success' => false, 'message' => '下载失败或内容为空', 'programCount' => 0];
        }

        // Handle gzip compressed XML
        if (str_ends_with(strtolower($url), '.gz') || str_starts_with(substr($response, 0, 2), "\x1f\x8b")) {
            @ini_set('memory_limit', '512M');
            $decompressed = @gzdecode($response);
            if ($decompressed !== false) {
                $response = $decompressed;
            }
        }

        $programs = self::parseXmltv($response);
        $programCount = count($programs);

        if ($programCount > 0) {
            self::saveProgramsToFile($programs, $id);
        }

        $pdo = Database::getInstance();
        $pdo->prepare("UPDATE epg_sources SET last_updated = datetime('now') WHERE id = ?")->execute([$id]);

        return ['id' => $id, 'name' => $name, 'success' => true, 'programCount' => $programCount];
    }

    public static function parseXmltv(string $xml): array
    {
        $programs = [];
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if (!$doc) {
            return [];
        }

        $channelMap = [];
        foreach ($doc->channel as $channel) {
            $id = (string)($channel['id'] ?? '');
            $icon = '';
            if (isset($channel->icon)) {
                $icon = (string)($channel->icon['src'] ?? '');
            }
            $displayName = (string)($channel->{'display-name'} ?? '');
            if ($id !== '') {
                $channelMap[$id] = ['name' => $displayName ?: $id, 'icon' => $icon];
            }
        }

        foreach ($doc->programme as $prog) {
            $channelId = (string)($prog['channel'] ?? '');
            $start = (string)($prog['start'] ?? '');
            $stop = (string)($prog['stop'] ?? '');
            $title = (string)($prog->title ?? '');

            if ($channelId === '' || $title === '') {
                continue;
            }

            $channelInfo = $channelMap[$channelId] ?? ['name' => $channelId, 'icon' => ''];

            $programs[] = [
                'channel_id' => $channelId,
                'channel_name' => $channelInfo['name'],
                'epg_name' => $channelId,
                'start_time' => self::parseXmltvTime($start),
                'end_time' => self::parseXmltvTime($stop),
                'title' => $title,
                'icon' => $channelInfo['icon'],
            ];
        }

        return $programs;
    }

    public static function saveProgramsToFile(array $programs, int $sourceId): void
    {
        $path = self::getProgramsPath();
        $existing = [];
        if (file_exists($path)) {
            $existing = json_decode(file_get_contents($path), true) ?? [];
        }

        // Remove old programs from this source
        $existing = array_filter($existing, fn($p) => ($p['source_id'] ?? 0) !== $sourceId);

        // Only keep today and tomorrow programs
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        foreach ($programs as $p) {
            $start = $p['start_time'] ?? '';
            if ($start >= $today && $start <= $tomorrow . ' 23:59:59') {
                $p['source_id'] = $sourceId;
                $existing[] = $p;
            }
        }

        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($existing, JSON_UNESCAPED_UNICODE));
        rename($tmp, $path);
    }

    public static function getProgramsForChannel(string $channelName): array
    {
        $path = self::getProgramsPath();
        if (!file_exists($path)) {
            return [];
        }

        $all = json_decode(file_get_contents($path), true) ?? [];
        $today = date('Y-m-d');

        return array_values(array_filter($all, function ($p) use ($channelName, $today) {
            return ($p['channel_name'] ?? '') === $channelName
                && ($p['start_time'] ?? '') >= $today;
        }));
    }

    public static function getEpgStats(): array
    {
        $path = self::getProgramsPath();
        $all = file_exists($path) ? (json_decode(file_get_contents($path), true) ?? []) : [];

        $channelNames = [];
        $todayPrograms = 0;
        $today = date('Y-m-d');
        foreach ($all as $p) {
            $channelNames[$p['channel_id'] ?? ''] = true;
            if (($p['start_time'] ?? '') >= $today) {
                $todayPrograms++;
            }
        }

        $pdo = Database::getInstance();
        $sourceCount = (int)$pdo->query("SELECT COUNT(*) FROM epg_sources")->fetchColumn() + 1;

        return [
            'totalChannels' => count($channelNames),
            'totalPrograms' => count($all),
            'todayPrograms' => $todayPrograms,
            'sourceCount' => $sourceCount,
        ];
    }

    public static function searchChannels(string $keyword): array
    {
        $path = self::getProgramsPath();
        if (!file_exists($path)) {
            return [];
        }

        $all = json_decode(file_get_contents($path), true) ?? [];
        $found = [];
        $seen = [];

        foreach ($all as $p) {
            $name = $p['channel_name'] ?? '';
            $id = $p['channel_id'] ?? '';
            if (stripos($name, $keyword) !== false || stripos($id, $keyword) !== false) {
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $found[] = [
                        'channel_id' => $id,
                        'channel_name' => $name,
                        'icon' => $p['icon'] ?? '',
                    ];
                }
            }
            if (count($found) >= 50) break;
        }

        return $found;
    }

    public static function fetchBuiltinEpg(): array
    {
        $totalPrograms = 0;
        $today = date('Ymd');
        $channels = MiguService::refreshChannels();
        $allPrograms = [];

        // CNTV EPG for CCTV channels
        $cntvMap = [
            'CCTV1综合' => 'cctv1', 'CCTV2财经' => 'cctv2', 'CCTV3综艺' => 'cctv3',
            'CCTV4中文国际' => 'cctv4', 'CCTV5体育' => 'cctv5', 'CCTV5+体育赛事' => 'cctv5plus',
            'CCTV6电影' => 'cctv6', 'CCTV7国防军事' => 'cctv7', 'CCTV8电视剧' => 'cctv8',
            'CCTV9纪录' => 'cctvjilu', 'CCTV10科教' => 'cctv10', 'CCTV11戏曲' => 'cctv11',
            'CCTV12社会与法' => 'cctv12', 'CCTV13新闻' => 'cctv13', 'CCTV14少儿' => 'cctvchild',
            'CCTV15音乐' => 'cctv15', 'CCTV17农业农村' => 'cctv17',
        ];

        foreach ($channels as $group) {
            foreach ($group['dataList'] ?? [] as $channel) {
                $name = $channel['name'] ?? '';
                $pID = $channel['pID'] ?? '';
                if ($name === '' || $pID === '') continue;

                $programs = null;
                if (isset($cntvMap[$name])) {
                    $programs = self::fetchCntvEpg($cntvMap[$name], $today);
                }
                if (!$programs) {
                    $programs = self::fetchMiguEpg($pID, $today);
                }

                if ($programs) {
                    foreach ($programs as $p) {
                        $p['source_id'] = 0;
                        $p['channel_name'] = $name;
                        $allPrograms[] = $p;
                    }
                    $totalPrograms += count($programs);
                }
            }
        }

        if (!empty($allPrograms)) {
            self::saveProgramsToFile($allPrograms, 0);
        }

        return ['id' => 0, 'name' => '咪咕/CNTV', 'success' => true, 'programCount' => $totalPrograms];
    }

    private static function fetchCntvEpg(string $cntvName, string $today): ?array
    {
        $url = "https://api.cntv.cn/epg/epginfo3?serviceId=shiyi&d={$today}&c={$cntvName}";
        $response = HttpHelper::get($url, [], 10);
        if (!$response) return null;

        $data = json_decode($response, true);
        $raw = $data[$cntvName]['program'] ?? [];
        $programs = [];
        foreach ($raw as $p) {
            $title = $p['t'] ?? '';
            if ($title === '') continue;
            $programs[] = [
                'channel_id' => $cntvName,
                'channel_name' => '',
                'epg_name' => $cntvName,
                'start_time' => self::formatTimestamp($p['st'] ?? 0),
                'end_time' => self::formatTimestamp($p['et'] ?? 0),
                'title' => $title,
                'icon' => '',
            ];
        }
        return $programs;
    }

    private static function fetchMiguEpg(string $pid, string $today): ?array
    {
        $url = "https://program-sc.miguvideo.com/live/v2/tv-programs-data/{$pid}/{$today}";
        $response = HttpHelper::get($url, [], 10);
        if (!$response) return null;

        $data = json_decode($response, true);
        $raw = $data['body']['program'][0]['content'] ?? [];
        $programs = [];
        foreach ($raw as $p) {
            $title = $p['contName'] ?? '';
            if ($title === '') continue;
            if (isset($p['startTime'])) {
                $start = self::formatTimestamp((int)($p['startTime'] / 1000));
                $stop = self::formatTimestamp((int)($p['endTime'] / 1000));
            } elseif (isset($p['st'])) {
                $start = self::formatTimestamp((int)$p['st']);
                $stop = self::formatTimestamp((int)$p['et']);
            } else { continue; }
            $programs[] = [
                'channel_id' => $pid,
                'channel_name' => '',
                'epg_name' => $pid,
                'start_time' => $start,
                'end_time' => $stop,
                'title' => $title,
                'icon' => '',
            ];
        }
        return $programs;
    }

    private static function formatTimestamp(int $ts): string
    {
        if ($ts <= 0) return '';
        return gmdate('Y-m-d H:i:s', $ts + 8 * 3600) . ' +0800';
    }

    private static function parseXmltvTime(string $time): string
    {
        $time = trim($time);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})\s+([+-]\d{4})$/', $time, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]} {$m[7]}";
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $time, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]} +0800";
        }
        return $time;
    }

    public static function mergeToPlaybackXml(string $existingXml): string
    {
        $path = self::getProgramsPath();
        if (!file_exists($path)) {
            return $existingXml;
        }

        $all = json_decode(file_get_contents($path), true) ?? [];
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $doc = @simplexml_load_string($existingXml);
        if (!$doc) {
            return $existingXml;
        }

        $existingIds = [];
        foreach ($doc->channel as $ch) {
            $existingIds[] = (string)($ch['id'] ?? '');
        }

        $newChannels = '';
        $channelIcons = [];
        foreach ($all as $p) {
            $name = $p['channel_name'] ?? '';
            $icon = $p['icon'] ?? '';
            if ($name !== '' && !in_array($name, $existingIds) && !isset($channelIcons[$name])) {
                $channelIcons[$name] = $icon;
            }
        }
        foreach ($channelIcons as $name => $icon) {
            $safeName = htmlspecialchars($name, ENT_XML1, 'UTF-8');
            $safeIcon = htmlspecialchars($icon, ENT_XML1, 'UTF-8');
            $newChannels .= "    <channel id=\"{$safeName}\">\n";
            $newChannels .= "        <display-name lang=\"zh\">{$safeName}</display-name>\n";
            if ($icon !== '') {
                $newChannels .= "        <icon src=\"{$safeIcon}\"/>\n";
            }
            $newChannels .= "    </channel>\n";
        }

        if ($newChannels !== '') {
            $existingXml = preg_replace('#</tv>#', $newChannels . "</tv>", $existingXml, 1);
        }

        $newPrograms = '';
        foreach ($all as $p) {
            $start = $p['start_time'] ?? '';
            if ($start < $today || $start > $tomorrow) {
                continue;
            }
            $safeChannel = htmlspecialchars($p['channel_name'] ?? '', ENT_XML1, 'UTF-8');
            $safeTitle = htmlspecialchars($p['title'] ?? '', ENT_XML1, 'UTF-8');
            $startFmt = self::formatXmltvTime($start);
            $stopFmt = self::formatXmltvTime($p['end_time'] ?? '');
            $newPrograms .= "    <programme channel=\"{$safeChannel}\" start=\"{$startFmt}\" stop=\"{$stopFmt}\">\n";
            $newPrograms .= "        <title lang=\"zh\">{$safeTitle}</title>\n";
            $newPrograms .= "    </programme>\n";
        }

        if ($newPrograms !== '') {
            $existingXml = preg_replace('#</tv>#', $newPrograms . "</tv>", $existingXml, 1);
        }

        return $existingXml;
    }

    private static function formatXmltvTime(string $datetime): string
    {
        $datetime = trim($datetime);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2}):(\d{2})/', $datetime, $m)) {
            return "{$m[1]}{$m[2]}{$m[3]}{$m[4]}{$m[5]}{$m[6]} +0800";
        }
        return $datetime;
    }
}
