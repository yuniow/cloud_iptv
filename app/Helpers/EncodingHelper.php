<?php
declare(strict_types=1);

namespace App\Helpers;

class EncodingHelper
{
    public static function autoDecode(string $content): string
    {
        if (self::hasBom($content)) {
            $content = self::removeBom($content);
        }

        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        $decoded = mb_convert_encoding($content, 'UTF-8', 'GBK, GB2312, BIG5, AUTO');
        if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8')) {
            return $decoded;
        }

        return $content;
    }

    private static function hasBom(string $content): bool
    {
        return str_starts_with($content, "\xEF\xBB\xBF");
    }

    private static function removeBom(string $content): string
    {
        return substr($content, 3);
    }

    public static function detectEncoding(string $content): string
    {
        if (self::hasBom($content)) {
            return 'UTF-8-BOM';
        }
        if (mb_check_encoding($content, 'UTF-8')) {
            return 'UTF-8';
        }
        if (mb_check_encoding($content, 'GBK')) {
            return 'GBK';
        }
        return 'UNKNOWN';
    }
}
