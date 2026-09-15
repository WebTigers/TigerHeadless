<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The install ledger: <app_root>/var/headless/state.json. Which steps completed, for which spec,
 * and whether the install finished. It is what makes a re-run resume from the failed step and a
 * re-run after success a no-op — and what stops a half-installed tree from ever looking installed
 * (the `installed` flag is set by the LAST step and by nothing else).
 */
class Tiger_Headless_State
{
    const FILE = 'var/headless/state.json';

    /** @var string */
    protected $_path;
    /** @var array */
    protected $_data;

    public function __construct($appRoot)
    {
        $this->_path = rtrim($appRoot, '/') . '/' . self::FILE;
        $this->_data = ['fingerprint' => '', 'installed' => false, 'version' => '', 'layout' => '', 'steps' => []];
        if (is_file($this->_path)) {
            $d = json_decode((string) @file_get_contents($this->_path), true);
            if (is_array($d)) { $this->_data = array_replace($this->_data, $d); }
        }
    }

    public function path()                 { return $this->_path; }
    public function exists()               { return is_file($this->_path); }
    public function fingerprint()          { return (string) $this->_data['fingerprint']; }
    public function installed()            { return (bool) $this->_data['installed']; }
    public function version()              { return (string) $this->_data['version']; }
    public function layout()               { return (string) $this->_data['layout']; }
    public function stepDone($name)        { return (($this->_data['steps'][$name]['status'] ?? '') === 'ok'); }
    public function steps()                { return $this->_data['steps']; }

    public function bind($fingerprint)
    {
        $this->_data['fingerprint'] = (string) $fingerprint;
        return $this;
    }

    public function setVersion($v)         { $this->_data['version'] = (string) $v; return $this; }
    public function setLayout($l)          { $this->_data['layout']  = (string) $l; return $this; }

    public function markStep($name, $status, $detail = '', $seconds = null)
    {
        $this->_data['steps'][$name] = ['status' => $status, 'detail' => (string) $detail, 'at' => gmdate('c')]
            + ($seconds !== null ? ['seconds' => round((float) $seconds, 2)] : []);
        return $this;
    }

    public function markInstalled()
    {
        $this->_data['installed'] = true;
        $this->_data['installed_at'] = gmdate('c');
        return $this;
    }

    /** Persist. Best-effort before the app root exists (the first steps create it). */
    public function save()
    {
        $dir = dirname($this->_path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { return false; }
        return Tiger_Headless_Files::writeAtomic($this->_path, json_encode($this->_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
    }

    public function toArray()
    {
        return $this->_data;
    }
}
