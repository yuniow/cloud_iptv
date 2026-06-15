<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\CryptoHelper;
use App\Helpers\HttpHelper;
use App\Config\AppConfig;

class MiguService
{
    private static array $cachedChannels = [];

    public static function refreshChannels(): array
    {
        $userId = AppConfig::get('userId', '');
        $token = AppConfig::get('token', '');
        $rateType = (int)AppConfig::get('rateType', 3);
        $enableHDR = AppConfig::get('enableHDR', true);
        $enableH265 = AppConfig::get('enableH265', true);

        if ($userId === '' && $token === '') {
            return self::getGuestChannels($rateType, $enableHDR, $enableH265);
        }
        return self::getAuthChannels($userId, $token, $rateType, $enableHDR, $enableH265);
    }

    public static function getCachedChannels(): array
    {
        return self::$cachedChannels;
    }

    public static function setCachedChannels(array $channels): void
    {
        self::$cachedChannels = $channels;
    }

    private static function getGuestChannels(int $rateType, bool $enableHDR, bool $enableH265): array
    {
        $categories = self::fetchCategoryList();
        $channels = [];
        foreach ($categories as $cat) {
            $catChannels = self::fetchChannelList($cat['vomsID']);
            $group = [
                'name' => $cat['name'],
                'dataList' => $catChannels,
            ];
            $channels[] = $group;
        }
        return $channels;
    }

    private static function getAuthChannels(string $userId, string $token, int $rateType, bool $enableHDR, bool $enableH265): array
    {
        return self::getGuestChannels($rateType, $enableHDR, $enableH265);
    }

    public static function fetchCategoryList(): array
    {
        $url = 'https://program-sc.miguvideo.com/live/v2/tv-data/1ff892f2b5ab4a79be6e25b69d2f5d05';
        $response = HttpHelper::get($url);
        if (!$response) {
            return [];
        }
        $data = json_decode($response, true);
        $liveList = $data['body']['liveList'] ?? [];

        $liveList = array_filter($liveList, fn($item) => ($item['name'] ?? '') !== '热门');

        usort($liveList, function ($a, $b) {
            if (($a['name'] ?? '') === '央视') return -1;
            if (($b['name'] ?? '') === '央视') return 1;
            return 0;
        });

        return array_values($liveList);
    }

    public static function fetchChannelList(string $vomsId): array
    {
        $url = "https://program-sc.miguvideo.com/live/v2/tv-data/{$vomsId}";
        $response = HttpHelper::get($url);
        if (!$response) {
            return [];
        }
        $data = json_decode($response, true);
        $dataList = $data['body']['dataList'] ?? [];

        $seen = [];
        $unique = [];
        foreach ($dataList as $program) {
            $name = $program['name'] ?? '';
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $unique[] = $program;
            }
        }
        return $unique;
    }

    public static function getPlayUrl(string $pid, string $userId = '', string $token = '', int $rateType = 3): string
    {
        $enableHDR = AppConfig::get('enableHDR', true);
        $enableH265 = AppConfig::get('enableH265', true);

        if ($rateType >= 3 && ($userId === '' || $token === '')) {
            $resObj = CryptoHelper::getAndroidUrl720p($pid);
        } else {
            $resObj = CryptoHelper::getAndroidUrl($userId, $token, $pid, $rateType, $enableHDR, $enableH265);
        }

        if (!empty($resObj['url'])) {
            $location = CryptoHelper::get302Url($resObj);
            if ($location !== '') {
                $resObj['url'] = $location;
            }
        }

        return $resObj['url'] ?? '';
    }
}
