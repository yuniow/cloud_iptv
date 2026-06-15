<?php
declare(strict_types=1);

namespace App\Helpers;

class CryptoHelper
{
    private static ?string $KEY_AES = null;
    private static ?string $IV = null;
    private static ?string $RSA_KEY = null;

    private static function getKeyAes(): string
    {
        $val = AppConfig::get('MIGU_AES_KEY', '');
        return self::$KEY_AES ??= $val ?: 'MQDUjI19MGe3BhaqTlpc9g==';
    }

    private static function getIv(): string
    {
        $val = AppConfig::get('MIGU_AES_IV', '');
        return self::$IV ??= $val ?: 'abcdefghijklmnop';
    }

    private static function getRsaKey(): string
    {
        $val = AppConfig::get('MIGU_RSA_PRIVATE_KEY', '');
        return self::$RSA_KEY ??= $val ?: 'MIICdQIBADANBgkqhkiG9w0BAQEFAASCAl8wggJbAgEAAoGBAOhvWsrglBpQGpjB8okxLUCaaiKKOytn9EtvytB5tKDchmgkSaXpreWcDy/9imsuOiVCSdBr6hHjrTN7QKkA4/QYS8ptiFv1ap61PiAyRFDI1b8wp2haJ6HF1rDShG2XdfWIhLk4Hj6efVZASfa3taM7C8NseWoWh05Cp26g4hXZAgMBAAECgYBzqZXghsisH1hc04ZBRrth/nT6Ixc2jlA+ia6+9xEvSw2HHSeY7COgsnvMQbpzg1lj2QyqLkkYBdfWWmrerpa/mb7jm6w95YKs5Ndii8NhFWvC0eGK8Ygt02DeLohmkQu3B+Yq8JszjB7tQJRR2kdG6cPtKp99ZTyyPom/9uD+AQJBAPxCwajHAkCuH4+aKdZhH6n7oDAxZoMH/mihDRxHZJofnT+K662QCCIx0kVCl64s/wZ4YMYbP8/PWDvLMNNWC7ECQQDr4V23KRT9fAPAN8vBq2NqjLAmEx+tVnd4maJ16Xjy5Q4PSRiAXYLSr9uGtneSPP2fd/tja0IyawlP5UPLl76pAkAeXqMWAK+CvfPKxBKZXqQDQOnuI2RmDgZQ7mK3rtirvXae+ciZ4qc4Bqt77yJ3s68YRlHQR+OMzzeeKz47kzZhAkAPteH1ChJw06q4Sb8TdiPX++jbkFiCxgiNCsaMTfGVU/Y8xGSSYCgPelEHxu1t2wwVa/tdYs505zYmkSGT1NaJAkBCS5hymXsAB92Fx8eGW5WpLfnpvxl8nOcP+eNXobi8Sc6q1FmoHi8snbcmBhidcDdcieKn+DbXGG3BQE/OCOkM';
    }

    private const SUFFIX_H5 = '&sv=10000&ct=www';
    private const SUFFIX_ANDROID = '&sv=10004&ct=android';

    private static array $list = [
        'h5' => [
            'keys' => 'yzwxcdabgh',
            'words' => ['', 'y', '0', 'w'],
            'thirdReplaceIndex' => 1,
            'suffix' => '&sv=10000&ct=www',
        ],
        'android' => [
            'keys' => 'cdabyzwxkl',
            'words' => ['v', 'a', '0', 'a'],
            'thirdReplaceIndex' => 6,
            'suffix' => '&sv=10004&ct=android',
        ],
    ];

    public static function md5(string $str): string
    {
        return strtolower(md5($str));
    }

    public static function getDdCalcu(string $puData, string $programId, string $clientType, int $rateType, string $urlUserId = ''): string
    {
        if ($puData === '' || $programId === '' || !in_array($clientType, ['android', 'h5'])) {
            return '';
        }

        $list = self::$list;
        $id = $urlUserId;
        if ($id !== '') {
            $words1 = $list['android']['keys'][$id[7] ?? 0] ?? 'v';
            $list['android']['words'][0] = $words1;
            $list['h5']['words'][0] = $words1;
        }

        $keys = $list[$clientType]['keys'];
        $words = $list[$clientType]['words'];
        $thirdReplaceIndex = $list[$clientType]['thirdReplaceIndex'];

        if ($clientType === 'android' && $rateType === 2) {
            $words[0] = 'v';
        }
        if (strlen($id) > 3 && strlen($id) <= 8) {
            $words[0] = 'e';
        }

        $puDataLength = strlen($puData);
        $ddCalcu = [];
        $dateStr = self::getDateString(new \DateTime());

        for ($i = 0; $i < intdiv($puDataLength, 2); $i++) {
            $ddCalcu[] = $puData[$puDataLength - $i - 1];
            $ddCalcu[] = $puData[$i];
            switch ($i) {
                case 1:
                    $ddCalcu[] = $words[$i - 1];
                    break;
                case 2:
                    $ddCalcu[] = $keys[(int)$dateStr[0]];
                    break;
                case 3:
                    $ddCalcu[] = $keys[$programId[$thirdReplaceIndex] ?? '0'] ?? '';
                    break;
                case 4:
                    $ddCalcu[] = $words[$i - 1];
                    break;
            }
        }
        return implode('', $ddCalcu);
    }

    public static function getDdCalcuUrl(string $puDataURL, string $programId, string $clientType, int $rateType, string $urlUserId = ''): string
    {
        if ($puDataURL === '' || $programId === '' || !in_array($clientType, ['android', 'h5'])) {
            return '';
        }

        $parts = explode('&puData=', $puDataURL);
        $puData = $parts[1] ?? '';
        $ddCalcu = self::getDdCalcu($puData, $programId, $clientType, $rateType, $urlUserId);
        $suffix = self::$list[$clientType]['suffix'];

        return $puDataURL . '&ddCalcu=' . $ddCalcu . $suffix;
    }

    public static function getDdCalcuUrl720p(string $puDataURL, string $programId): string
    {
        if ($puDataURL === '' || $programId === '') {
            return '';
        }

        $parts = explode('&puData=', $puDataURL);
        $puData = $parts[1] ?? '';
        $ddCalcu = self::getDdCalcu720p($puData, $programId);
        return $puDataURL . '&ddCalcu=' . $ddCalcu . '&sv=10004&ct=android';
    }

    private static function getDdCalcu720p(string $puData, string $programId): string
    {
        if ($puData === '' || $programId === '') {
            return '';
        }

        $keys = 'cdabyzwxkl';
        $ddCalcu = [];
        $dateStr = self::getDateString(new \DateTime());
        $puDataLength = strlen($puData);

        for ($i = 0; $i < intdiv($puDataLength, 2); $i++) {
            $ddCalcu[] = $puData[$puDataLength - $i - 1];
            $ddCalcu[] = $puData[$i];
            switch ($i) {
                case 1:
                    $ddCalcu[] = 'v';
                    break;
                case 2:
                    $ddCalcu[] = $keys[(int)$dateStr[2]] ?? '';
                    break;
                case 3:
                    $ddCalcu[] = $keys[$programId[6] ?? 0] ?? '';
                    break;
                case 4:
                    $ddCalcu[] = 'a';
                    break;
            }
        }
        return implode('', $ddCalcu);
    }

    public static function getAndroidUrl(string $userId, string $token, string $pid, int $rateType, bool $enableHDR = true, bool $enableH265 = true): array
    {
        if ($rateType <= 1) {
            return ['url' => '', 'rateType' => 0, 'content' => null];
        }

        $timestamp = round(microtime(true) * 1000);
        $appVersion = '26000370';
        $headers = [
            'AppVersion' => '2600037000',
            'TerminalId' => 'android',
            'X-UP-CLIENT-CHANNEL-ID' => '2600037000-99000-200300220100002',
        ];
        if ($pid !== '641886683' && $pid !== '641886773') {
            $headers['appCode'] = 'miguvideo_default_android';
        }
        if ($rateType !== 2 && $userId !== '' && $token !== '') {
            $headers['UserId'] = $userId;
            $headers['UserToken'] = $token;
        }

        $str = $timestamp . $pid . $appVersion;
        $md5 = self::md5($str);
        $salt = 1230024;
        $suffix = '3ce941cc3cbc40528bfd1c64f9fdf6c0migu0123';
        $sign = self::md5($md5 . $suffix);

        $enableHDRStr = '';
        $enableH265Str = '';
        $ottStr = $rateType === 9 ? '&ott=true' : '';

        $baseURL = 'https://play.miguvideo.com/playurl/v1/play/playurl';
        $params = "?sign={$sign}&rateType={$rateType}&contId={$pid}&timestamp={$timestamp}&salt={$salt}&flvEnable=true&super4k=true{$ottStr}{$enableH265Str}{$enableHDRStr}";

        $respData = self::fetchUrl($baseURL . $params, $headers);

        if (isset($respData['rid']) && $respData['rid'] === 'TIPS_NEED_MEMBER') {
            $respRateType = (int)($respData['body']['urlInfo']['rateType'] ?? 3);
            $respRateType = $respRateType > 4 ? 4 : 3;
            $params = "?sign={$sign}&rateType={$respRateType}&contId={$pid}&timestamp={$timestamp}&salt={$salt}&flvEnable=true&super4k=true{$enableH265Str}{$enableHDRStr}";
            $respData = self::fetchUrl($baseURL . $params, $headers);

            if (isset($respData['rid']) && $respData['rid'] === 'TIPS_NEED_MEMBER') {
                $params = "?sign={$sign}&rateType=3&contId={$pid}&timestamp={$timestamp}&salt={$salt}&flvEnable=true&super4k=true{$enableH265Str}{$enableHDRStr}";
                $respData = self::fetchUrl($baseURL . $params, $headers);
            }
        }

        $url = $respData['body']['urlInfo']['url'] ?? '';
        if (!$url) {
            return ['url' => '', 'rateType' => 0, 'content' => $respData];
        }

        $pid = $respData['body']['content']['contId'] ?? $pid;
        $resURL = self::getDdCalcuUrl($url, $pid, 'android', $rateType, $userId);
        $rateType = (int)($respData['body']['urlInfo']['rateType'] ?? $rateType);

        return ['url' => $resURL, 'rateType' => $rateType, 'content' => $respData];
    }

    public static function getAndroidUrl720p(string $pid): array
    {
        $timestamp = round(microtime(true) * 1000);
        $appVersion = '2600034600';
        $appVersionID = $appVersion . '-99000-201600010010028';
        $headers = [
            'AppVersion' => $appVersion,
            'TerminalId' => 'android',
            'X-UP-CLIENT-CHANNEL-ID' => $appVersionID,
        ];
        if ($pid !== '641886683' && $pid !== '641886773') {
            $headers['appCode'] = 'miguvideo_default_android';
        }

        $str = $timestamp . $pid . substr($appVersion, 0, 8);
        $md5 = self::md5($str);

        $salt = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT) . '25';
        $suffixSalt = '2cac4f2c6c3346a5b34e085725ef7e33migu' . substr($salt, 0, 4);
        $sign = self::md5($md5 . $suffixSalt);

        $enableHDRStr = '';
        $enableH265Str = '';

        $baseURL = 'https://play.miguvideo.com/playurl/v1/play/playurl';
        $params = "?sign={$sign}&rateType=3&contId={$pid}&timestamp={$timestamp}&salt={$salt}&flvEnable=true&super4k=true{$enableH265Str}{$enableHDRStr}";

        $respData = self::fetchUrl($baseURL . $params, $headers);

        $url = $respData['body']['urlInfo']['url'] ?? '';
        if (!$url) {
            return ['url' => '', 'rateType' => 0, 'content' => $respData];
        }

        $rateType = (int)($respData['body']['urlInfo']['rateType'] ?? 3);
        $pid = $respData['body']['content']['contId'] ?? $pid;
        $resURL = self::getDdCalcuUrl720p($url, $pid);

        return ['url' => $resURL, 'rateType' => $rateType, 'content' => $respData];
    }

    public static function get302Url(array $resObj): string
    {
        for ($z = 1; $z <= 6; $z++) {
            $ch = curl_init($resObj['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_NOBODY => false,
                CURLOPT_HEADER => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_exec($ch);
            $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);

            if ($location === '' || $location === false || str_starts_with($location, 'http://bofang')) {
                $error = curl_error($ch);
                if ($error && str_contains($error, 'SSL')) {
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                    curl_exec($ch);
                    $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                }
            }

            if ($location !== '' && $location !== false && !str_starts_with($location, 'http://bofang')) {
                return $location;
            }
            if ($z < 6) {
                usleep(150000);
            }
        }
        return '';
    }

    private static function fetchUrl(string $url, array $headers = []): ?array
    {
        $ch = curl_init($url);
        $headerArr = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'];
        foreach ($headers as $k => $v) {
            $headerArr[] = "{$k}: {$v}";
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerArr,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($ch);
        if (!$response) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $response = curl_exec($ch);
            if (!$response) {
                return null;
            }
        }
        return json_decode($response, true);
    }

    private static function getDateString(\DateTime $date): string
    {
        return $date->format('Ymd');
    }

    public static function aesEncrypt(string $data, string $key = '', string $iv = ''): string
    {
        $key = $key ?: self::getKeyAes();
        $iv = $iv ?: self::getIv();
        $keyBytes = str_pad($key, 32, "\0");
        $ivBytes = str_pad($iv, 16, "\0");
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $keyBytes, OPENSSL_RAW_DATA, $ivBytes);
        return base64_encode($encrypted);
    }

    public static function aesDecrypt(string $baseData, string $key = '', string $iv = ''): string
    {
        $key = $key ?: self::getKeyAes();
        $iv = $iv ?: self::getIv();
        $keyBytes = str_pad($key, 32, "\0");
        $ivBytes = str_pad($iv, 16, "\0");
        $data = base64_decode($baseData);
        return openssl_decrypt($data, 'aes-256-cbc', $keyBytes, OPENSSL_RAW_DATA, $ivBytes);
    }

    public static function rsaEncrypt(string $data): string
    {
        $keyBytes = base64_decode(self::getRsaKey());
        $privateKey = openssl_pkey_get_private($keyBytes);
        if (!$privateKey) {
            return '';
        }
        openssl_private_encrypt($data, $encrypted, $privateKey, OPENSSL_PKCS1_PADDING);
        openssl_pkey_free($privateKey);
        return base64_encode($encrypted);
    }
}
