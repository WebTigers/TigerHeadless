<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The non-install verbs a packaging front-end needs — thin, JSON-reporting wrappers over the
 * authorities Tiger already has (Tiger_Update_Core, Tiger_Backup). Each takes an app root and
 * returns a Result; nothing here knows how to update or back up a site on its own.
 */
class Tiger_Headless_Verbs
{
    /** What is installed here, without touching the network. */
    public static function status($appRoot)
    {
        $r = new Tiger_Headless_Result('status');
        $appRoot = rtrim((string) $appRoot, '/');
        if (!Tiger_Headless_App::looksExtracted($appRoot)) {
            return $r->fail('status', "No Tiger app at {$appRoot}.");
        }
        $state = new Tiger_Headless_State($appRoot);
        $r->set('version', Tiger_Headless_App::version($appRoot));
        $r->set('layout', $state->layout() ?: null);
        $r->set('installed', $state->installed());
        $r->set('app_root', $appRoot);
        $ini = $appRoot . '/application/configs/local.ini';
        $r->set('configured', is_file($ini) && Tiger_Headless_Files::iniValue((string) file_get_contents($ini), 'tiger.db.dbname') !== '');
        try {
            Tiger_Headless_App::boot($appRoot);
            $db = Zend_Db_Table_Abstract::getDefaultAdapter();
            $db->fetchOne('SELECT 1');
            $r->set('database', 'ok');
            if (class_exists('Tiger_Db_Migrator')) {
                $m = new Tiger_Db_Migrator($db, Tiger_Module_Installer::migrationPaths());
                $r->set('pending_migrations', count(array_filter($m->status(), static function ($row) { return empty($row['applied']); })));
            }
        } catch (Throwable $e) {
            $r->set('database', 'error: ' . $e->getMessage());
        }
        return $r->succeed();
    }

    /**
     * Move tiger-core to a release (default: latest) using the same swap the admin "Update core"
     * button runs — backup, verify, swap vendor/, migrate, republish.
     */
    public static function upgrade($appRoot, $version = '')
    {
        $r = new Tiger_Headless_Result('upgrade');
        $appRoot = rtrim((string) $appRoot, '/');
        try {
            Tiger_Headless_App::boot($appRoot);
            $from = Tiger_Headless_App::version($appRoot);
            $target = $version !== '' ? $version : (string) Tiger_Module_Github::latestRef('WebTigers', 'tiger-core');
            if ($target === '') { return $r->fail('resolve', 'Could not determine the latest tiger-core release.'); }
            $rel  = Tiger_Update_Core::resolveRelease($target);
            if (!$rel) { return $r->fail('resolve', 'No release' . ($version !== '' ? " {$version}" : '') . ' with a vendored bundle was found.'); }
            $r->step('resolve', 'ok', ($rel['version'] ?? '?') . ' (from ' . $from . ')');
            if ($from !== '' && ltrim((string) $rel['version'], 'v') === ltrim($from, 'v')) {
                $r->set('version', $from)->set('already_current', true);
                return $r->succeed();
            }
            $out = Tiger_Update_Core::update(['url' => $rel['url'], 'sha256' => $rel['sha256'], 'version' => $rel['version'], 'migrate' => true]);
            foreach (($out['log'] ?? []) as $row) {
                $r->step((string) $row['step'], !empty($row['ok']) ? 'ok' : 'failed', (string) ($row['detail'] ?? ''));
            }
            if (empty($out['ok'])) {
                $last = end($out['log']);
                return $r->fail((string) ($last['step'] ?? 'update'), (string) ($last['detail'] ?? 'update failed'));
            }
            $r->set('version', (string) ($out['version'] ?? $rel['version']));
            $state = new Tiger_Headless_State($appRoot);
            if ($state->exists()) { $state->setVersion((string) ($out['version'] ?? $rel['version']))->save(); }
            return $r->succeed();
        } catch (Throwable $e) {
            return $r->fail('upgrade', $e->getMessage());
        }
    }

    /** A local backup archive of the given components (default: all). */
    public static function backup($appRoot, array $components = [])
    {
        $r = new Tiger_Headless_Result('backup');
        try {
            Tiger_Headless_App::boot(rtrim((string) $appRoot, '/'));
            $components = $components ?: Tiger_Backup::COMPONENTS;
            $out = Tiger_Backup::create($components, ['disk' => 'local', 'source' => 'manual', 'notify' => false]);
            if (($out['status'] ?? '') !== 'ok') { return $r->fail('backup', (string) ($out['error'] ?? 'backup failed')); }
            $r->step('backup', 'ok', (string) ($out['filename'] ?? ''));
            $r->set('backup_id', $out['backup_id'] ?? null)->set('filename', $out['filename'] ?? null)->set('size', $out['size'] ?? null);
            $r->set('path', isset($out['filename']) ? rtrim((string) $appRoot, '/') . '/storage/backups/' . $out['filename'] : null);
            return $r->succeed();
        } catch (Throwable $e) {
            return $r->fail('backup', $e->getMessage());
        }
    }

    /** Restore from a TigerBackup archive (destructive; Tiger takes its own safety backup first). */
    public static function restore($appRoot, $zipPath, array $components = [])
    {
        $r = new Tiger_Headless_Result('restore');
        try {
            Tiger_Headless_App::boot(rtrim((string) $appRoot, '/'));
            $out = Tiger_Backup::restore((string) $zipPath, $components, ['safety' => true]);
            if (($out['status'] ?? '') !== 'ok') { return $r->fail('restore', (string) ($out['error'] ?? 'restore failed')); }
            $r->step('restore', 'ok', implode(', ', (array) ($out['restored'] ?? [])));
            $r->set('restored', $out['restored'] ?? [])->set('safety_id', $out['safety_id'] ?? null);
            return $r->succeed();
        } catch (Throwable $e) {
            return $r->fail('restore', $e->getMessage());
        }
    }
}
