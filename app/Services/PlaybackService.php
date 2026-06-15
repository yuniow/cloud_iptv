<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\HttpHelper;

class PlaybackService
{
    private const CNTV_NAMES = [
        'CCTV1综合' => 'cctv1',
        'CCTV2财经' => 'cctv2',
        'CCTV3综艺' => 'cctv3',
        'CCTV4中文国际' => 'cctv4',
        'CCTV5体育' => 'cctv5',
        'CCTV5+体育赛事' => 'cctv5plus',
        'CCTV6电影' => 'cctv6',
        'CCTV7国防军事' => 'cctv7',
        'CCTV8电视剧' => 'cctv8',
        'CCTV9纪录' => 'cctvjilu',
        'CCTV10科教' => 'cctv10',
        'CCTV11戏曲' => 'cctv11',
        'CCTV12社会与法' => 'cctv12',
        'CCTV13新闻' => 'cctv13',
        'CCTV14少儿' => 'cctvchild',
        'CCTV15音乐' => 'cctv15',
        'CCTV17农业农村' => 'cctv17',
        'CCTV4欧洲' => 'cctveurope',
        'CCTV4美洲' => 'cctvamerica',
    ];

    public static function getPlaybackData(string $programId, int $timeout = 6000): ?array
    {
        $today = date('Ymd');
        $url = "https://program-sc.miguvideo.com/live/v2/tv-programs-data/{$programId}/{$today}";
        $response = HttpHelper::get($url, [], $timeout / 1000);
        if (!$response) {
            return null;
        }
        $data = json_decode($response, true);
        return $data['body']['program'][0]['content'] ?? null;
    }

    public static function generatePlaybackXml(array $channels): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<tv generator-info-name=\"CloudIPTV\">\n";

        foreach ($channels as $group) {
            foreach ($group['dataList'] as $channel) {
                $name = $channel['name'] ?? '';
                if ($name === '') {
                    continue;
                }

                $playbackData = null;
                if (isset(self::CNTV_NAMES[$name])) {
                    $playbackData = self::getPlaybackDataByCntv($name);
                } else {
                    $pID = $channel['pID'] ?? '';
                    if ($pID !== '') {
                        $playbackData = self::getPlaybackData($pID);
                    }
                }

                if (!$playbackData) {
                    continue;
                }

                $xml .= "    <channel id=\"" . self::escapeXml($name) . "\">\n";
                $xml .= "        <display-name lang=\"zh\">" . self::escapeXml($name) . "</display-name>\n";
                $xml .= "    </channel>\n";

                foreach ($playbackData as $prog) {
                    $progName = $prog['contName'] ?? $prog['t'] ?? '';
                    if ($progName === '') {
                        continue;
                    }
                    $progName = self::escapeXml($progName);

                    if (isset($prog['startTime'])) {
                        $start = self::formatDateTime((int)($prog['startTime'] / 1000));
                        $stop = self::formatDateTime((int)($prog['endTime'] / 1000));
                    } elseif (isset($prog['st'])) {
                        $start = self::formatDateTime((int)$prog['st']);
                        $stop = self::formatDateTime((int)$prog['et']);
                    } else {
                        continue;
                    }

                    $xml .= "    <programme channel=\"" . self::escapeXml($name) . "\" start=\"{$start}\" stop=\"{$stop}\">\n";
                    $xml .= "        <title lang=\"zh\">{$progName}</title>\n";
                    $xml .= "    </programme>\n";
                }
            }
        }

        $xml .= "</tv>\n";
        return $xml;
    }

    private static function getPlaybackDataByCntv(string $name): ?array
    {
        $cntvName = self::CNTV_NAMES[$name] ?? null;
        if (!$cntvName) {
            return null;
        }

        $today = date('Ymd');
        $url = "https://api.cntv.cn/epg/epginfo3?serviceId=shiyi&d={$today}&c={$cntvName}";
        $response = HttpHelper::get($url);
        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        return $data[$cntvName]['program'] ?? null;
    }

    private static function escapeXml(string $str): string
    {
        return htmlspecialchars($str, ENT_XML1, 'UTF-8');
    }

    private static function formatDateTime(int $timestamp): string
    {
        return gmdate('YmdHis', $timestamp) . ' +0800';
    }
}
