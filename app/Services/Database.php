<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

class Database
{
    private static ?PDO $pdo = null;
    private static string $dbPath = '';

    public static function getInstance(): PDO
    {
        if (self::$pdo === null) {
            self::$dbPath = self::getDbPath();
            self::$pdo = new PDO('sqlite:' . self::$dbPath);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$pdo->exec('PRAGMA journal_mode=WAL');
            self::$pdo->exec('PRAGMA foreign_keys=ON');
            self::$pdo->exec('PRAGMA busy_timeout=5000');
        }
        return self::$pdo;
    }

    private static function getDbPath(): string
    {
        if (self::$dbPath !== '') {
            return self::$dbPath;
        }
        $dataDir = dirname(__DIR__, 2) . '/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        return $dataDir . '/cloudiptv.db';
    }

    public static function init(): void
    {
        $pdo = self::getInstance();

        $pdo->exec("CREATE TABLE IF NOT EXISTS system_config (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS playlist_config (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS external_sources (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            group_name TEXT DEFAULT '未分组',
            mode TEXT NOT NULL DEFAULT 'direct',
            url TEXT,
            web_url TEXT,
            subscription_url TEXT,
            m3u8_url TEXT,
            logo TEXT DEFAULT '',
            enabled INTEGER NOT NULL DEFAULT 1,
            auto_refresh INTEGER NOT NULL DEFAULT 1,
            refresh_interval INTEGER DEFAULT 240,
            update_on_startup INTEGER NOT NULL DEFAULT 1,
            last_updated DATETIME,
            extract_options TEXT,
            parsed_channels TEXT,
            description TEXT DEFAULT '',
            error_message TEXT,
            proxy TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS channels (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            display_name TEXT,
            group_name TEXT NOT NULL DEFAULT '未分组',
            url TEXT,
            logo TEXT,
            tvg_id TEXT,
            tvg_name TEXT,
            source TEXT NOT NULL DEFAULT 'external',
            source_id TEXT,
            enabled INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            extra TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS epg_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            last_updated DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS epg_programs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel_id TEXT NOT NULL,
            channel_name TEXT NOT NULL,
            epg_name TEXT NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            title TEXT NOT NULL,
            icon TEXT DEFAULT '',
            source_id INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_epg_programs_channel ON epg_programs(channel_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_epg_programs_time ON epg_programs(start_time, end_time)");

        // Migration: add source_id column if missing
        try {
            $pdo->exec("ALTER TABLE epg_programs ADD COLUMN source_id INTEGER DEFAULT 0");
        } catch (\Exception $e) {
            // Column already exists, ignore
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            display_name TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0,
            hidden INTEGER NOT NULL DEFAULT 0,
            is_custom INTEGER NOT NULL DEFAULT 0,
            icon TEXT,
            color TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_channels_group ON channels(group_name)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_channels_source ON channels(source)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_channels_enabled ON channels(enabled)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS access_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel_id TEXT,
            channel_name TEXT,
            client_ip TEXT,
            user_agent TEXT,
            access_type TEXT DEFAULT 'play',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_logs_created ON access_logs(created_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_logs_channel ON access_logs(channel_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_logs_ip ON access_logs(client_ip)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS blocked_devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_ip TEXT NOT NULL,
            user_agent TEXT DEFAULT '',
            accept_language TEXT DEFAULT '',
            fingerprint TEXT DEFAULT '',
            device_id TEXT DEFAULT '',
            reason TEXT DEFAULT '',
            blocked_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_blocked_devices_ip ON blocked_devices(client_ip)");

        foreach (['accept_language', 'fingerprint', 'device_id'] as $col) {
            try { $pdo->exec("ALTER TABLE blocked_devices ADD COLUMN {$col} TEXT DEFAULT ''"); } catch (\Exception $e) {}
        }

        try { $pdo->exec("ALTER TABLE external_sources ADD COLUMN proxy TEXT DEFAULT ''"); } catch (\Exception $e) {}

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_blocked_devices_fp ON blocked_devices(fingerprint)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_blocked_devices_did ON blocked_devices(device_id)");
    }

    public static function seedDefaults(): void
    {
        $pdo = self::getInstance();

        $count = (int)$pdo->query("SELECT COUNT(*) FROM system_config")->fetchColumn();
        if ($count === 0) {
            $defaults = [
                'userId' => '', 'token' => '', 'port' => '1905', 'host' => '',
                'rateType' => '3', 'pass' => '', 'enableHDR' => '1', 'enableH265' => '1',
                'programInfoUpdateInterval' => '8', 'refreshToken' => '1', 'adminPath' => 'admin',
                'blank' => '0', 'enableMigu' => '1', 'enableBuiltInSources' => '1',
                'enableBuiltInSubscriptions' => '1', 'probeInterval' => '30',
            ];
            $stmt = $pdo->prepare("INSERT INTO system_config (key, value) VALUES (?, ?)");
            foreach ($defaults as $k => $v) {
                $stmt->execute([$k, $v]);
            }
        }

        $count = (int)$pdo->query("SELECT COUNT(*) FROM playlist_config")->fetchColumn();
        if ($count === 0) {
            $defaults = [
                'channelGroupMap' => '{}', 'channelRenameMap' => '{}', 'channelOrder' => '{}',
                'hiddenChannels' => '[]', 'customGroups' => '[]', 'groupOrder' => '[]',
                'deletedGroups' => '[]', 'groupRenameMap' => '{}',
            ];
            $stmt = $pdo->prepare("INSERT INTO playlist_config (key, value) VALUES (?, ?)");
            foreach ($defaults as $k => $v) {
                $stmt->execute([$k, $v]);
            }
        }

        $count = (int)$pdo->query("SELECT COUNT(*) FROM groups")->fetchColumn();
        if ($count === 0) {
            $pdo->exec("INSERT INTO groups (name, sort_order, icon, color) VALUES
                ('央视', 1, 'tv', '#007aff'),
                ('体育', 2, 'trophy', '#ff9500'),
                ('影视', 3, 'film', '#af52de'),
                ('新闻', 4, 'newspaper', '#ff3b30'),
                ('国际', 5, 'globe', '#5ac8fa'),
                ('地方', 6, 'map-pin', '#34c759'),
                ('音乐', 7, 'music', '#ff2d55'),
                ('少儿', 8, 'baby', '#ffcc00'),
                ('教育', 9, 'book-open', '#5856d6'),
                ('未分组', 10, 'inbox', '#86868b')");
        }

        $count = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        if ($count === 0) {
            $hash = password_hash('admin', PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO admins (username, password_hash) VALUES (?, ?)")->execute(['admin', $hash]);
        }
    }

    public static function getDbPathForCopy(): string
    {
        return self::getDbPath();
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
