<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Config\AppConfig;
use App\Config\Routes;
use App\Services\Database;

Database::init();
Database::seedDefaults();
AppConfig::load();

$routes = new Routes();
$routes->dispatch();
