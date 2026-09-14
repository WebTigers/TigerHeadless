<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The step engine. Runs named steps in order against a ledger:
 *
 *  - a step recorded `ok` in the ledger is skipped on a re-run unless it is declared `always`
 *    (cheap, must-recheck steps like requirements);
 *  - the first failure stops the run, is recorded, and names itself in the result;
 *  - the ledger's `installed` flag is set only when every step has passed;
 *  - a run against a ledger that is already `installed` does nothing and says so;
 *  - a ledger bound to a DIFFERENT spec is refused, never repointed.
 *
 * Steps are closures so the engine can be tested with fakes; the real steps live in Installer.
 */
class Tiger_Headless_Pipeline
{
    /** @var array<int,array{name:string,fn:callable,always:bool}> */
    protected $_steps = [];
    /** @var Tiger_Headless_State */
    protected $_state;
    /** @var callable|null fn(string $line) progress sink */
    protected $_log;

    public function __construct(Tiger_Headless_State $state, $log = null)
    {
        $this->_state = $state;
        $this->_log   = $log;
    }

    /**
     * @param string   $name   the step name reported in the result
     * @param callable $fn     fn(): string|null — returns a detail string (or null); throws to fail
     * @param bool     $always re-run on every invocation even when the ledger says it passed
     */
    public function add($name, callable $fn, $always = false)
    {
        $this->_steps[] = ['name' => $name, 'fn' => $fn, 'always' => (bool) $always];
        return $this;
    }

    /**
     * @param  string $fingerprint the spec's fingerprint (see Tiger_Headless_Spec::fingerprint)
     * @return Tiger_Headless_Result
     */
    public function run($fingerprint)
    {
        $result = new Tiger_Headless_Result('install');
        $state  = $this->_state;

        if ($state->exists() && $state->fingerprint() !== '' && $state->fingerprint() !== $fingerprint) {
            $result->fail('ledger', 'This app root already carries an install ledger for a different database/paths. '
                . 'Refusing to repoint an existing installation; remove ' . $state->path() . ' only if you know that tree is disposable.');
            return $result;
        }
        if ($state->installed()) {
            $result->alreadyInstalled($state->version(), $state->layout());
            return $result;
        }
        $state->bind($fingerprint);

        foreach ($this->_steps as $step) {
            $name = $step['name'];
            if (!$step['always'] && $state->stepDone($name)) {
                $result->step($name, 'skipped', 'completed on a previous run');
                $this->_log("- {$name}: skipped (done)");
                continue;
            }
            $t0 = microtime(true);
            try {
                $detail = (string) call_user_func($step['fn']);
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $state->markStep($name, 'failed', $msg)->save();
                $result->step($name, 'failed', $msg, microtime(true) - $t0);
                $result->fail($name, $msg);
                $this->_log("x {$name}: {$msg}");
                return $result;
            }
            $state->markStep($name, 'ok', $detail)->save();
            $result->step($name, 'ok', $detail, microtime(true) - $t0);
            $this->_log("+ {$name}" . ($detail !== '' ? ": {$detail}" : ''));
        }

        $state->markInstalled()->save();
        $result->succeed();
        return $result;
    }

    protected function _log($line)
    {
        if ($this->_log) { call_user_func($this->_log, $line); }
    }
}
