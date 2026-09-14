<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * Recognise a Tiger install that is already there — whoever installed it. The ledger only knows
 * about installs THIS tool made; a site put down by the web installer or Composer has no ledger,
 * and must still read as "installed" (so a re-run adopts it rather than failing at the owner step),
 * and must still show up when a hosting panel asks "what Tigers are on this box?".
 *
 * Everything here runs WITHOUT booting Tiger: file checks plus one PDO probe of the configured
 * database, so it can inspect many installs in one process and never defines Tiger's constants.
 */
class Tiger_Headless_Detect
{
    /** Directories never descended into while scanning. */
    const SKIP = ['vendor', 'node_modules', '.git', 'storage', 'var', 'cache', '.cache', '.composer', '.npm', 'tmp', 'mail', 'etc', 'logs', 'ssl', '.cpanel', '.trash'];

    /**
     * Probe one app root.
     *
     * @param  string $appRoot
     * @return array{app_root:string,tree:bool,version:string,configured:bool,db:array,installed:?bool,db_error:?string,ledger:bool,ledger_installed:bool}
     */
    public static function probe($appRoot)
    {
        $appRoot = rtrim((string) $appRoot, '/');
        $out = [
            'app_root' => $appRoot, 'tree' => Tiger_Headless_App::looksExtracted($appRoot),
            'version' => Tiger_Headless_App::version($appRoot), 'configured' => false,
            'db' => ['host' => '', 'port' => 3306, 'name' => ''], 'installed' => null, 'db_error' => null,
            'ledger' => false, 'ledger_installed' => false,
        ];
        $state = new Tiger_Headless_State($appRoot);
        $out['ledger'] = $state->exists();
        $out['ledger_installed'] = $state->installed();

        $ini = $appRoot . '/application/configs/local.ini';
        if (!is_file($ini)) { $out['installed'] = false; return $out; }
        $text = (string) @file_get_contents($ini);
        $v = static function ($k) use ($text) { return Tiger_Headless_Files::iniValue($text, $k); };
        $out['db'] = ['host' => $v('tiger.db.host') ?: 'localhost', 'port' => (int) ($v('tiger.db.port') ?: 3306), 'name' => $v('tiger.db.dbname')];
        $out['configured'] = $out['db']['name'] !== '';
        if (!$out['configured'] || !$out['tree']) { $out['installed'] = false; return $out; }

        // The schema and a founding org are what make a tree a SITE. Ask the database directly.
        try {
            $pdo = new PDO(
                'mysql:host=' . $out['db']['host'] . ';port=' . $out['db']['port'] . ';dbname=' . $out['db']['name'] . ';charset=utf8mb4',
                $v('tiger.db.username'), $v('tiger.db.password'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $has = static function ($table) use ($pdo) {
                $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
                $q->execute([$table]);
                return (int) $q->fetchColumn() > 0;
            };
            $migrated = $has('tiger_migration') && (int) $pdo->query('SELECT COUNT(*) FROM tiger_migration')->fetchColumn() > 0;
            $owned    = $has('org') && (int) $pdo->query('SELECT COUNT(*) FROM org WHERE deleted = 0')->fetchColumn() > 0;
            $out['installed'] = $migrated && $owned;
            $out['migrated']  = $migrated;
            $out['owned']     = $owned;
        } catch (Throwable $e) {
            $out['db_error'] = $e->getMessage();   // installed stays null: we could not tell
        }
        return $out;
    }

    /**
     * Find every Tiger under a directory (a cPanel home, /home, a server) without booting any of
     * them. An app root is a dir holding vendor/webtigers/tiger-core AND application/configs; its
     * docroot is any index.php shim under the same scan root that names it (the above-docroot layout),
     * else its own public/ (the docroot layout).
     *
     * @param  string $root   where to start
     * @param  int    $depth  how deep to look (a cPanel account rarely needs more than 4)
     * @return array<int,array> one probe() per install, plus docroot + layout
     */
    public static function discover($root, $depth = 4)
    {
        $root = rtrim((string) $root, '/');
        $appRoots = []; $shims = [];
        self::_walk($root, (int) $depth, $appRoots, $shims);

        $installs = [];
        foreach (array_unique($appRoots) as $app) {
            $row = self::probe($app);
            $doc = null;
            foreach ($shims as $file => $target) {
                if ($target === $app) { $doc = dirname($file); break; }
            }
            $row['docroot'] = $doc ?: (is_file($app . '/public/index.php') ? $app . '/public' : null);
            $row['layout']  = $doc ? Tiger_Headless_Spec::LAYOUT_ABOVE : ($row['docroot'] ? Tiger_Headless_Spec::LAYOUT_DOCROOT : null);
            $installs[] = $row;
        }
        usort($installs, static function ($a, $b) { return strcmp($a['app_root'], $b['app_root']); });
        return $installs;
    }

    /** The app root an index.php shim points at, or '' if it isn't a Tiger shim. */
    public static function shimTarget($indexPhp)
    {
        $head = (string) @file_get_contents($indexPhp, false, null, 0, 2048);
        if ($head === '' || strpos($head, 'Tiger_Application') === false) { return ''; }
        if (preg_match("/define\\('APPLICATION_ROOT',\\s*'((?:[^'\\\\]|\\\\.)*)'\\)/", $head, $m)) {
            return rtrim(stripslashes($m[1]), '/');
        }
        return '';
    }

    protected static function _walk($dir, $depth, array &$appRoots, array &$shims)
    {
        if ($depth < 0 || !is_dir($dir) || is_link($dir)) { return; }
        if (is_dir($dir . '/vendor/webtigers/tiger-core') && is_dir($dir . '/application/configs')) {
            $appRoots[] = $dir;
            // Its own public/index.php is the bundle's front controller (docroot layout) — note it, don't recurse.
            return;
        }
        $index = $dir . '/index.php';
        if (is_file($index)) {
            $t = self::shimTarget($index);
            if ($t !== '') { $shims[$index] = $t; }
        }
        foreach (@scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || $f[0] === '.' && $f !== '.' || in_array($f, self::SKIP, true)) { continue; }
            $sub = $dir . '/' . $f;
            if (is_dir($sub) && !is_link($sub)) { self::_walk($sub, $depth - 1, $appRoots, $shims); }
        }
    }
}
