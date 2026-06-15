<?php
declare(strict_types=1);

namespace App\Services;

class ProbeService
{
    private const TIMEOUT = 5;
    private const CONCURRENCY = 8;
    private const BATCH_SIZE = 30;
    private const RESULT_TTL = 3600;

    private static string $resultPath = '';

    private static function getResultPath(): string
    {
        if (self::$resultPath === '') {
            self::$resultPath = dirname(__DIR__, 2) . '/data/probe-results.json';
        }
        return self::$resultPath;
    }

    public static function getAllChannelUrls(): array
    {
        $groups = PlaylistConfigService::parseInterfaceTxt();
        $urls = [];
        foreach ($groups as $group) {
            $groupName = $group['name'] ?? '';
            foreach ($group['channels'] ?? [] as $ch) {
                $name = $ch['name'] ?? '';
                $url = $ch['url'] ?? '';
                if ($url === '' || $name === '') continue;
                $urls[] = ['name' => $name, 'groupName' => $groupName, 'url' => $url];
            }
        }
        return $urls;
    }

    public static function probeBatch(int $batchSize = 0): array
    {
        $batchSize = $batchSize > 0 ? $batchSize : self::BATCH_SIZE;
        $existing = self::loadResults();
        $allUrls = self::getAllChannelUrls();

        $toProbe = [];
        $now = time();
        foreach ($allUrls as $item) {
            $url = $item['url'];
            if (isset($existing['results'][$url]) && ($now - strtotime($existing['results'][$url]['checkedAt'] ?? '')) < self::RESULT_TTL) {
                continue;
            }
            $toProbe[] = $item;
            if (count($toProbe) >= $batchSize) break;
        }

        if (empty($toProbe)) {
            return [
                'message' => '所有频道已在有效期内，无需检测',
                'probed' => 0,
                'remaining' => 0,
                'summary' => $existing['summary'] ?? [],
            ];
        }

        $newResults = [];
        foreach ($toProbe as $item) {
            $url = $item['url'];
            $start = microtime(true);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                CURLOPT_NOBODY => false,
            ]);
            $body = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            $latency = (int)((microtime(true) - $start) * 1000);

            if ($statusCode === 0 && $body && strlen($body) > 0) {
                $statusCode = 200;
            }

            if ($statusCode === 0 || $statusCode >= 400 || $error) {
                $status = 'dead';
            } elseif ($latency > 5000) {
                $status = 'slow';
            } else {
                $bodyPreview = substr($body ?? '', 0, 256);
                $status = preg_match('/<html|<head|<body|<!DOCTYPE/i', $bodyPreview) ? 'dead' : 'alive';
            }

            $newResults[$url] = [
                'name' => $item['name'],
                'groupName' => $item['groupName'],
                'url' => $url,
                'status' => $status,
                'statusCode' => $statusCode,
                'latencyMs' => $latency,
                'checkedAt' => date('c'),
            ];
        }

        $allResults = array_merge($existing['results'] ?? [], $newResults);
        self::saveResults($allResults);

        $summary = self::calcSummary($allResults);
        $remaining = count($allUrls) - count($allResults);

        return [
            'message' => "本批检测 " . count($toProbe) . " 个频道",
            'probed' => count($toProbe),
            'remaining' => max(0, $remaining),
            'summary' => $summary,
        ];
    }

    public static function getResults(): array
    {
        return self::loadResults();
    }

    private static function loadResults(): array
    {
        $path = self::getResultPath();
        if (!file_exists($path)) {
            return ['results' => [], 'checkedAt' => null, 'summary' => []];
        }
        return json_decode(file_get_contents($path), true) ?: ['results' => [], 'checkedAt' => null, 'summary' => []];
    }

    private static function saveResults(array $results): void
    {
        $summary = self::calcSummary($results);
        $data = [
            'results' => $results,
            'checkedAt' => date('c'),
            'summary' => $summary,
        ];
        $path = self::getResultPath();
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE));
        rename($tmp, $path);
    }

    public static function saveResultsFor(string $url, array $result): void
    {
        $existing = self::loadResults();
        $existing['results'][$url] = $result;
        $summary = self::calcSummary($existing['results']);
        $existing['checkedAt'] = date('c');
        $existing['summary'] = $summary;
        $path = self::getResultPath();
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($existing, JSON_UNESCAPED_UNICODE));
        rename($tmp, $path);
    }

    private static function calcSummary(array $results): array
    {
        return [
            'total' => count($results),
            'alive' => count(array_filter($results, fn($r) => $r['status'] === 'alive')),
            'slow' => count(array_filter($results, fn($r) => $r['status'] === 'slow')),
            'dead' => count(array_filter($results, fn($r) => $r['status'] === 'dead')),
        ];
    }
}
