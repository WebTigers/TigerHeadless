<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The machine-readable result — the ONE thing a front-end reads. JSON on stdout, nothing else.
 *
 *   {
 *     "ok": true|false,
 *     "verb": "install",
 *     "version": "1.0.17",            // the Tiger release that was installed / is installed
 *     "layout": "above-docroot",
 *     "already_installed": false,
 *     "steps": [ {"step": "requirements", "status": "ok|skipped|failed", "detail": "...", "ms": 12}, ... ],
 *     "admin_url": "https://example.com/admin",     // on success
 *     "login": {"username": "...", "email": "..."},  // on success
 *     "agent": {...},                                // when the spec asked for the connect handshake
 *     "error": null | {"step": "<name>", "message": "..."}
 *   }
 *
 * Exit codes: 0 ok · 1 a step failed (error.step names it) · 2 the spec or usage was invalid.
 */
class Tiger_Headless_Result
{
    const EXIT_OK      = 0;
    const EXIT_FAILED  = 1;
    const EXIT_USAGE   = 2;

    /** @var array */
    protected $_d;

    public function __construct($verb)
    {
        $this->_d = [
            'ok'                => false,
            'verb'              => (string) $verb,
            'version'           => null,
            'layout'            => null,
            'already_installed' => false,
            'steps'             => [],
            'error'             => null,
        ];
    }

    public function step($name, $status, $detail = '', $seconds = null)
    {
        $row = ['step' => (string) $name, 'status' => (string) $status, 'detail' => (string) $detail];
        if ($seconds !== null) { $row['ms'] = (int) round($seconds * 1000); }
        $this->_d['steps'][] = $row;
        return $this;
    }

    public function set($key, $value)
    {
        $this->_d[$key] = $value;
        return $this;
    }

    public function fail($step, $message)
    {
        $this->_d['ok']    = false;
        $this->_d['error'] = ['step' => (string) $step, 'message' => (string) $message];
        return $this;
    }

    public function succeed()
    {
        $this->_d['ok']    = true;
        $this->_d['error'] = null;
        return $this;
    }

    public function alreadyInstalled($version, $layout)
    {
        $this->_d['ok']                = true;
        $this->_d['already_installed'] = true;
        $this->_d['version']           = $version ?: null;
        $this->_d['layout']            = $layout ?: null;
        $this->_d['error']             = null;
        return $this;
    }

    public function ok()          { return (bool) $this->_d['ok']; }
    public function error()       { return $this->_d['error']; }
    public function exitCode()    { return $this->_d['ok'] ? self::EXIT_OK : self::EXIT_FAILED; }
    public function toArray()     { return $this->_d; }

    public function toJson()
    {
        return json_encode($this->_d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
