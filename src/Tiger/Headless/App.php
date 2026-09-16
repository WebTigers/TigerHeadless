<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * Boot an installed (or freshly extracted) Tiger tree in this process, once. Everything after the
 * extract step runs against Tiger's OWN library — the installer never re-implements a migration,
 * an owner record or an asset link.
 */
class Tiger_Headless_App
{
    /** @var bool */
    protected static $_booted = false;

    /** Whether a tree looks like an extracted Tiger bundle. */
    public static function looksExtracted($appRoot)
    {
        return is_file($appRoot . '/vendor/autoload.php') && is_dir($appRoot . '/application');
    }

    /** Load Tiger's autoloader without booting (for static helpers that need no config/DB). */
    public static function load($appRoot)
    {
        if (!self::looksExtracted($appRoot)) {
            throw new RuntimeException("No Tiger app at {$appRoot} (vendor/autoload.php missing).");
        }
        if (!defined('APPLICATION_ROOT')) { define('APPLICATION_ROOT', $appRoot); }
        require_once $appRoot . '/vendor/autoload.php';
    }

    /** Boot: constants + config + DB adapter, no dispatch. Idempotent per process. */
    public static function boot($appRoot)
    {
        self::load($appRoot);
        if (!self::$_booted) {
            // Booting Tiger starts a session. Inside a web request (the one-file installer) that must
            // never be the VISITOR's session: the browser may carry a PHPSESSID from a previous site on
            // this domain whose file this account cannot read (cPanel's shared session dir after an
            // account is recreated) — session_start() then fails and the install dies at `migrate`.
            // No cookies in, no cookie out: a private, throwaway session for the install's own boot.
            if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
                @ini_set('session.use_cookies', '0');
                @ini_set('session.use_trans_sid', '0');
                @ini_set('session.cache_limiter', '');
                session_id(bin2hex(random_bytes(16)));
            }
            (new Tiger_Application($appRoot))->boot();
            self::$_booted = true;
        }
    }

    /** Tiger's version on disk — from the extracted tree, no boot required. */
    public static function version($appRoot)
    {
        $file = $appRoot . '/vendor/webtigers/tiger-core/library/Tiger/Version.php';
        if (is_file($file) && preg_match("/VERSION\s*=\s*'([^']+)'/", (string) file_get_contents($file), $m)) {
            return $m[1];
        }
        return '';
    }
}
