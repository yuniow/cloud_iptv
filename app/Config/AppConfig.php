<?php
declare(strict_types=1);

namespace App\Config;

use App\Services\Database;
use PDO;

class AppConfig
{
    private static ?array $config = null;

    public static function load(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $dbConfig = self::loadFromDb();

        self::$config = [
            'userId' => $dbConfig['userId'] ?? '',
            'token' => $dbConfig['token'] ?? '',
            'host' => $dbConfig['host'] ?? '',
            'rateType' => (int)($dbConfig['rateType'] ?? 3),
            'pass' => $dbConfig['pass'] ?? '',
            'channelPass' => $dbConfig['channelPass'] ?? '',
            'enableHDR' => self::toBool($dbConfig['enableHDR'] ?? '1'),
            'enableH265' => self::toBool($dbConfig['enableH265'] ?? '1'),
            'programInfoUpdateInterval' => (string)($dbConfig['programInfoUpdateInterval'] ?? '8'),
            'refreshToken' => self::toBool($dbConfig['refreshToken'] ?? '1'),
            'adminPath' => self::sanitizePath($dbConfig['adminPath'] ?? 'admin'),
            'blank' => self::toBool($dbConfig['blank'] ?? '0'),
            'enableMigu' => self::toBool($dbConfig['enableMigu'] ?? '1'),
            'enableBuiltInSources' => self::toBool($dbConfig['enableBuiltInSources'] ?? '1'),
            'enableBuiltInSubscriptions' => self::toBool($dbConfig['enableBuiltInSubscriptions'] ?? '1'),
            'probeInterval' => (string)($dbConfig['probeInterval'] ?? '30'),
            'proxyEnabled' => self::toBool($dbConfig['proxyEnabled'] ?? '0'),
            'proxyUrl' => $dbConfig['proxyUrl'] ?? '',
            'miguProxy' => $dbConfig['miguProxy'] ?? '',
            'builtInProxy' => $dbConfig['builtInProxy'] ?? '',
            'MIGU_AES_KEY' => $dbConfig['MIGU_AES_KEY'] ?? '',
            'MIGU_AES_IV' => $dbConfig['MIGU_AES_IV'] ?? '',
            'MIGU_RSA_PRIVATE_KEY' => $dbConfig['MIGU_RSA_PRIVATE_KEY'] ?? '',
        ];

        return self::$config;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$config === null) {
            self::load();
        }
        return self::$config[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        if (self::$config === null) {
            self::load();
        }
        self::$config[$key] = $value;
    }

    public static function save(array $config): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_config (key, value, updated_at) VALUES (?, ?, datetime('now'))");

        $fields = [
            'userId' => $config['userId'] ?? '',
            'token' => $config['token'] ?? '',
            'host' => $config['host'] ?? '',
            'rateType' => (string)($config['rateType'] ?? 3),
            'pass' => $config['pass'] ?? '',
            'enableHDR' => ($config['enableHDR'] ?? true) ? '1' : '0',
            'enableH265' => ($config['enableH265'] ?? true) ? '1' : '0',
            'programInfoUpdateInterval' => (string)($config['programInfoUpdateInterval'] ?? '8'),
        ];
        if (isset($config['refreshToken'])) {
            $fields['refreshToken'] = $config['refreshToken'] ? '1' : '0';
        }
        if (isset($config['adminPath'])) {
            $fields['adminPath'] = self::sanitizePath($config['adminPath'], 'admin');
        }
        if (isset($config['enableMigu'])) {
            $fields['enableMigu'] = $config['enableMigu'] ? '1' : '0';
        }
        if (isset($config['enableBuiltInSources'])) {
            $fields['enableBuiltInSources'] = $config['enableBuiltInSources'] ? '1' : '0';
        }
        if (isset($config['enableBuiltInSubscriptions'])) {
            $fields['enableBuiltInSubscriptions'] = $config['enableBuiltInSubscriptions'] ? '1' : '0';
        }
        if (isset($config['probeInterval'])) {
            $fields['probeInterval'] = (string)max(1, (int)$config['probeInterval']);
        }
        if (isset($config['proxyEnabled'])) {
            $fields['proxyEnabled'] = $config['proxyEnabled'] ? '1' : '0';
        }
        if (isset($config['proxyUrl'])) {
            $fields['proxyUrl'] = $config['proxyUrl'];
        }
        if (isset($config['miguProxy'])) {
            $fields['miguProxy'] = $config['miguProxy'];
        }
        if (isset($config['builtInProxy'])) {
            $fields['builtInProxy'] = $config['builtInProxy'];
        }
        if (isset($config['channelPass'])) {
            $fields['channelPass'] = $config['channelPass'];
        }
        foreach (['MIGU_AES_KEY', 'MIGU_AES_IV', 'MIGU_RSA_PRIVATE_KEY'] as $key) {
            if (isset($config[$key]) && $config[$key] !== '') {
                $fields[$key] = $config[$key];
            }
        }

        foreach ($fields as $k => $v) {
            $stmt->execute([$k, $v]);
        }
        self::$config = null;
    }

    public static function reload(): array
    {
        self::$config = null;
        return self::load();
    }

    private static function loadFromDb(): array
    {
        try {
            $pdo = Database::getInstance();
            $rows = $pdo->query("SELECT key, value FROM system_config")->fetchAll();
            $config = [];
            foreach ($rows as $row) {
                $config[$row['key']] = $row['value'];
            }
            return $config;
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        $str = strtolower(trim((string)$value));
        return $str !== '' && $str !== '0' && $str !== 'false' && $str !== 'off' && $str !== 'no';
    }

    private static function sanitizePath(string $value, string $fallback = 'admin'): string
    {
        $reserved = ['api', 'player', 'favicon.ico'];
        $s = strtolower(trim($value, '/ '));
        if ($s === '' || str_contains($s, '/') || str_contains($s, ' ') || in_array($s, $reserved)) {
            return $fallback;
        }
        return $s;
    }
}
