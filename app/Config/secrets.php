<?php

if (!defined('HB_AES_KEY')) {

    define('HB_AES_KEY',  '5c3d9f2a8b1e4c6079d2f3a4b5c6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f8');


    define('HB_HMAC_KEY', 'X9kL2mQ7vR4nB1eY3pT8wS5uJ6dA0cF');


    define('HB_XKEY1',    0xB7E15163);
    define('HB_XKEY2',    0x9E3779B9);



    define('UNLOCK_SECRET', 'e1f2a3b4c5d6079889a0b1c2d3e4f5061728394a5b6c7d8e9f0a1b2c3d4e5f60');


    define('LIB_ALLOWED', 'libshaitaan.so,libEliteCore.so,libbgmi.so,libc++_shared.so');
}

if (!function_exists('hb_aes_encrypt')) {
    function hb_aes_encrypt(string $plain): string {
        $key   = hex2bin(HB_AES_KEY);
        $nonce = random_bytes(12);
        $tag   = '';
        $ct    = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ct === false) return '';
        return rtrim(strtr(base64_encode($nonce.$tag.$ct), '+/', '-_'), '=');
    }
    function hb_aes_decrypt(string $b64url): ?string {
        $b64 = strtr($b64url, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) $b64 .= str_repeat('=', 4 - $pad);
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 29) return null;
        $key   = hex2bin(HB_AES_KEY);
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key,
                    OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }


    function hb_grant(string $key, string $nonce): string {
        return hash_hmac('sha256', $key.'|'.$nonce, UNLOCK_SECRET);
    }
}

if (!function_exists('hb_b64url_encode')) {
    function hb_b64url_encode(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
    function hb_b64url_decode(string $s): ?string {
        $b64 = strtr($s, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) $b64 .= str_repeat('=', 4 - $pad);
        $raw = base64_decode($b64, true);
        return $raw === false ? null : $raw;
    }



    function hb_sign(string $plain): string {
        $body = hb_b64url_encode($plain);
        return $body.'.'.hash_hmac('sha256', $body, HB_HMAC_KEY);
    }



    function hb_sign_aes(string $plain): string {
        $body = hb_aes_encrypt($plain);
        return $body.'.'.hash_hmac('sha256', $body, HB_HMAC_KEY);
    }
}