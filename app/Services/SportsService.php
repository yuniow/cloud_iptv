<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\HttpHelper;

class SportsService
{
    private const MATCH_LIST_URL = 'http://v0-sc.miguvideo.com/vms-match/v6/staticcache/basic/match-list/normal-match-list/0/all/default/1/miguvideo';
    private const BASIC_DATA_URL = 'https://vms-sc.miguvideo.com/vms-match/v6/staticcache/basic/basic-data';
    private const REPLAY_URL = 'http://app-sc.miguvideo.com/vms-match/v5/staticcache/basic/all-view-list';

    private static string $cachePath = '';

    private static function getCachePath(): string
    {
        if (self::$cachePath === '') {
            self::$cachePath = dirname(__DIR__, 2) . '/data/pe-cache.json';
        }
        return self::$cachePath;
    }

    public static function fetchSportsChannels(): array
    {
        $response = HttpHelper::get(self::MATCH_LIST_URL, [], 15);
        if (!$response) {
            return [];
        }

        $data = json_decode($response, true);
        if (!$data || empty($data['body']['days'])) {
            return [];
        }

        $days = $data['body']['days'];
        $matchList = $data['body']['matchList'] ?? [];
        $today = date('Ymd');
        $groups = [];

        for ($i = 1; $i < 4 && $i < count($days); $i++) {
            $date = $days[$i];
            if (!isset($matchList[$date])) {
                continue;
            }

            if ($date === $today) {
                $relativeDate = '今天';
            } elseif ($date > $today) {
                $relativeDate = '明天';
            } else {
                $relativeDate = '昨天';
            }

            $groupName = "体育-{$relativeDate}";
            $channels = [];

            foreach ($matchList[$date] as $match) {
                $mgdbId = $match['mgdbId'] ?? '';
                if ($mgdbId === '') {
                    continue;
                }

                $pkInfoTitle = $match['pkInfoTitle'] ?? '';
                if (!empty($match['confrontTeams']) && count($match['confrontTeams']) >= 2) {
                    $pkInfoTitle = $match['confrontTeams'][0]['name'] . 'VS' . $match['confrontTeams'][1]['name'];
                }
                $competitionName = $match['competitionName'] ?? '';
                $competitionLogo = $match['competitionLogo'] ?? '';

                $basicData = self::fetchBasicData($mgdbId);
                if (!$basicData) {
                    continue;
                }

                $endTime = $basicData['body']['endTime'] ?? 0;
                $keyword = $basicData['body']['keyword'] ?? '';

                if ($endTime > 0 && $endTime < time() * 1000) {
                    $channels = array_merge($channels, self::fetchReplays($mgdbId, $basicData, $pkInfoTitle, $competitionName, $competitionLogo, $keyword, $relativeDate));
                } else {
                    $channels = array_merge($channels, self::fetchLiveChannels($basicData, $pkInfoTitle, $competitionName, $competitionLogo));
                }
            }

            if (!empty($channels)) {
                $groups[] = [
                    'name' => $groupName,
                    'dataList' => $channels,
                ];
            }
        }

        if (!empty($groups)) {
            self::saveCache($groups);
        }

        return $groups;
    }

    private static function cleanUtf8(string $str): string
    {
        $str = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
        return preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $str);
    }

    private static function fetchBasicData(string $mgdbId): ?array
    {
        $url = self::BASIC_DATA_URL . "/{$mgdbId}/miguvideo";
        $response = HttpHelper::get($url, [], 10);
        if (!$response) {
            return null;
        }
        return json_decode($response, true);
    }

    private static function fetchReplays(string $mgdbId, array $basicData, string $pkInfoTitle, string $competitionName, string $competitionLogo, string $keyword, string $relativeDate): array
    {
        $channels = [];

        $url = self::REPLAY_URL . "/{$mgdbId}/2/miguvideo";
        $response = HttpHelper::get($url, [], 10);
        $replayData = $response ? json_decode($response, true) : null;

        $replayList = $replayData['body']['replayList'] ?? $basicData['body']['multiPlayList']['replayList'] ?? [];

        if (empty($replayList)) {
            return [];
        }

        foreach ($replayList as $replay) {
            $name = $replay['name'] ?? '';
            if (preg_match('/.*集锦|训练.*/', $name)) {
                continue;
            }
            if (preg_match('/.*回放|赛.*/', $name)) {
                $timeStr = substr($keyword, 7);
                $preList = $basicData['body']['multiPlayList']['preList'] ?? [];
                if (!empty($preList)) {
                    $lastPre = end($preList);
                    if (isset($lastPre['startTimeStr'])) {
                        $timeStr = substr($lastPre['startTimeStr'], 11, 5);
                    }
                }
                $competitionDesc = self::cleanUtf8("{$competitionName} {$pkInfoTitle} {$name} {$timeStr}");
                $pID = $replay['pID'] ?? '';
                if ($pID !== '') {
                    $channels[] = [
                        'name' => $competitionDesc,
                        'pID' => $pID,
                        'url' => '${replace}/' . $pID,
                        'logo' => $competitionLogo,
                    ];
                }
            }
        }
        return $channels;
    }

    private static function fetchLiveChannels(array $basicData, string $pkInfoTitle, string $competitionName, string $competitionLogo): array
    {
        $channels = [];
        $liveList = $basicData['body']['multiPlayList']['liveList'] ?? [];

        foreach ($liveList as $live) {
            $name = $live['name'] ?? '';
            if (preg_match('/.*集锦.*/', $name) || !isset($live['startTimeStr'])) {
                continue;
            }
            $startTime = substr($live['startTimeStr'] ?? '', 11, 5);
            $competitionDesc = self::cleanUtf8("{$competitionName} {$pkInfoTitle} {$name} {$startTime}");
            $pID = $live['pID'] ?? '';
            if ($pID !== '') {
                $channels[] = [
                    'name' => $competitionDesc,
                    'pID' => $pID,
                    'url' => '${replace}/' . $pID,
                    'logo' => $competitionLogo,
                ];
            }
        }
        return $channels;
    }

    public static function getCachedChannels(): array
    {
        $path = self::getCachePath();
        if (!file_exists($path)) {
            return [];
        }
        $cache = json_decode(file_get_contents($path), true);
        return $cache['groups'] ?? [];
    }

    private static function saveCache(array $groups): void
    {
        $data = [
            'groups' => $groups,
            'updatedAt' => date('c'),
        ];
        $path = self::getCachePath();
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE));
        rename($tmp, $path);
    }
}
