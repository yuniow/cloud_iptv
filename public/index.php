<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\AppConfig;
use App\Config\Routes;
use App\Services\Database;
use App\Services\ExternalSourceService;

Database::init();
Database::seedDefaults();
AppConfig::load();

$pdo = Database::getInstance();
$count = (int)$pdo->query("SELECT COUNT(*) FROM external_sources")->fetchColumn();
if ($count === 0) {
    $builtinM3u = dirname(__DIR__, 2) . '/config/IPTV.m3u';
    if (file_exists($builtinM3u)) {
        $channels = ExternalSourceService::parsePlaylistContent(file_get_contents($builtinM3u));
        $id = md5('精选频道');
        $pdo->prepare("INSERT INTO external_sources (id, name, group_name, mode, subscription_url, logo, enabled, auto_refresh, refresh_interval, update_on_startup, parsed_channels, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))")
            ->execute([$id, '精选频道', '未分组', 'subscription', 'https://raw.githubusercontent.com/akiralereal/iptv/refs/heads/main/IPTV.m3u', '', 1, 1, 360, 1, json_encode($channels)]);
    }
}

$routes = new Routes();
$routes->dispatch();
