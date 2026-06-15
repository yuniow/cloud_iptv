<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Services\Database;
use App\Services\ProbeService;

Database::init();
Database::seedDefaults();
AppConfig::load();

@ignore_user_abort(true);
@set_time_limit(60);

ProbeService::probeBatch();

$pdo = Database::getInstance();
$pdo->prepare("INSERT OR REPLACE INTO system_config (key, value, updated_at) VALUES ('last_probe', ?, datetime('now'))")->execute([time()]);
