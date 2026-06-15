<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\CryptoHelper;
use App\Helpers\HttpHelper;

class RefreshTokenService
{
    public static function refreshToken(string $userId, string $token): bool
    {
        if ($userId === '' || $token === '') {
            return false;
        }

        $time = (int)(microtime(true));
        $baseData = json_encode([
            'userToken' => $token,
            'autoDelay' => true,
            'deviceId' => '',
            'userId' => $userId,
            'timestamp' => (string)$time,
        ]);

        $encryData = CryptoHelper::aesEncrypt($baseData);
        $data = '{"data":"' . $encryData . '"}';

        $str = CryptoHelper::md5($data);
        $sign = rawurlencode(CryptoHelper::rsaEncrypt($str));

        $headers = [
            'userId' => $userId,
            'userToken' => $token,
            'Content-Type' => 'appsication/json; charset=utf-8',
        ];

        $baseURL = 'https://migu-app-umnb.miguvideo.com/login/token_refresh_migu_plus';
        $params = "?clientId=27fb3129-5a54-45bc-8af1-7dc8f1155501&sign={$sign}&signType=RSA";

        $ch = curl_init($baseURL . $params);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "{$k}: {$v}", array_keys($headers), array_values($headers)),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);

        if (!$response) {
            return false;
        }

        $result = json_decode($response, true);
        return ($result['resultCode'] ?? '') === 'REFRESH_TOKEN_SUCCESS';
    }
}
