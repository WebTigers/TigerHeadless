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

    /** Seconds a metadata GET may take by default; a release download uses its own, longer budget. */
    const TIMEOUT = 120;

    /**
     * GET a URL into memory.
     *
     * @param  int $timeout total seconds (connect gets a third of it, at least 3) — a panel page
     *                      rendering a catalog passes something small; the default suits a CLI.
     * @return array{0:?string,1:int} [body|null, http status (0 = transport failure)]
     */
    public static function get($url, $accept = null, $timeout = self::TIMEOUT)
    {
        $timeout = max(1, (int) $timeout);
        $connect = max(3, (int) ceil($timeout / 3));
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $headers = ['User-Agent: ' . self::UA];
            if ($accept) { $headers[] = 'Accept: ' . $accept; }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min($connect, $timeout),
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            return $body === false ? [null, 0] : [(string) $body, $code];
        }
        if (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create([
                'http' => ['header' => 'User-Agent: ' . self::UA . "\r\n" . ($accept ? "Accept: {$accept}\r\n" : ''), 'timeout' => $timeout],
                'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            return $body === false ? [null, 0] : [(string) $body, 200];
        }
        return [null, 0];
    }

    /**
     * Resolve a GitHub ref (branch or tag) to its commit sha so what was installed has an immutable
     * identity. Best effort: the unauthenticated API is rate-limited, and a miss is not an error —
     * the caller keeps the ref it had. A 40-hex ref is already a sha.
     *
     * @return string|null
     */
    public static function githubSha($repo, $ref, $timeout = 8)
    {
        if (preg_match('/^[0-9a-f]{40}$/', (string) $ref)) { return $ref; }
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', (string) $repo) || !preg_match('#^[A-Za-z0-9_./-]+$#', (string) $ref)) { return null; }
        list($body, $code) = self::get('https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode($ref), 'application/vnd.github.sha', $timeout);
        return ($body !== null && $code === 200 && preg_match('/^[0-9a-f]{40}$/', trim($body))) ? trim($body) : null;
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
