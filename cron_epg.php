<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Services\Database;
use App\Services\EpgService;

Database::init();
Database::seedDefaults();
AppConfig::load();

@ignore_user_abort(true);
@set_time_limit(300);

EpgService::fetchAllSources();
