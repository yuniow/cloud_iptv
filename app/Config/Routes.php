<?php
declare(strict_types=1);

namespace App\Config;

class Routes
{
    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        $adminPath = AppConfig::get('adminPath', 'admin');
        $pass = AppConfig::get('pass', '');
        $channelPass = AppConfig::get('channelPass', '');
        $passInUrl = false;
        $channelPassInUrl = false;

        if ($pass !== '' && (str_starts_with($path, "/{$pass}/") || $path === "/{$pass}")) {
            $passInUrl = true;
            $cleanPath = substr($path, strlen("/{$pass}")) ?: '/';
            if (str_starts_with($cleanPath, "/{$adminPath}") || str_starts_with($cleanPath, '/player') || $cleanPath === '/login') {
                header('Location: /');
                http_response_code(302);
                return;
            }
            $path = $cleanPath;
        }

        if ($channelPass !== '' && (str_starts_with($path, "/{$channelPass}/") || $path === "/{$channelPass}")) {
            $channelPassInUrl = true;
            $path = substr($path, strlen("/{$channelPass}")) ?: '/';
        }

        if ($path === '/favicon.ico') {
            http_response_code(204);
            return;
        }

        if ($method === 'HEAD' || $method === 'OPTIONS') {
            self::setCorsHeaders();
            http_response_code(200);
            return;
        }

        if ($path === "/{$adminPath}" || $path === "/{$adminPath}/" || str_starts_with($path, "/{$adminPath}/")) {
            $token = $_COOKIE['admin_token'] ?? '';
            if ($token && \App\Services\AdminAuthService::verify($token) !== null) {
                self::checkAndRefresh();
                self::checkAndProbe();
            }
            require dirname(__DIR__, 2) . '/public/admin.php';
            return;
        }

        if ($path === '/player' || str_starts_with($path, '/player/')) {
            require dirname(__DIR__, 2) . '/public/player.php';
            return;
        }

        if ($path === '/login') {
            require dirname(__DIR__, 2) . '/public/admin.php';
            return;
        }

        if (str_starts_with($path, '/api/')) {
            $apiController = new \App\Controllers\ApiController();
            $apiController->handleApi($path, $method);
            return;
        }

        if ($path === '/logo') {
            $url = $_GET['url'] ?? '';
            if ($url && preg_match('#^https?://#', $url) && self::isSafeExternalUrl($url)) {
                $ctx = stream_context_create(['http' => ['timeout' => 5, 'follow_location' => true, 'ignore_errors' => true]]);
                $data = @file_get_contents($url, false, $ctx);
                if ($data !== false) {
                    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                    $types = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','svg'=>'image/svg+xml'];
                    header('Content-Type: ' . ($types[$ext] ?? 'image/webp'));
                    header('Cache-Control: public, max-age=86400');
                    echo $data;
                    return;
                }
            }
            http_response_code(404);
            return;
        }

        if ($path === '/proxy') {
            $url = $_GET['url'] ?? '';
            if ($url && preg_match('#^https?://#', $url) && self::isSafeExternalUrl($url)) {
                $ctx = stream_context_create([
                    'http' => [
                        'timeout' => 15,
                        'follow_location' => true,
                        'ignore_errors' => true,
                        'header' => "Referer: " . preg_replace('#/[^/]*$#', '/', $url) . "\r\nUser-Agent: Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36\r\n",
                    ]
                ]);
                $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                if (in_array($ext, ['m3u8', 'm3u'])) {
                    $data = @file_get_contents($url, false, $ctx);
                    if ($data !== false) {
                        $proxyBase = preg_replace('#/[^/]+\.m3u8(\?.*)?$#', '/', $url);
                        $lines = explode("\n", $data);
                        foreach ($lines as &$line) {
                            $line = trim($line);
                            if ($line === '' || str_starts_with($line, '#')) continue;
                            if (!preg_match('#^https?://#', $line) && !str_starts_with($line, '/')) {
                                $line = '/proxy?url=' . urlencode($proxyBase . $line);
                            } elseif (preg_match('#^https?://#', $line)) {
                                $line = '/proxy?url=' . urlencode($line);
                            }
                        }
                        unset($line);
                        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
                        self::setCorsHeaders();
                        header('Cache-Control: no-cache');
                        echo implode("\n", $lines);
                        return;
                    }
                } else {
                    $resp = @file_get_contents($url, false, $ctx);
                    if ($resp !== false) {
                        if ($ext === 'ts') header('Content-Type: video/mp2t');
                        self::setCorsHeaders();
                        header('Cache-Control: public, max-age=3600');
                        echo $resp;
                        return;
                    }
                }
            }
            http_response_code(404);
            return;
        }

        $routeUrl = $path;
        $fullUrl = $_SERVER['REQUEST_URI'] ?? '/';
        $queryIndex = strpos($fullUrl, '?');
        if ($queryIndex !== false) {
            $routeUrl .= substr($fullUrl, $queryIndex);
        }

        $urlUserId = AppConfig::get('userId', '');
        $urlToken = AppConfig::get('token', '');
        if (preg_match('#/[^/\s]+/[^/\s]+#', $routeUrl)) {
            $urlSplit = explode('/', $routeUrl);
            if (count($urlSplit) >= 3) {
                $urlUserId = $urlSplit[1];
                $urlToken = $urlSplit[2];
                $routeUrl = count($urlSplit) === 3 ? '/' : '/' . end($urlSplit);
            }
        }

        if ($routeUrl === '/') {
            require dirname(__DIR__, 2) . '/public/admin.php';
            return;
        }

        $interfaceList = ['/interface.txt', '/m3u', '/txt', '/playback.xml'];
        if (in_array($routeUrl, $interfaceList) || str_starts_with($routeUrl, '/?')) {
            if ($pass !== '' && !$passInUrl) {
                self::showErrorPage('需要访问密码', '播放链接需要携带访问密码才能访问，请在系统配置中查看密码。', '/');
                return;
            }
            $host = AppConfig::get('host', '');
            if ($host) {
                $hostName = preg_replace('#^https?://#', '', $host);
                $hostName = rtrim($hostName, '/');
                $currentHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
                $isLocal = in_array($currentHost, ['127.0.0.1:10800', '127.0.0.1', 'localhost:10800', 'localhost', '0.0.0.0:10800']);
                if (!$isLocal && $currentHost !== $hostName) {
                    self::showErrorPage('域名不匹配', "请通过配置的公网地址访问：<br><strong>{$host}</strong>", $host, '前往访问');
                    return;
                }
            }
            $this->serveInterface($routeUrl);
            return;
        }

        if ($channelPass !== '' && !$channelPassInUrl) {
            $checkPid = trim($routeUrl, '/');
            if (str_contains($checkPid, '?')) $checkPid = explode('?', $checkPid)[0];
            if (is_numeric($checkPid)) {
                self::showErrorPage('需要频道密码', '频道播放需要携带频道密码才能访问，请在系统配置中查看频道密码。', '/');
                return;
            }
        }

        $this->serveChannel($routeUrl, $urlUserId, $urlToken);
    }

    private function serveInterface(string $routeUrl): void
    {
        $dataDir = dirname(__DIR__, 2) . '/data';
        $publicDir = dirname(__DIR__, 2) . '/public';

        if ($routeUrl === '/playback.xml') {
            $file = $dataDir . '/playback.xml';
            header('Content-Type: text/xml; charset=utf-8');
            if (file_exists($file)) {
                readfile($file);
            } else {
                echo '<?xml version="1.0" encoding="UTF-8"?><tv></tv>';
            }
            return;
        }

        if ($routeUrl === '/txt') {
            header('Content-Type: text/plain; charset=utf-8');
            $groups = \App\Services\PlaylistConfigService::parseInterfaceTxt();
            $config = \App\Services\PlaylistConfigService::readConfig();
            $result = \App\Services\PlaylistConfigService::applyConfig($groups, $config);
            echo \App\Services\PlaylistConfigService::generateTxt($result);
            return;
        }

        if ($routeUrl === '/m3u') {
            $groups = \App\Services\PlaylistConfigService::parseInterfaceTxt();
            $config = \App\Services\PlaylistConfigService::readConfig();
            $result = \App\Services\PlaylistConfigService::applyConfig($groups, $config);
            $content = \App\Services\PlaylistConfigService::generateM3u8($result);
            header('Content-Type: audio/x-mpegurl; charset=utf-8');
            header('Content-Disposition: inline; filename="interface.m3u"');
            echo $content;
            return;
        }

        if ($routeUrl === '/interface.txt') {
            header('Content-Type: text/plain; charset=utf-8');
            $groups = \App\Services\PlaylistConfigService::parseInterfaceTxt();
            $config = \App\Services\PlaylistConfigService::readConfig();
            $result = \App\Services\PlaylistConfigService::applyConfig($groups, $config);
            echo \App\Services\PlaylistConfigService::generateTxt($result);
            return;
        }

        $reactIndex = $publicDir . '/react/index.html';
        if (file_exists($reactIndex)) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($reactIndex);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<html><body><h1>CloudIPTV</h1><p>React app not built. Run: cd frontend && npm run build</p></body></html>';
        }
    }

    private function serveChannel(string $routeUrl, string $urlUserId, string $urlToken): void
    {
        $urlParts = explode('/', trim($routeUrl, '/'));
        $pid = $urlParts[0] ?? '';
        $params = '';

        if (str_contains($pid, '?')) {
            $pidParts = explode('?', $pid, 2);
            $pid = $pidParts[0];
            $params = $pidParts[1];
        }

        if (!is_numeric($pid)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => '地址格式错误']);
            return;
        }

        $rateType = (int)AppConfig::get('rateType', 3);
        $enableHDR = AppConfig::get('enableHDR', true);
        $enableH265 = AppConfig::get('enableH265', true);

        if ($rateType >= 3 && ($urlUserId === '' || $urlToken === '')) {
            $resObj = \App\Helpers\CryptoHelper::getAndroidUrl720p($pid);
        } else {
            $resObj = \App\Helpers\CryptoHelper::getAndroidUrl($urlUserId, $urlToken, $pid, $rateType, $enableHDR, $enableH265);
        }

        if (!empty($resObj['url'])) {
            $location = \App\Helpers\CryptoHelper::get302Url($resObj);
            if ($location !== '') {
                $resObj['url'] = $location;
            }
        }

        $playUrl = $resObj['url'] ?? '';

        if ($playUrl === '') {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => '节目调整，暂不提供服务']);
            return;
        }

        if ($params !== '') {
            $playUrl .= '&' . $params;
        }

        $proxyEnabled = AppConfig::get('proxyEnabled', false);
        $proxyUrl = AppConfig::get('proxyUrl', '');

        $sourceProxy = '';
        try {
            $srcFile = dirname(__DIR__, 2) . '/data/channel-sources.json';
            if (file_exists($srcFile)) {
                $srcData = json_decode(file_get_contents($srcFile), true) ?? [];
                foreach ($srcData as $ch) {
                    foreach ($ch['sources'] ?? [] as $src) {
                        $srcUrl = $src['url'] ?? '';
                        if (str_contains($srcUrl, "/{$pid}") || str_contains($srcUrl, "/{$pid}?")) {
                            $srcType = $src['source'] ?? '';
                            if ($srcType === '咪咕') $sourceProxy = AppConfig::get('miguProxy', '');
                            elseif ($srcType === '内置') $sourceProxy = AppConfig::get('builtInProxy', '');
                            elseif (isset($src['proxy']) && $src['proxy'] !== '') $sourceProxy = $src['proxy'];
                            break 2;
                        }
                    }
                }
            }
        } catch (\Exception $e) {}

        if ($sourceProxy !== '' && preg_match('#^https?://#', $sourceProxy)) {
            $playUrl = rtrim($sourceProxy, '/') . '/' . urlencode($playUrl);
        } elseif ($proxyEnabled && $proxyUrl !== '' && preg_match('#^https?://#', $proxyUrl)) {
            $playUrl = rtrim($proxyUrl, '/') . '/' . urlencode($playUrl);
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $deviceId = $_COOKIE['device_id'] ?? '';
        if (!$deviceId) {
            $deviceId = md5($clientIp . $userAgent . $acceptLang . time());
            setcookie('device_id', $deviceId, [
                'expires' => time() + 86400 * 30,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $fingerprint = md5($clientIp . '|' . $userAgent . '|' . $acceptLang);

        try {
            $pdo = \App\Services\Database::getInstance();
            $blocked = $pdo->prepare("SELECT id FROM blocked_devices WHERE client_ip = ? OR fingerprint = ? OR device_id = ?");
            $blocked->execute([$clientIp, $fingerprint, $deviceId]);
            if ($blocked->fetch()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => '您的设备已被屏蔽，请联系管理员']);
                return;
            }
        } catch (\Exception $e) {}

        try {
            $channelName = '';
            try {
                $groups = \App\Services\PlaylistConfigService::parseInterfaceTxt();
                foreach ($groups as $group) {
                    foreach ($group['channels'] as $ch) {
                        if (isset($ch['id']) && $ch['id'] === $pid) {
                            $channelName = $ch['name'] ?? '';
                            break 2;
                        }
                    }
                }
            } catch (\Exception $e) {}
            $pdo = \App\Services\Database::getInstance();
            $stmt = $pdo->prepare("INSERT INTO access_logs (channel_id, channel_name, client_ip, user_agent, access_type, created_at) VALUES (?, ?, ?, ?, 'play', ?)");
            $stmt->execute([$pid, $channelName, $clientIp, $userAgent, date('Y-m-d H:i:s')]);
            if (random_int(1, 100) === 1) {
                $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
                $pdo->prepare("DELETE FROM access_logs WHERE created_at < ?")->execute([$cutoff]);
            }
        } catch (\Exception $e) {}

        if (str_ends_with($playUrl, '.m3u8') || str_contains($playUrl, '.m3u8?')) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'follow_location' => true,
                    'ignore_errors' => true,
                    'header' => "Referer: " . preg_replace('#/[^/]+\.m3u8(\?.*)?$#', '/', $playUrl) . "\r\nUser-Agent: Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36\r\n",
                ]
            ]);
            $m3u8 = @file_get_contents($playUrl, false, $ctx);
            if ($m3u8 !== false && strlen($m3u8) > 50 && str_contains($m3u8, '#EXT')) {
                $baseUrl = preg_replace('#/[^/]+\.m3u8(\?.*)?$#', '/', $playUrl);
                $lines = explode("\n", $m3u8);
                foreach ($lines as &$line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) continue;
                    if (preg_match('#^https?://#', $line)) {
                        $line = preg_replace('#:8080/#', '/', $line);
                        $line = str_replace('http://', 'https://', $line);
                    } elseif (!str_starts_with($line, '/')) {
                        $fullUrl = preg_replace('#:8080/#', '/', str_replace('http://', 'https://', $baseUrl . $line));
                        $line = $fullUrl;
                    }
                    if ($proxyEnabled && $proxyUrl !== '' && preg_match('#^https?://#', $line) && preg_match('#^https?://#', $proxyUrl)) {
                        $line = rtrim($proxyUrl, '/') . '/' . urlencode($line);
                    }
                }
                unset($line);
                $m3u8 = implode("\n", $lines);
                header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
                header('Access-Control-Allow-Origin: *');
                header('Cache-Control: no-cache');
                echo $m3u8;
                return;
            }
            header('Location: ' . $playUrl, true, 302);
            return;
        }

        header('Location: ' . $playUrl, true, 302);
    }

    private static function setCorsHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $host = AppConfig::get('host', '');
        if ($origin) {
            $allowed = [];
            if ($host) {
                $scheme = preg_match('#^https://#', $origin) ? 'https' : 'http';
                $hostName = preg_replace('#^https?://#', '', $host);
                $allowed[] = $scheme . '://' . $hostName;
                $allowed[] = 'http://' . $hostName;
            }
            $allowed[] = 'http://127.0.0.1:10800';
            $allowed[] = 'http://localhost:10800';
            $requestHost = $_SERVER['HTTP_HOST'] ?? '';
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $allowed[] = $scheme . '://' . $requestHost;
            $allowed = array_unique($allowed);
            if (in_array($origin, $allowed)) {
                header("Access-Control-Allow-Origin: {$origin}");
            }
        }
        header('Access-Control-Allow-Methods: GET, POST, HEAD, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }

    private static function isSafeExternalUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        if ($host === '' || $host === 'localhost' || $host === '0.0.0.0') return false;
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        if (preg_match('#\.(internal|local|localdomain|intra|corp|private|home|lan)$#i', $host)) return false;
        return true;
    }

    private static function showErrorPage(string $title, string $message, string $link = '/', string $linkText = '返回首页'): void
    {
        $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $link = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $linkText = htmlspecialchars($linkText, ENT_QUOTES, 'UTF-8');
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title}</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:#f5f5f7; }
  .card { background:#fff; border-radius:16px; padding:48px 40px; max-width:420px; width:90%; text-align:center; box-shadow:0 2px 20px rgba(0,0,0,0.06); }
  .icon { width:64px; height:64px; margin:0 auto 24px; border-radius:50%; background:linear-gradient(135deg,#FF9500,#FF3B30); display:flex; align-items:center; justify-content:center; }
  .icon svg { width:32px; height:32px; fill:#fff; }
  h1 { font-size:20px; font-weight:600; color:#1d1d1f; margin-bottom:8px; }
  p { font-size:14px; color:#86868b; line-height:1.6; margin-bottom:24px; }
  .btn { display:inline-block; padding:12px 32px; border-radius:10px; background:linear-gradient(135deg,#0A84FF,#5AC8FA); color:#fff; text-decoration:none; font-size:15px; font-weight:500; transition:opacity 0.2s; }
  .btn:hover { opacity:0.9; }
</style>
</head>
<body>
<div class="card">
  <div class="icon"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg></div>
  <h1>{$title}</h1>
  <p>{$message}</p>
  <a class="btn" href="{$link}">{$linkText}</a>
</div>
</body>
</html>
HTML;
    }

    private static function checkAndRefresh(): void
    {
        try {
            $pdo = \App\Services\Database::getInstance();
            $row = $pdo->query("SELECT value FROM system_config WHERE key='last_refresh'")->fetch();
            $lastRefresh = $row ? (int)$row['value'] : 0;
            $interval = (int)AppConfig::get('programInfoUpdateInterval', '8');
            $hours = max($interval, 1);

            if ((time() - $lastRefresh) < $hours * 3600) {
                return;
            }

            $phpBinary = PHP_BINARY;
            $script = dirname(__DIR__, 2) . '/cron_refresh.php';
            $cmd = "\"{$phpBinary}\" \"{$script}\" 2>&1";
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open($cmd, $descriptors, $pipes);
            if (is_resource($process)) {
                if (isset($pipes[0])) fclose($pipes[0]);
                if (isset($pipes[1])) fclose($pipes[1]);
                if (isset($pipes[2])) fclose($pipes[2]);
            }
        } catch (\Exception $e) {
            // silently fail
        }
    }

    private static function checkAndProbe(): void
    {
        try {
            $pdo = \App\Services\Database::getInstance();
            $row = $pdo->query("SELECT value FROM system_config WHERE key='last_probe'")->fetch();
            $lastProbe = $row ? (int)$row['value'] : 0;
            $probeIntervalMinutes = max(1, (int)AppConfig::get('probeInterval', '30'));
            $probeInterval = $probeIntervalMinutes * 60;

            if ((time() - $lastProbe) < $probeInterval) {
                return;
            }

            $phpBinary = PHP_BINARY;
            $script = dirname(__DIR__, 2) . '/cron_probe.php';
            $cmd = "\"{$phpBinary}\" \"{$script}\" 2>&1";
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open($cmd, $descriptors, $pipes);
            if (is_resource($process)) {
                if (isset($pipes[0])) fclose($pipes[0]);
                if (isset($pipes[1])) fclose($pipes[1]);
                if (isset($pipes[2])) fclose($pipes[2]);
            }
        } catch (\Exception $e) {
            // silently fail
        }
    }
}
