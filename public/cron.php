<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$endpoint = $argv[1] ?? '';

if (!in_array($endpoint, ['probe', 'epg', 'refresh', 'delay'], true)) {
    echo "Usage: php cron.php <endpoint>\n";
    echo "Endpoints: probe, epg, refresh, delay\n";
    exit(1);
}

$dir = dirname(__DIR__);
$scriptMap = [
    'probe'   => $dir . '/cron_probe.php',
    'epg'     => $dir . '/cron_epg.php',
    'refresh' => $dir . '/cron_refresh.php',
];

if ($endpoint === 'delay') {
    $script = $dir . '/cron_probe.php';
} else {
    $script = $scriptMap[$endpoint];
}

$php = PHP_BINARY;
$cmd = "$php -f " . escapeshellarg($script) . " 2>&1";
echo "[cron] Running: $endpoint\n";
$output = shell_exec($cmd);
echo $output;
echo "[cron] Done: $endpoint at " . date('Y-m-d H:i:s') . "\n";
