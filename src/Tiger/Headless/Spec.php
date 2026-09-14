<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The install spec — what a front-end (the WHM plugin, Softaculous, the web installer, a shell)
 * hands the headless installer. One shape, validated once, defaults applied once, so every caller
 * gets the same install from the same input.
 *
 * See SPEC.md for the documented contract. This class is the authority; SPEC.md describes it.
 */
class Tiger_Headless_Spec
{
    const LAYOUT_ABOVE   = 'above-docroot';
    const LAYOUT_DOCROOT = 'docroot';

    /** @var array the normalized spec */
    protected $_spec;

    /**
     * @param array $spec the raw spec as decoded from JSON
     * @throws Tiger_Headless_SpecException listing every problem at once
     */
    public function __construct(array $spec)
    {
        $errors = [];
        $out    = [];

        // ---- db --------------------------------------------------------------------------------------
        $db = is_array($spec['db'] ?? null) ? $spec['db'] : [];
        $out['db'] = [
            'host'     => self::_str($db, 'host', 'localhost'),
            'port'     => (int) ($db['port'] ?? 3306),
            'name'     => self::_str($db, 'name'),
            'user'     => self::_str($db, 'user'),
            'password' => (string) ($db['password'] ?? ''),
        ];
        foreach (['name', 'user'] as $k) {
            if ($out['db'][$k] === '') { $errors[] = "db.{$k} is required"; }
        }
        if ($out['db']['port'] < 1 || $out['db']['port'] > 65535) { $errors[] = 'db.port must be 1-65535'; }
        if (strpos($out['db']['password'], '"') !== false) { $errors[] = 'db.password may not contain a double quote (")'; }

        // ---- paths + layout ---------------------------------------------------------------------------
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];
        $out['paths'] = [
            'app_root' => rtrim(self::_str($paths, 'app_root'), '/'),
            'docroot'  => rtrim(self::_str($paths, 'docroot'), '/'),
        ];
        foreach (['app_root', 'docroot'] as $k) {
            $v = $out['paths'][$k];
            if ($v === '')          { $errors[] = "paths.{$k} is required"; }
            elseif ($v[0] !== '/')  { $errors[] = "paths.{$k} must be an absolute path"; }
        }
        $layout = self::_str($spec, 'layout', self::LAYOUT_ABOVE);
        if (!in_array($layout, [self::LAYOUT_ABOVE, self::LAYOUT_DOCROOT], true)) {
            $errors[] = 'layout must be "' . self::LAYOUT_ABOVE . '" or "' . self::LAYOUT_DOCROOT . '"';
        }
        $out['layout'] = $layout;
        if (!$errors) {
            $app = $out['paths']['app_root'];
            $doc = $out['paths']['docroot'];
            $inside = static function ($child, $parent) {
                return $child === $parent || strpos($child . '/', $parent . '/') === 0;
            };
            if ($layout === self::LAYOUT_ABOVE) {
                // The security story: the app is NOT web-reachable. A docroot inside the app root
                // (or an app root inside the docroot) is exactly what "above the docroot" forbids.
                if ($inside($doc, $app)) { $errors[] = 'layout "above-docroot" forbids a docroot inside app_root (' . $doc . ' is under ' . $app . '); set layout "docroot" explicitly if that is intended'; }
                if ($inside($app, $doc)) { $errors[] = 'layout "above-docroot" forbids an app_root inside the docroot (' . $app . ' is under ' . $doc . ')'; }
            } elseif ($doc !== $app . '/public') {
                $errors[] = 'layout "docroot" requires paths.docroot to be paths.app_root + "/public" (the bundle\'s own public/ becomes the document root)';
            }
        }

        // ---- site ------------------------------------------------------------------------------------
        $site = is_array($spec['site'] ?? null) ? $spec['site'] : [];
        $out['site'] = [
            'url'  => rtrim(self::_str($site, 'url'), '/'),
            'name' => self::_str($site, 'name', 'Tiger'),
        ];
        if ($out['site']['url'] === '')                                       { $errors[] = 'site.url is required'; }
        elseif (!preg_match('#^https?://[^/\s]+#i', $out['site']['url']))     { $errors[] = 'site.url must start with http:// or https://'; }

        // ---- admin -----------------------------------------------------------------------------------
        $admin = is_array($spec['admin'] ?? null) ? $spec['admin'] : [];
        $out['admin'] = [
            'username' => self::_str($admin, 'username'),
            'email'    => self::_str($admin, 'email'),
            'password' => (string) ($admin['password'] ?? ''),
            'org'      => self::_str($admin, 'org', $out['site']['name']),
        ];
        if ($out['admin']['email'] === '')                                          { $errors[] = 'admin.email is required'; }
        elseif (!filter_var($out['admin']['email'], FILTER_VALIDATE_EMAIL))         { $errors[] = 'admin.email is not a valid email address'; }
        if ($out['admin']['password'] === '')                                       { $errors[] = 'admin.password is required'; }

        // ---- locale / modules / theme ----------------------------------------------------------------
        $locale = self::_str($spec, 'locale', 'en');
        if (!preg_match('/^[a-z]{2,3}$/', $locale)) { $errors[] = 'locale must be a language code like "en" or "es"'; }
        $out['locale'] = $locale;

        $mods = $spec['modules'] ?? [];
        if (!is_array($mods)) { $errors[] = 'modules must be a list of Directory slugs'; $mods = []; }
        $out['modules'] = [];
        foreach ($mods as $m) {
            $m = strtolower(trim((string) $m));
            if ($m === '') { continue; }
            if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $m)) { $errors[] = "modules: \"{$m}\" is not a slug"; continue; }
            $out['modules'][] = $m;
        }
        $out['modules'] = array_values(array_unique($out['modules']));

        $theme = self::_str($spec, 'theme');
        if ($theme !== '' && !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $theme)) { $errors[] = 'theme must be a Directory slug (e.g. "theme-grey-mist")'; }
        $out['theme'] = $theme;

        // ---- skills: Agent Skills to install + activate, each a SKILL.md folder in a public GitHub repo --
        $sk = $spec['skills'] ?? [];
        if (!is_array($sk)) { $errors[] = 'skills must be a list of {repo, path, ref?}'; $sk = []; }
        $out['skills'] = []; $seen = [];
        foreach ($sk as $i => $e) {
            if (!is_array($e)) { $errors[] = "skills[{$i}] must be an object"; continue; }
            $repo = trim((string) ($e['repo'] ?? '')); $path = trim((string) ($e['path'] ?? ''), '/'); $ref = trim((string) ($e['ref'] ?? 'main'));
            if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) { $errors[] = "skills[{$i}].repo must be owner/name"; continue; }
            if ($path === '' || strpos($path, '..') !== false || !preg_match('#^[A-Za-z0-9_./-]+$#', $path)) { $errors[] = "skills[{$i}].path must be a folder path inside the repo"; continue; }
            if (!preg_match('#^[A-Za-z0-9_./-]+$#', $ref)) { $errors[] = "skills[{$i}].ref is not a git ref"; continue; }
            $k = strtolower($repo . '@' . $path);
            if (isset($seen[$k])) { continue; }
            $seen[$k] = true;
            $out['skills'][] = ['repo' => $repo, 'path' => $path, 'ref' => $ref];
        }

        // ---- source ----------------------------------------------------------------------------------
        $src = is_array($spec['source'] ?? null) ? $spec['source'] : [];
        $out['source'] = [
            'version' => self::_str($src, 'version'),
            'bundle'  => self::_str($src, 'bundle'),
            'sha256'  => strtolower(self::_str($src, 'sha256')),
        ];
        if ($out['source']['bundle'] !== '' && $out['source']['bundle'][0] !== '/') { $errors[] = 'source.bundle must be an absolute path to a tiger-<version>.zip'; }
        if ($out['source']['sha256'] !== '' && !preg_match('/^[0-9a-f]{64}$/', $out['source']['sha256'])) { $errors[] = 'source.sha256 must be a 64-hex sha256'; }

        // ---- config: extra local.ini keys (host defaults: mail relay, module posture, …) ---------------
        $cfg = $spec['config'] ?? [];
        if (!is_array($cfg)) { $errors[] = 'config must be an object of local.ini keys'; $cfg = []; }
        $out['config'] = [];
        foreach ($cfg as $k => $v) {
            $k = (string) $k;
            if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/i', $k)) { $errors[] = "config: \"{$k}\" is not a dotted ini key"; continue; }
            if (strpos($k, 'tiger.db.') === 0 || in_array($k, ['tiger.crypto.key', 'tiger.security.pepper'], true)) { $errors[] = "config: \"{$k}\" is owned by the installer and may not be set here"; continue; }
            if (is_array($v) || is_object($v)) { $errors[] = "config: \"{$k}\" must be a scalar"; continue; }
            $v = (string) (is_bool($v) ? (int) $v : $v);
            if (strpos($v, '"') !== false || strpos($v, "\n") !== false) { $errors[] = "config: \"{$k}\" may not contain a double quote or a newline"; continue; }
            $out['config'][$k] = $v;
        }

        // ---- agent (TIGER-90 connect handshake) ------------------------------------------------------
        $out['agent'] = !empty($spec['agent']);

        if ($errors) {
            throw new Tiger_Headless_SpecException($errors);
        }
        $this->_spec = $out;
    }

    /** Parse a JSON document into a validated spec. */
    public static function fromJson($json)
    {
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            throw new Tiger_Headless_SpecException(['spec is not a JSON object' . (json_last_error() ? ' (' . json_last_error_msg() . ')' : '')]);
        }
        return new self($data);
    }

    /** The normalized spec. */
    public function toArray()
    {
        return $this->_spec;
    }

    /** Dotted accessor: $spec->get('db.name'). */
    public function get($path, $default = null)
    {
        $cur = $this->_spec;
        foreach (explode('.', $path) as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) { return $default; }
            $cur = $cur[$k];
        }
        return $cur;
    }

    /**
     * What makes two runs "the same install": the database and the two paths. A resume must match;
     * a different spec against an installed tree is refused rather than silently repointed.
     */
    public function fingerprint()
    {
        return hash('sha256', strtolower($this->_spec['db']['name']) . '|' . $this->_spec['paths']['app_root'] . '|' . $this->_spec['paths']['docroot']);
    }

    /** The spec with secrets blanked — what may be logged or echoed back. */
    public function redacted()
    {
        $s = $this->_spec;
        $s['db']['password']    = $s['db']['password'] === '' ? '' : '***';
        $s['admin']['password'] = '***';
        return $s;
    }

    protected static function _str(array $a, $k, $default = '')
    {
        $v = $a[$k] ?? null;
        if ($v === null) { return $default; }
        $v = trim((string) $v);
        return $v === '' ? $default : $v;
    }
}
