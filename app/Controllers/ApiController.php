<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\AppConfig;
use App\Services\MiguService;
use App\Services\ExternalSourceService;
use App\Services\BuiltInSourceService;
use App\Services\PlaylistConfigService;
use App\Services\UpdateService;
use App\Services\RefreshTokenService;
use App\Services\AdminAuthService;
use App\Services\ProbeService;
use App\Services\EpgService;

class ApiController
{
    public function handleApi(string $path, string $method): void
    {
        $path = rtrim($path, '/');

        $publicRoutes = [
            'POST /api/login',
            'GET /api/channels',
            'GET /api/channel-url/{id}',
            'GET /api/epg/programs',
            'GET /api/epg/search',
            'GET /api/check-update',
        ];

        $routeKey = $method . ' ' . preg_replace('#/api/[a-z-]+/\{[^}]+\}#', '/api/{group}/{id}', $path);
        $isPublic = false;
        foreach ($publicRoutes as $pr) {
            $prPattern = preg_replace('#/api/[a-z-]+/\{[^}]+\}#', '/api/{group}/{id}', $pr);
            if ($routeKey === $prPattern || $method . ' ' . $path === $pr) {
                $isPublic = true;
                break;
            }
        }

        if (!$isPublic) {
            $token = '';
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('#^Bearer\s+(.+)$#', $authHeader, $m)) {
                $token = $m[1];
            }
            if ($token === '') {
                $token = $_COOKIE['admin_token'] ?? '';
            }
            if ($token === '' || AdminAuthService::verify($token) === null) {
                $this->json(['success' => false, 'message' => '未登录或登录已过期'], 401);
                return;
            }
        }

        $routes = [
            'POST /api/login' => 'login',
            'POST /api/logout' => 'logout',
            'GET /api/admin/info' => 'adminInfo',
            'POST /api/admin/change-password' => 'changePassword',
            'POST /api/admin/change-username' => 'changeUsername',
            'GET /api/channels' => 'getChannels',
            'POST /api/migu/check' => 'checkMiguAccount',
            'GET /api/channel-url/{id}' => 'getChannelUrl',
            'GET /api/system-config' => 'getSystemConfig',
            'POST /api/system-config' => 'saveSystemConfig',
            'GET /api/external-sources' => 'getExternalSources',
            'POST /api/external-sources' => 'handleExternalSourceAction',
            'GET /api/built-in-sources' => 'getBuiltInSources',
            'GET /api/all-sources' => 'getAllSources',
            'POST /api/source-proxy' => 'saveSourceProxy',
            'GET /api/my-playlist' => 'getMyPlaylist',
            'GET /api/my-playlist-config' => 'getPlaylistConfig',
            'POST /api/my-playlist-config' => 'savePlaylistConfig',
            'POST /api/channel-source' => 'switchChannelSource',
            'POST /api/probe' => 'probeChannels',
            'POST /api/probe/single' => 'probeSingleChannel',
            'GET /api/probe/results' => 'getProbeResults',
            'GET /api/epg/sources' => 'getEpgSources',
            'POST /api/epg/sources' => 'saveEpgSource',
            'DELETE /api/epg/sources' => 'removeEpgSource',
            'POST /api/epg/refresh' => 'refreshEpg',
            'GET /api/epg/programs' => 'getEpgPrograms',
            'GET /api/epg/stats' => 'getEpgStats',
            'GET /api/epg/search' => 'searchEpgChannels',
            'GET /api/check-update' => 'checkUpdate',
            'POST /api/refresh' => 'refreshAll',
            'GET /api/devices' => 'getDevices',
            'POST /api/devices/block' => 'blockDevice',
            'POST /api/devices/unblock' => 'unblockDevice',
            'GET /api/devices/blocked' => 'getBlockedDevices',
            'GET /api/channel-stats' => 'getChannelStats',
        ];

        foreach ($routes as $route => $handler) {
            [$routeMethod, $routePattern] = explode(' ', $route, 2);
            if ($method !== $routeMethod) {
                continue;
            }
            $params = $this->matchRoute($routePattern, $path);
            if ($params !== false) {
                $this->$handler($params);
                return;
            }
        }

        $this->json(['success' => false, 'message' => '接口不存在'], 404);
    }

    private function matchRoute(string $pattern, string $path): mixed
    {
        $pattern = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        if (preg_match($pattern, $path, $matches)) {
            return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        }
        return false;
    }

    private function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        echo $json;
    }

    private function readBody(): array
    {
        $raw = file_get_contents('php://input');
        return json_decode($raw, true) ?? [];
    }

    private function checkMiguAccount(): void
    {
        try {
            $data = $this->readBody();
            $userId = $data['userId'] ?? AppConfig::get('userId', '');
            $token = $data['token'] ?? AppConfig::get('token', '');

            if ($userId === '' || $token === '') {
                $this->json(['success' => false, 'valid' => false, 'message' => '未填写咪咕账号信息', 'memberType' => 'none']);
                return;
            }

            $timestamp = round(microtime(true) * 1000);
            $appVersion = '26000370';
            $pid = '608807420';
            $str = $timestamp . $pid . $appVersion;
            $md5 = \App\Helpers\CryptoHelper::md5($str);
            $salt = 1230024;
            $suffix = '3ce941cc3cbc40528bfd1c64f9fdf6c0migu0123';
            $sign = \App\Helpers\CryptoHelper::md5($md5 . $suffix);

            $headers = [
                'AppVersion' => '2600037000',
                'TerminalId' => 'android',
                'X-UP-CLIENT-CHANNEL-ID' => '2600037000-99000-200300220100002',
                'UserId' => $userId,
                'UserToken' => $token,
            ];
            $url = "https://play.miguvideo.com/playurl/v1/play/playurl?sign={$sign}&rateType=4&contId={$pid}&timestamp={$timestamp}&salt={$salt}&flvEnable=true&super4k=true";

            $response = \App\Helpers\HttpHelper::get($url, $headers, 10);
            $result = json_decode($response ?? '{}', true);

            $rid = $result['rid'] ?? '';
            $auth = $result['body']['auth'] ?? [];
            $urlInfo = $result['body']['urlInfo'] ?? [];
            $login = $auth['logined'] ?? false;
            $authResult = $auth['authResult'] ?? '';
            $resolvedRate = (int)($urlInfo['rateType'] ?? 0);

            if ($rid === 'TIPS_NEED_MEMBER') {
                $this->json(['success' => true, 'valid' => true, 'memberType' => 'basic', 'message' => '账号有效，但非 VIP 会员（最高 720p）']);
            } elseif ($login && $authResult === 'FAIL') {
                $this->json(['success' => true, 'valid' => false, 'memberType' => 'expired', 'message' => '账号已过期或认证失败']);
            } elseif ($login) {
                $memberType = $resolvedRate >= 9 ? 'diamond' : ($resolvedRate >= 4 ? 'vip' : 'basic');
                $memberLabels = ['diamond' => '钻石 VIP（4K）', 'vip' => 'VIP（1080p）', 'basic' => '普通会员（720p）'];
                $this->json(['success' => true, 'valid' => true, 'memberType' => $memberType, 'message' => "账号有效，会员等级：{$memberLabels[$memberType]}，最高可用画质 {$resolvedRate}p"]);
            } else {
                $this->json(['success' => true, 'valid' => false, 'memberType' => 'unknown', 'message' => '无法验证账号状态，请检查 userId 和 token 是否正确']);
            }
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '检测失败: ' . $e->getMessage()], 500);
        }
    }

    private function getChannels(): void
    {
        @set_time_limit(120);
        @ini_set('max_execution_time', '120');
        try {
            $channels = MiguService::refreshChannels();
            $builtInChannels = BuiltInSourceService::getChannels();
            $externalChannels = ExternalSourceService::getValidChannels();
            $allChannels = array_merge($channels, $builtInChannels, $externalChannels);
            $this->json(['success' => true, 'data' => $allChannels]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 500);
        }
    }

    private function getChannelUrl(array $params): void
    {
        try {
            $pid = $params['id'] ?? '';
            if (!is_numeric($pid)) {
                $this->json(['success' => false, 'message' => '无效的频道ID'], 400);
                return;
            }

            $userId = AppConfig::get('userId', '');
            $token = AppConfig::get('token', '');
            $rateType = (int)AppConfig::get('rateType', 3);
            $enableHDR = AppConfig::get('enableHDR', true);
            $enableH265 = AppConfig::get('enableH265', true);

            if ($rateType >= 3 && ($userId === '' || $token === '')) {
                $resObj = \App\Helpers\CryptoHelper::getAndroidUrl720p($pid);
            } else {
                $resObj = \App\Helpers\CryptoHelper::getAndroidUrl($userId, $token, $pid, $rateType, $enableHDR, $enableH265);
            }

            if (!empty($resObj['url'])) {
                $location = \App\Helpers\CryptoHelper::get302Url($resObj);
                if ($location !== '') {
                    $resObj['url'] = $location;
                }
            }

            $playUrl = $resObj['url'] ?? '';
            if ($playUrl === '') {
                $this->json(['success' => false, 'message' => '节目调整，暂不提供服务'], 503);
                return;
            }

            $this->json(['success' => true, 'url' => $playUrl]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 500);
        }
    }

    private static function getCliBinary(): string
    {
        $binary = PHP_BINARY;
        if (stripos($binary, 'php-fpm') !== false || stripos($binary, 'php-cgi') !== false) {
            $candidates = [
                dirname($binary) . '/php',
                dirname(dirname($binary)) . '/bin/php',
                preg_replace('#php-(fpm|cgi).*#', 'php', $binary),
            ];
            foreach ($candidates as $path) {
                $resolved = realpath($path);
                if ($resolved && is_executable($resolved)) {
                    return $resolved;
                }
            }
        }
        return $binary;
    }

    private function getSystemConfig(): void
    {
        $data = [
            'userId' => AppConfig::get('userId', ''),
            'token' => AppConfig::get('token', ''),
            'host' => AppConfig::get('host', ''),
            'rateType' => (int)AppConfig::get('rateType', 3),
            'pass' => AppConfig::get('pass', ''),
            'channelPass' => AppConfig::get('channelPass', ''),
            'enableHDR' => AppConfig::get('enableHDR', true),
            'enableH265' => AppConfig::get('enableH265', true),
            'programInfoUpdateInterval' => AppConfig::get('programInfoUpdateInterval', '8'),
            'refreshToken' => AppConfig::get('refreshToken', true),
            'adminPath' => AppConfig::get('adminPath', 'admin'),
            'enableMigu' => AppConfig::get('enableMigu', true),
            'enableBuiltInSources' => AppConfig::get('enableBuiltInSources', true),
            'enableBuiltInSubscriptions' => AppConfig::get('enableBuiltInSubscriptions', true),
            'probeInterval' => AppConfig::get('probeInterval', '30'),
            'proxyEnabled' => AppConfig::get('proxyEnabled', false),
            'proxyUrl' => AppConfig::get('proxyUrl', ''),
            'miguProxy' => AppConfig::get('miguProxy', ''),
            'builtInProxy' => AppConfig::get('builtInProxy', ''),
            'phpBinary' => self::getCliBinary(),
            'projectRoot' => dirname(__DIR__, 2),
        ];
        $this->json(['success' => true, 'data' => $data]);
    }

    private function saveSystemConfig(): void
    {
        try {
            $config = $this->readBody();
            AppConfig::save($config);
            $this->json(['success' => true, 'message' => '配置保存成功']);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 500);
        }
    }

    private function getExternalSources(): void
    {
        $config = ExternalSourceService::loadSources();
        $this->json(['success' => true, 'data' => $config]);
    }

    private function handleExternalSourceAction(): void
    {
        try {
            $data = $this->readBody();
            $action = $data['action'] ?? '';

            $result = match ($action) {
                'save' => ExternalSourceService::saveSources($data['sources'] ?? []),
                'add' => ExternalSourceService::addSource($data['source'] ?? []),
                'remove' => ExternalSourceService::removeSource($data['index'] ?? -1),
                'update' => ExternalSourceService::updateSource($data['index'] ?? -1),
                'setM3u8' => ExternalSourceService::setM3u8Url($data['index'] ?? -1, $data['m3u8Url'] ?? ''),
                'importSubscription' => ExternalSourceService::updateSubscriptionSource($data['index'] ?? -1),
                default => ['success' => false, 'message' => '未知操作'],
            };

            $this->json($result, $result['success'] ? 200 : 500);

            if ($result['success']) {
                self::triggerBackgroundRefresh();
            }
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 400);
        }
    }

    private static function triggerBackgroundRefresh(): void
    {
        try {
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
        } catch (\Exception $e) {}
    }

    private static function triggerBackgroundProbe(): void
    {
        try {
            $phpBinary = PHP_BINARY;
            $script = dirname(__DIR__, 2) . '/cron_probe.php';
            $cmd = "\"{$phpBinary}\" \"{$script}\" 2>&1";
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open($cmd, $descriptors, $pipes);
            if (is_resource($process)) {
                if (isset($pipes[0])) fclose($pipes[0]);
                if (isset($pipes[1])) fclose($pipes[1]);
                if (isset($pipes[2])) fclose($pipes[2]);
                proc_close($process);
            }
        } catch (\Exception $e) {}
    }

    private function getBuiltInSources(): void
    {
        $config = BuiltInSourceService::getSourceConfig();
        $this->json(['success' => true, 'data' => $config]);
    }

    private function getAllSources(): void
    {
        $result = [];

        // 咪咕源 - 从 channel-sources.json 统计
        $enableMigu = AppConfig::get('enableMigu', true);
        $userId = AppConfig::get('userId', '');
        $token = AppConfig::get('token', '');
        $miguChannelCount = 0;
        $miguGroups = [];
        $miguGroupCount = 0;
        $csPath = dirname(__DIR__, 2) . '/data/channel-sources.json';
        if (file_exists($csPath)) {
            $csData = @json_decode(file_get_contents($csPath), true);
            if (is_array($csData)) {
                foreach ($csData as $key => $ch) {
                    $sources = $ch['sources'] ?? [];
                    foreach ($sources as $s) {
                        if (($s['source'] ?? '') === '咪咕') {
                            $miguChannelCount++;
                            $g = $ch['groupName'] ?? '咪咕';
                            if (!isset($miguGroups[$g])) { $miguGroups[$g] = 0; $miguGroupCount++; }
                            $miguGroups[$g]++;
                            break;
                        }
                    }
                }
            }
        }
        $result[] = [
            'id' => 'migu',
            'name' => '咪咕视频源',
            'type' => 'migu',
            'enabled' => $enableMigu,
            'hasAccount' => ($userId !== '' && $token !== ''),
            'channelCount' => $miguChannelCount,
            'groupCount' => $miguGroupCount,
            'groups' => $miguGroups,
            'lastUpdated' => file_exists($csPath) ? date('Y-m-d H:i:s', filemtime($csPath)) : null,
            'proxy' => AppConfig::get('miguProxy', ''),
        ];

        // 内置单频道源
        $builtInConfig = BuiltInSourceService::getSourceConfig();
        $builtInEnabled = $builtInConfig['enabled'] ?? true;
        $builtInSources = $builtInConfig['sources'] ?? [];
        $builtInActive = 0;
        $builtInChannels = 0;
        $builtInGroups = [];
        foreach ($builtInSources as $bs) {
            if ($bs['enabled'] ?? false) {
                $builtInActive++;
                $builtInChannels++;
                $g = $bs['group'] ?? '未分组';
                if (!isset($builtInGroups[$g])) $builtInGroups[$g] = 0;
                $builtInGroups[$g]++;
            }
        }
        $result[] = [
            'id' => 'built-in',
            'name' => '内置单频道源',
            'type' => 'built-in',
            'enabled' => $builtInEnabled,
            'channelCount' => $builtInChannels,
            'groupCount' => count($builtInGroups),
            'groups' => $builtInGroups,
            'sourceCount' => count($builtInSources),
            'activeCount' => $builtInActive,
            'sources' => $builtInSources,
            'proxy' => AppConfig::get('builtInProxy', ''),
        ];

        // 外部源
        $extConfig = ExternalSourceService::loadSources();
        $extSources = $extConfig['sources'] ?? [];
        $extActive = 0;
        foreach ($extSources as $es) {
            if ($es['enabled'] ?? false) $extActive++;
        }
        $result[] = [
            'id' => 'external',
            'name' => '自定义源',
            'type' => 'external',
            'enabled' => true,
            'channelCount' => 0,
            'sourceCount' => count($extSources),
            'activeCount' => $extActive,
            'sources' => $extSources,
        ];

        $this->json(['success' => true, 'data' => $result]);
    }

    private function saveSourceProxy(): void
    {
        try {
            $data = $this->readBody();
            $sourceType = $data['sourceType'] ?? '';
            $proxy = $data['proxy'] ?? '';
            $configKey = match ($sourceType) {
                'migu' => 'miguProxy',
                'built-in' => 'builtInProxy',
                default => '',
            };
            if ($configKey === '') {
                $this->json(['success' => false, 'message' => '不支持的源类型'], 400);
                return;
            }
            AppConfig::set($configKey, $proxy);
            $pdo = \App\Services\Database::getInstance();
            $pdo->prepare("INSERT OR REPLACE INTO system_config (key, value, updated_at) VALUES (?, ?, datetime('now'))")->execute([$configKey, $proxy]);
            $this->json(['success' => true, 'message' => '代理设置已保存']);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 500);
        }
    }

    private function getMyPlaylist(): void
    {
        try {
            @ini_set('memory_limit', '256M');
            $groups = PlaylistConfigService::parseInterfaceTxt();
            $config = PlaylistConfigService::readConfig();
            $result = PlaylistConfigService::applyConfig($groups, $config);

            // Collect all channel names from playlist
            $channelNames = [];
            foreach ($result as $group) {
                foreach ($group['channels'] as $ch) {
                    $channelNames[$ch['name']] = true;
                }
            }

            // Load EPG data and build lookup by channel name
            $epgPath = dirname(__DIR__, 2) . '/data/epg-programs.json';
            $epgByName = [];
            if (file_exists($epgPath) && !empty($channelNames)) {
                $now = date('Y-m-d H:i:s');
                $fp = fopen($epgPath, 'r');
                if ($fp) {
                    $content = stream_get_contents($fp);
                    fclose($fp);
                    $epgData = json_decode($content, true) ?? [];
                    unset($content);

                    foreach ($epgData as $prog) {
                        $name = $prog['channel_name'] ?? '';
                        if ($name === '' || !isset($channelNames[$name])) continue;
                        if (!isset($epgByName[$name])) {
                            $epgByName[$name] = ['now' => '', 'next' => '', 'icon' => ''];
                        }
                        $start = $prog['start_time'] ?? '';
                        $end = $prog['end_time'] ?? '';
                        if ($start <= $now && $end > $now) {
                            $epgByName[$name]['now'] = $prog['title'] ?? '';
                        } elseif ($start > $now && $epgByName[$name]['next'] === '') {
                            $epgByName[$name]['next'] = $prog['title'] ?? '';
                        }
                        if ($epgByName[$name]['icon'] === '' && ($prog['icon'] ?? '') !== '') {
                            $epgByName[$name]['icon'] = $prog['icon'];
                        }
                    }
                    unset($epgData);
                }
            }

            foreach ($result as &$group) {
                foreach ($group['channels'] as &$ch) {
                    $epg = $epgByName[$ch['name']] ?? null;
                    $ch['nowPlaying'] = $epg['now'] ?? '';
                    $ch['nextPlaying'] = $epg['next'] ?? '';
                    $ch['epgIcon'] = $epg['icon'] ?? '';
                }
            }
            unset($group, $ch);

            $this->json(['success' => true, 'data' => $result, 'originalData' => $groups]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 500);
        }
    }

    private function getPlaylistConfig(): void
    {
        try {
            $config = PlaylistConfigService::readConfig();
            $this->json(['success' => true, 'data' => $config]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 500);
        }
    }

    private function savePlaylistConfig(): void
    {
        try {
            $config = $this->readBody();
            $result = PlaylistConfigService::saveConfig($config);
            $this->json($result, $result['success'] ? 200 : 500);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 400);
        }
    }

    private function switchChannelSource(): void
    {
        try {
            $data = $this->readBody();
            $groupName = $data['groupName'] ?? '';
            $channelName = $data['channelName'] ?? '';
            $sourceIndex = (int)($data['sourceIndex'] ?? 0);

            if ($groupName === '' || $channelName === '') {
                $this->json(['success' => false, 'message' => '参数错误'], 400);
                return;
            }

            $config = PlaylistConfigService::readConfig();
            if (!isset($config['channelSourceMap'])) $config['channelSourceMap'] = [];
            $key = "{$groupName}::{$channelName}";
            $config['channelSourceMap'][$key] = $sourceIndex;
            $result = PlaylistConfigService::saveConfig($config);
            $this->json($result, $result['success'] ? 200 : 500);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 400);
        }
    }

    private function probeChannels(): void
    {
        try {
            self::triggerBackgroundProbe();
            $this->json(['success' => true, 'message' => '本批次检测已启动']);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 500);
        }
    }

    private function probeSingleChannel(): void
    {
        try {
            $data = $this->readBody();
            $url = $data['url'] ?? '';
            if ($url === '') {
                $this->json(['success' => false, 'message' => '缺少 url 参数'], 400);
                return;
            }

            $playUrl = $url;
            if (preg_match('#/(\d+)(?:\?|$)#', $url, $m)) {
                $pid = $m[1];
                $userId = AppConfig::get('userId', '');
                $token = AppConfig::get('token', '');
                $rateType = (int)AppConfig::get('rateType', 3);
                $enableHDR = AppConfig::get('enableHDR', true);
                $enableH265 = AppConfig::get('enableH265', true);

                if ($rateType >= 3 && ($userId === '' || $token === '')) {
                    $resObj = \App\Helpers\CryptoHelper::getAndroidUrl720p($pid);
                } else {
                    $resObj = \App\Helpers\CryptoHelper::getAndroidUrl($userId, $token, $pid, $rateType, $enableHDR, $enableH265);
                }

                if (!empty($resObj['url'])) {
                    $location = \App\Helpers\CryptoHelper::get302Url($resObj);
                    if ($location !== '') {
                        $resObj['url'] = $location;
                    }
                }
                $playUrl = $resObj['url'] ?? '';
            }

            if ($playUrl === '' || $playUrl === $url) {
                $result = ['url' => $url, 'status' => 'dead', 'statusCode' => 0, 'latencyMs' => 0, 'checkedAt' => date('c')];
                \App\Services\ProbeService::saveResultsFor($url, $result);
                $this->json(['success' => true, 'data' => $result]);
                return;
            }

            $start = microtime(true);
            $ch = curl_init($playUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                CURLOPT_NOBODY => false,
            ]);
            curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $latency = (int)((microtime(true) - $start) * 1000);

            $body = curl_multi_getcontent($ch) ?? '';
            if ($statusCode === 0 && strlen($body) > 0) {
                $statusCode = 200;
            }

            if ($statusCode === 0 || $statusCode >= 400 || $error) {
                $status = 'dead';
            } elseif ($latency > 5000) {
                $status = 'slow';
            } else {
                $bodyPreview = substr($body, 0, 256);
                $status = preg_match('/<html|<head|<body|<!DOCTYPE/i', $bodyPreview) ? 'dead' : 'alive';
            }

            $result = [
                'url' => $url,
                'status' => $status,
                'statusCode' => $statusCode,
                'latencyMs' => $latency,
                'checkedAt' => date('c'),
            ];

            \App\Services\ProbeService::saveResultsFor($url, $result);
            $this->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 500);
        }
    }

    private function getProbeResults(): void
    {
        $results = ProbeService::getResults();
        $this->json(['success' => true, 'data' => $results]);
    }

    private function getEpgSources(): void
    {
        $sources = EpgService::getSourceList();
        $this->json(['success' => true, 'data' => $sources]);
    }

    private function saveEpgSource(): void
    {
        $data = $this->readBody();
        $action = $data['action'] ?? 'add';
        $result = match ($action) {
            'add' => EpgService::addSource($data['name'] ?? '', $data['url'] ?? ''),
            'remove' => EpgService::removeSource((int)($data['id'] ?? 0)),
            'toggle' => EpgService::toggleSource((int)($data['id'] ?? 0), (bool)($data['enabled'] ?? true)),
            default => ['success' => false, 'message' => '未知操作'],
        };
        $this->json($result, $result['success'] ? 200 : 500);
    }

    private function removeEpgSource(): void
    {
        $data = $this->readBody();
        $result = EpgService::removeSource((int)($data['id'] ?? 0));
        $this->json($result, $result['success'] ? 200 : 500);
    }

    private function refreshEpg(): void
    {
        try {
            @set_time_limit(600);
            @ini_set('memory_limit', '512M');

            $lockFile = dirname(__DIR__, 2) . '/data/epg_refresh.lock';
            if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 600) {
                $this->json(['success' => true, 'message' => 'EPG 正在刷新中，请稍候']);
                return;
            }
            file_put_contents($lockFile, (string)time());

            $results = [];
            $pdo = \App\Services\Database::getInstance();
            $sources = $pdo->query("SELECT * FROM epg_sources WHERE enabled = 1")->fetchAll();

            foreach ($sources as $source) {
                $result = \App\Services\EpgService::fetchSource($source);
                $results[] = $result;
            }

            // Also fetch built-in EPG (Migu + CNTV)
            $builtinResult = \App\Services\EpgService::fetchBuiltinEpg();
            $results[] = $builtinResult;

            @unlink($lockFile);

            $total = array_sum(array_column($results, 'programCount'));
            $this->json(['success' => true, 'message' => "EPG 刷新完成，共 {$total} 条节目", 'data' => $results]);
        } catch (\Exception $e) {
            @unlink($lockFile);
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 500);
        }
    }

    private function getEpgPrograms(): void
    {
        $channel = $_GET['channel'] ?? '';
        if ($channel === '') {
            $this->json(['success' => false, 'message' => '缺少 channel 参数'], 400);
            return;
        }
        $programs = EpgService::getProgramsForChannel($channel);
        $this->json(['success' => true, 'data' => $programs]);
    }

    private function getEpgStats(): void
    {
        $stats = EpgService::getEpgStats();
        $this->json(['success' => true, 'data' => $stats]);
    }

    private function searchEpgChannels(): void
    {
        $keyword = $_GET['q'] ?? '';
        $results = EpgService::searchChannels($keyword);
        $this->json(['success' => true, 'data' => $results]);
    }

    private static function triggerBackgroundEpg(): void
    {
        try {
            $phpBinary = PHP_BINARY;
            $script = dirname(__DIR__, 2) . '/cron_epg.php';
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $cmd = "start /b \"\" \"{$phpBinary}\" \"{$script}\" > NUL 2>&1";
            } else {
                $cmd = "nohup {$phpBinary} {$script} > /dev/null 2>&1 &";
            }
            @exec($cmd);
        } catch (\Exception $e) {}
    }

    private function refreshAll(): void
    {
        try {
            @set_time_limit(600);
            @ini_set('max_execution_time', '600');
            @ignore_user_abort(true);

            $userId = AppConfig::get('userId', '');
            $token = AppConfig::get('token', '');
            $enableMigu = AppConfig::get('enableMigu', true);

            if ($enableMigu && $userId && $token) {
                RefreshTokenService::refreshToken($userId, $token);
            }

            UpdateService::runUpdate(0);
            $this->json(['success' => true, 'message' => '刷新完成']);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 500);
        }
    }

    private function checkUpdate(): void
    {
        $currentVersion = '1.0.0';
        $composerFile = dirname(__DIR__, 2) . '/composer.json';
        if (file_exists($composerFile)) {
            $pkg = json_decode(file_get_contents($composerFile), true);
            $currentVersion = $pkg['version'] ?? '1.0.0';
        }

        $remoteVersion = null;
        $remoteUrl = 'https://raw.githubusercontent.com/yuniow/cloud_iptv/main/package.json';
        $response = \App\Helpers\HttpHelper::get($remoteUrl, [], 5);
        if ($response) {
            $remotePkg = json_decode($response, true);
            $remoteVersion = $remotePkg['version'] ?? null;
        }

        $this->json([
            'success' => true,
            'currentVersion' => $currentVersion,
            'latestVersion' => $remoteVersion,
            'hasUpdate' => $remoteVersion && $remoteVersion !== $currentVersion,
        ]);
    }

    private function getDevices(): void
    {
        try {
            $pdo = \App\Services\Database::getInstance();
            $cutoff = date('Y-m-d H:i:s', strtotime('-7 days'));
            $rows = $pdo->prepare("SELECT client_ip, user_agent, MAX(created_at) as last_active, COUNT(DISTINCT channel_id) as channel_count, COUNT(*) as total_accesses FROM access_logs WHERE created_at >= ? GROUP BY client_ip, user_agent ORDER BY last_active DESC");
            $rows->execute([$cutoff]);
            $rows = $rows->fetchAll();
            $blockedRows = $pdo->query("SELECT * FROM blocked_devices ORDER BY blocked_at DESC")->fetchAll(\PDO::FETCH_ASSOC);
            $blockedMap = [];
            foreach ($blockedRows as $br) { $blockedMap[$br['client_ip']] = $br; }
            foreach ($rows as &$row) {
                $row['blocked'] = isset($blockedMap[$row['client_ip']]);
                if ($row['blocked']) {
                    $row['fingerprint'] = $blockedMap[$row['client_ip']]['fingerprint'] ?? '';
                    $row['device_id'] = $blockedMap[$row['client_ip']]['device_id'] ?? '';
                }
            }
            unset($row);
            $this->json(['success' => true, 'data' => $rows, 'blocked' => $blockedRows]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 500);
        }
    }

    private function blockDevice(): void
    {
        try {
            $data = $this->readBody();
            $clientIp = $data['client_ip'] ?? '';
            $reason = $data['reason'] ?? '';
            if ($clientIp === '') {
                $this->json(['success' => false, 'message' => '缺少 client_ip'], 400);
                return;
            }
            $pdo = \App\Services\Database::getInstance();
            $log = $pdo->prepare("SELECT user_agent FROM access_logs WHERE client_ip = ? ORDER BY id DESC LIMIT 1");
            $log->execute([$clientIp]);
            $row = $log->fetch(\PDO::FETCH_ASSOC);
            $userAgent = $row['user_agent'] ?? '';
            $acceptLanguage = '';
            $fingerprint = md5($clientIp . '|' . $userAgent . '|' . $acceptLanguage);
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO blocked_devices (client_ip, user_agent, accept_language, fingerprint, device_id, reason) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$clientIp, $userAgent, $acceptLanguage, $fingerprint, '', $reason]);
            $this->json(['success' => true, 'message' => '设备已屏蔽']);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 500);
        }
    }

    private function unblockDevice(): void
    {
        try {
            $data = $this->readBody();
            $clientIp = $data['client_ip'] ?? '';
            if ($clientIp === '') {
                $this->json(['success' => false, 'message' => '缺少 client_ip'], 400);
                return;
            }
            $pdo = \App\Services\Database::getInstance();
            $stmt = $pdo->prepare("DELETE FROM blocked_devices WHERE client_ip = ?");
            $stmt->execute([$clientIp]);
            $this->json(['success' => true, 'message' => '设备已解除屏蔽']);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 500);
        }
    }

    private function getBlockedDevices(): void
    {
        try {
            $pdo = \App\Services\Database::getInstance();
            $rows = $pdo->query("SELECT * FROM blocked_devices ORDER BY blocked_at DESC")->fetchAll();
            $this->json(['success' => true, 'data' => $rows]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 500);
        }
    }

    private function getChannelStats(): void
    {
        try {
            $pdo = \App\Services\Database::getInstance();
            $rows = $pdo->query("SELECT channel_id, channel_name, COUNT(*) as play_count, MAX(created_at) as last_played FROM access_logs GROUP BY channel_id ORDER BY play_count DESC")->fetchAll();
            $nameMap = [];
            try {
                $groups = \App\Services\PlaylistConfigService::parseInterfaceTxt();
                foreach ($groups as $group) {
                    foreach ($group['channels'] as $ch) {
                        if (isset($ch['id']) && !empty($ch['name'])) {
                            $nameMap[$ch['id']] = ['name' => $ch['name'], 'group' => $group['name'] ?? '', 'logo' => $ch['logo'] ?? ''];
                        }
                    }
                }
            } catch (\Exception $e) {}
            foreach ($rows as &$row) {
                $id = $row['channel_id'] ?? '';
                if (empty($row['channel_name']) && isset($nameMap[$id])) {
                    $row['channel_name'] = $nameMap[$id]['name'];
                    $row['group_name'] = $nameMap[$id]['group'];
                    $row['logo'] = $nameMap[$id]['logo'];
                } elseif (isset($nameMap[$id])) {
                    $row['group_name'] = $nameMap[$id]['group'] ?? '';
                    $row['logo'] = $nameMap[$id]['logo'] ?? '';
                }
            }
            unset($row);
            $this->json(['success' => true, 'data' => $rows]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => '服务器内部错误，请稍后重试'], 500);
        }
    }

    private function login(): void
    {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        try {
            $pdo = \App\Services\Database::getInstance();
            $row = $pdo->prepare("SELECT value FROM system_config WHERE key = ?");
            $row->execute(['login_rate_' . $clientIp]);
            $rateData = $row->fetch();
            if ($rateData) {
                $d = json_decode($rateData['value'], true);
                if (($d['attempts'] ?? 0) >= 5 && (time() - ($d['first'] ?? 0)) < 60) {
                    $this->json(['success' => false, 'message' => '登录尝试过多，请1分钟后再试'], 429);
                    return;
                }
            }
        } catch (\Exception $e) {}

        $data = $this->readBody();
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $token = AdminAuthService::login($username, $password);
        if ($token) {
            try {
                $pdo = \App\Services\Database::getInstance();
                $pdo->prepare("DELETE FROM system_config WHERE key = ?")->execute(['login_rate_' . $clientIp]);
            } catch (\Exception $e) {}
            $loginToken = is_array($token) ? $token['token'] : $token;
            $forceChange = is_array($token) ? ($token['forceChangePassword'] ?? false) : false;
            setcookie('admin_token', $loginToken, [
                'expires' => time() + 86400,
                'path' => '/',
                'httponly' => true,
                'secure' => true,
                'samesite' => 'Lax',
            ]);
            $this->json(['success' => true, 'token' => $loginToken, 'forceChangePassword' => $forceChange]);
        } else {
            try {
                $pdo = \App\Services\Database::getInstance();
                $row = $pdo->prepare("SELECT value FROM system_config WHERE key = ?");
                $row->execute(['login_rate_' . $clientIp]);
                $rateData = $row->fetch();
                if ($rateData) {
                    $d = json_decode($rateData['value'], true);
                    $attempts = ($d['attempts'] ?? 0) + 1;
                    $first = $d['first'] ?? time();
                } else {
                    $attempts = 1;
                    $first = time();
                }
                $pdo->prepare("INSERT OR REPLACE INTO system_config (key, value, updated_at) VALUES (?, ?, datetime('now'))")
                    ->execute(['login_rate_' . $clientIp, json_encode(['attempts' => $attempts, 'first' => $first])]);
            } catch (\Exception $e) {}
            $this->json(['success' => false, 'message' => '用户名或密码错误'], 401);
        }
    }

    private function logout(): void
    {
        $token = AdminAuthService::getTokenFromRequest();
        AdminAuthService::logout($token);
        setcookie('admin_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $this->json(['success' => true]);
    }

    private function adminInfo(): void
    {
        $token = AdminAuthService::getTokenFromRequest();
        $admin = AdminAuthService::verify($token);
        if (!$admin) {
            $this->json(['success' => false, 'message' => '未登录'], 401);
            return;
        }
        $this->json(['success' => true, 'data' => ['id' => $admin['id'], 'username' => $admin['username']]]);
    }

    private function changePassword(): void
    {
        $token = AdminAuthService::getTokenFromRequest();
        $admin = AdminAuthService::verify($token);
        if (!$admin) {
            $this->json(['success' => false, 'message' => '未登录'], 401);
            return;
        }
        $data = $this->readBody();
        $result = AdminAuthService::changePassword((int)$admin['id'], $data['oldPassword'] ?? '', $data['newPassword'] ?? '');
        $this->json($result, $result['success'] ? 200 : 400);
    }

    private function changeUsername(): void
    {
        $token = AdminAuthService::getTokenFromRequest();
        $admin = AdminAuthService::verify($token);
        if (!$admin) {
            $this->json(['success' => false, 'message' => '未登录'], 401);
            return;
        }
        $data = $this->readBody();
        $result = AdminAuthService::changeUsername((int)$admin['id'], $data['newUsername'] ?? '');
        $this->json($result, $result['success'] ? 200 : 400);
    }
}
