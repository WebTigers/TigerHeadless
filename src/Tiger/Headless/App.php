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
