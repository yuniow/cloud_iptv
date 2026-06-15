<?php
declare(strict_types=1);

namespace App\Helpers;

class ValidationHelper
{
    public static function isEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function isUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    public static function isM3u8Url(string $value): bool
    {
        return str_ends_with(strtolower($value), '.m3u8') ||
               str_contains($value, 'm3u8') ||
               str_contains($value, 'm3u');
    }

    public static function isAlphanumeric(string $value): bool
    {
        return ctype_alnum($value);
    }

    public static function sanitizeString(string $value): string
    {
        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }

    public static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
