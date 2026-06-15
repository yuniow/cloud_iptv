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
                $name = htmlspecialchars($ch['display_name'] ?: $ch['name']);
                $logo = htmlspecialchars($ch['logo'] ?? '');
                $grp = htmlspecialchars($group);
                $url = $this->getChannelUrl($ch['id']);
                $content .= "#EXTINF:-1 tvg-id=\"{$id}\" tvg-name=\"{$name}\" tvg-logo=\"{$logo}\" group-title=\"{$grp}\",{$name}\n";
                $content .= "{$url}\n";
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

    public function getChannelUrl(string $channelId): string
    {
        $channelPass = AppConfig::get('channelPass', '');
        $host = AppConfig::get('host', '');
        $port = AppConfig::get('port', 1905);

        $baseUrl = $host ?: "http://localhost:{$port}";
        if ($channelPass) {
            $baseUrl .= "/{$channelPass}";
        }
        return $baseUrl . '/' . $channelId;
    }
}
