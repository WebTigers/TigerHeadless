<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * Resolve a Tiger release to its full-app bundle (tiger-<version>.zip) and the bundle's .sha256.
 * Same rules as the web installer: newest release that actually carries a bundle, pre-releases
 * included (GitHub's /releases/latest skips them), never the vendor-only tiger-core zip.
 */
class Tiger_Headless_Release
{
    const GH_API       = 'https://api.github.com';
    const RELEASE_REPO = 'WebTigers/Tiger';

    /** @var callable|null test seam: fn(string $url, ?string $accept): array{0:?string,1:int} */
    protected static $_fetch;

    /** Replace the HTTP fetcher (tests). */
    public static function setFetcher($fn = null)
    {
        self::$_fetch = $fn;
    }

    /**
     * @param  string $version a tag ('v1.0.17' / '1.0.17'), or '' for the newest bundle-bearing release
     * @return array{tag:string,zip:string,sha:string,name:string}
     * @throws RuntimeException when nothing installable can be resolved
     */
    public static function resolve($version = '')
    {
        $version = ltrim(trim((string) $version), 'v');
        if ($version !== '') {
            $rel = null;
            foreach (['v' . $version, $version] as $tag) {
                list($body, $code) = self::_get(self::GH_API . '/repos/' . self::RELEASE_REPO . '/releases/tags/' . rawurlencode($tag));
                if ($body !== null && $code < 400) { $rel = json_decode($body, true); break; }
            }
            if (!is_array($rel)) {
                throw new RuntimeException("Release {$version} was not found on GitHub (" . self::RELEASE_REPO . ').');
            }
            $releases = [$rel];
        } else {
            list($body, $code) = self::_get(self::GH_API . '/repos/' . self::RELEASE_REPO . '/releases?per_page=20');
            if ($body === null || $code >= 400) {
                throw new RuntimeException('Could not reach GitHub to find a Tiger release (HTTP ' . $code . ').');
            }
            $releases = json_decode($body, true);
            if (!is_array($releases)) { $releases = []; }
        }
        return self::pick($releases);
    }

    /**
     * Choose the bundle from a list of GitHub release objects (newest first). Pure, so it is testable
     * against fixtures.
     *
     * @throws RuntimeException when no release carries a verifiable bundle
     */
    public static function pick(array $releases)
    {
        foreach ($releases as $rel) {
            if (!is_array($rel) || !empty($rel['draft']) || empty($rel['assets']) || !is_array($rel['assets'])) { continue; }
            $zip = null; $name = '';
            foreach ($rel['assets'] as $a) {
                $n = (string) ($a['name'] ?? '');
                if (preg_match('/^tiger-\d[\w.\-]*\.zip$/', $n) && strpos($n, 'core-vendored') === false) {
                    $zip = (string) ($a['browser_download_url'] ?? ''); $name = $n; break;
                }
            }
            if ($zip === null || $zip === '') { continue; }
            $sha = '';
            foreach ($rel['assets'] as $a) {
                if ((string) ($a['name'] ?? '') === $name . '.sha256') { $sha = (string) ($a['browser_download_url'] ?? ''); }
            }
            if ($sha === '') {
                // Fail closed: a bundle we cannot verify is not a bundle we install.
                throw new RuntimeException('Release ' . ($rel['tag_name'] ?? '?') . " has {$name} but no {$name}.sha256 to verify it against.");
            }
            return ['tag' => (string) ($rel['tag_name'] ?? ''), 'zip' => $zip, 'sha' => $sha, 'name' => $name];
        }
        throw new RuntimeException('No release of ' . self::RELEASE_REPO . ' carries a full-app bundle (tiger-<version>.zip).');
    }

    /** Parse a .sha256 asset body ("<hex>  <file>") into the hex digest, or '' if malformed. */
    public static function digestFrom($body)
    {
        $first = preg_split('/\s+/', trim((string) $body));
        $hex   = strtolower((string) ($first[0] ?? ''));
        return preg_match('/^[0-9a-f]{64}$/', $hex) ? $hex : '';
    }

    protected static function _get($url)
    {
        $fn = self::$_fetch;
        return $fn ? $fn($url, 'application/vnd.github+json') : Tiger_Headless_Http::get($url, 'application/vnd.github+json');
    }
}
