<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Services\Database;
use App\Services\UpdateService;
use App\Services\RefreshTokenService;
use App\Services\ProbeService;

Database::init();
Database::seedDefaults();
AppConfig::load();

@ignore_user_abort(true);
@set_time_limit(600);
@ini_set('output_buffering', '0');

$userId = AppConfig::get('userId', '');
$token = AppConfig::get('token', '');
$enableMigu = AppConfig::get('enableMigu', true);

if ($enableMigu && $userId && $token) {
    try { RefreshTokenService::refreshToken($userId, $token); } catch (\Exception $e) {}
}

UpdateService::runUpdate(0);

$pdo = Database::getInstance();
$pdo->prepare("INSERT OR REPLACE INTO system_config (key, value, updated_at) VALUES ('last_refresh', ?, datetime('now'))")->execute([time()]);

ProbeService::probeBatch();

