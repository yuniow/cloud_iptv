<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;

class PlaylistGenerator
{
    public function generateM3u(array $channels): string
    {
        $content = "#EXTM3U\n";

        $grouped = [];
        foreach ($channels as $ch) {
            $group = $ch['group_name'] ?? '未分组';
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }
            $grouped[$group][] = $ch;
        }

        foreach ($grouped as $group => $groupChannels) {
            foreach ($groupChannels as $ch) {
                $id = htmlspecialchars($ch['tvg_id'] ?: $ch['id']);
                $name = htmlspecialchars($ch['name'] ?? '');
                $logo = htmlspecialchars($ch['logo'] ?? '');
                $grp = htmlspecialchars($group);
                $sources = $ch['sources'] ?? [['url' => $ch['url'] ?? '', 'source' => '']];

                if (count($sources) <= 1) {
                    // 单线路：正常输出
                    $url = $this->getChannelUrl($ch['id']);
                    $content .= "#EXTINF:-1 tvg-id=\"{$id}\" tvg-name=\"{$name}\" tvg-logo=\"{$logo}\" group-title=\"{$grp}\",{$name}\n";
                    $content .= "{$url}\n";
                } else {
                    // 多线路：每条线路输出一条记录，tvg-id 相同
                    foreach ($sources as $idx => $src) {
                        $srcUrl = $src['url'] ?? '';
                        $srcName = $src['source'] ?? '线路' . ($idx + 1);
                        $lineName = count($sources) > 1 ? "{$name} ({$srcName})" : $name;
                        $url = $this->getChannelUrl($ch['id'], $idx);
                        $content .= "#EXTINF:-1 tvg-id=\"{$id}\" tvg-name=\"{$name}\" tvg-logo=\"{$logo}\" group-title=\"{$grp}\",{$lineName}\n";
                        $content .= "{$url}\n";
                    }
                }
            }
        }

        return $content;
    }

    public function generateTxt(array $channels): string
    {
        $content = '';

        $grouped = [];
        foreach ($channels as $ch) {
            $group = $ch['group_name'] ?? '未分组';
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }
            $grouped[$group][] = $ch;
        }

        foreach ($grouped as $group => $groupChannels) {
            $content .= "{$group},#genre#\n";
            foreach ($groupChannels as $ch) {
                $name = $ch['display_name'] ?: $ch['name'];
                $url = $this->getChannelUrl($ch['id']);
                $content .= "{$name},{$url}\n";
            }
            $content .= "\n";
        }

        return $content;
    }

    public function getChannelUrl(string $channelId, int $sourceIndex = 0): string
    {
        $channelPass = AppConfig::get('channelPass', '');
        $host = AppConfig::get('host', '');
        $port = AppConfig::get('port', 1905);

        $baseUrl = rtrim($host ?: "http://localhost:{$port}", '/');
        if ($channelPass) {
            $baseUrl .= "/{$channelPass}";
        }
        // 多线路时，URL 包含 sourceIndex 参数
        $url = $baseUrl . '/' . $channelId;
        if ($sourceIndex > 0) {
            $url .= "?source={$sourceIndex}";
        }
        return $url;
    }
}
