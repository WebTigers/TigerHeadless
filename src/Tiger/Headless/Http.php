<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * Outbound HTTP with no dependencies: curl when present, stream wrapper otherwise. TLS is always
 * verified. Always sends a User-Agent — the release host's WAF drops anonymous clients.
 */
class Tiger_Headless_Http
{
    const UA = 'tiger-headless/' . Tiger_Headless_Version::VERSION;

    /** @return bool whether any transport is available at all */
    public static function available()
    {
        return function_exists('curl_init') || (bool) ini_get('allow_url_fopen');
    }

    /**
     * GET a URL into memory.
     *
     * @return array{0:?string,1:int} [body|null, http status (0 = transport failure)]
     */
    public static function get($url, $accept = null)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $headers = ['User-Agent: ' . self::UA];
            if ($accept) { $headers[] = 'Accept: ' . $accept; }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 120,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return $body === false ? [null, 0] : [(string) $body, $code];
        }
        if (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create([
                'http' => ['header' => 'User-Agent: ' . self::UA . "\r\n" . ($accept ? "Accept: {$accept}\r\n" : ''), 'timeout' => 120],
                'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            return $body === false ? [null, 0] : [(string) $body, 200];
        }
        return [null, 0];
    }

    /**
     * Stream a URL to a file. A zero-byte result is a failure — a 200 with nothing in it is how a
     * bad release page or a captive proxy presents.
     *
     * @return bool
     */
    public static function download($url, $dest)
    {
        if (function_exists('curl_init')) {
            $fp = @fopen($dest, 'wb');
            if (!$fp) { return false; }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['User-Agent: ' . self::UA], CURLOPT_TIMEOUT => 600,
            ]);
            $ok   = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            fclose($fp);
            clearstatcache(true, $dest);
            return $ok !== false && $code < 400 && filesize($dest) > 0;
        }
        if (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create([
                'http' => ['header' => 'User-Agent: ' . self::UA . "\r\n", 'timeout' => 600],
                'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $data = @file_get_contents($url, false, $ctx);
            return $data !== false && $data !== '' && @file_put_contents($dest, $data) !== false;
        }
        return false;
    }
}
