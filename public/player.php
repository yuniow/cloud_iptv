<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\AppConfig;
use App\Services\Database;

Database::init();
Database::seedDefaults();
AppConfig::load();

$reactIndex = __DIR__ . '/react/index.html';
if (file_exists($reactIndex)) {
    readfile($reactIndex);
} else {
    echo 'React app not built. Run: cd frontend && npm run build';
}
