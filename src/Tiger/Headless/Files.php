<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * Filesystem helpers the installer needs before Tiger's own library is on disk.
 */
class Tiger_Headless_Files
{
    /** Recursively copy a tree (symlinks re-created as symlinks). */
    public static function rcopy($src, $dst)
    {
        $src = rtrim($src, '/'); $dst = rtrim($dst, '/');
        if (is_link($src)) { @symlink(readlink($src), $dst); return; }
        if (is_dir($src)) {
            if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
                throw new RuntimeException("Could not create directory {$dst}");
            }
            foreach (scandir($src) as $f) {
                if ($f === '.' || $f === '..') { continue; }
                self::rcopy("{$src}/{$f}", "{$dst}/{$f}");
            }
            return;
        }
        if (!@copy($src, $dst)) {
            throw new RuntimeException("Could not copy {$src} to {$dst}");
        }
    }

    /** Recursively delete a tree (never follows symlinks out of it). */
    public static function rrmdir($path)
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) { return; }
        foreach (scandir($path) as $f) {
            if ($f === '.' || $f === '..') { continue; }
            self::rrmdir($path . '/' . $f);
        }
        @rmdir($path);
    }

    /**
     * Write a file atomically (temp + rename) with the given mode, reporting failure instead of
     * swallowing it. A failed write can never leave a half-written config.
     */
    public static function writeAtomic($path, $text, $mode = 0600)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { return false; }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $text) === false) { @unlink($tmp); return false; }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
        return true;
    }

    /**
     * Merge keys into a local.ini, PRESERVING every other line — the file also holds the minted
     * secrets by the second run, and rewriting it wholesale threw them away (a lesson the web
     * installer learned the hard way: a rotated pepper locks the founding admin out).
     *
     * @return string the full file text to write
     */
    public static function iniMerge($existingText, array $kv)
    {
        $text = (string) $existingText;
        if (trim($text) === '') { $text = "[production]\n"; }
        foreach ($kv as $key => $val) {
            $line = $key . ' = "' . $val . '"';
            $pat  = '/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=.*$/m';
            if (preg_match($pat, $text)) {
                $text = preg_replace($pat, $line, $text, 1);
                continue;
            }
            if (preg_match('/^\[production\][ \t]*\r?\n/m', $text, $m, PREG_OFFSET_CAPTURE)) {
                $at   = $m[0][1] + strlen($m[0][0]);
                $text = substr($text, 0, $at) . $line . "\n" . substr($text, $at);
            } else {
                $text = rtrim($text, "\n") . "\n" . $line . "\n";
            }
        }
        return $text;
    }

    /** The value of one key in an ini text, or '' when absent. */
    public static function iniValue($text, $key)
    {
        if (preg_match('/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=[ \t]*"?([^"\r\n]*)"?/m', (string) $text, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /** Whether $child is $parent or lies under it. */
    public static function under($child, $parent)
    {
        $child = rtrim($child, '/'); $parent = rtrim($parent, '/');
        return $child === $parent || strpos($child . '/', $parent . '/') === 0;
    }
}
