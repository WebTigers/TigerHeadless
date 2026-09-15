<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The engine's promises, proven with fake steps: resume from the failed step, no-op after success,
 * never look installed half-way, never repoint a different spec.
 */
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tiger-headless-pipe-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        Tiger_Headless_Files::rrmdir($this->root);
    }

    private function pipeline(array &$calls, array $failAt = []): Tiger_Headless_Pipeline
    {
        $p = new Tiger_Headless_Pipeline(new Tiger_Headless_State($this->root));
        foreach (['a', 'b', 'c'] as $name) {
            $p->add($name, function () use (&$calls, $name, $failAt) {
                $calls[] = $name;
                if (in_array($name, $failAt, true)) { throw new RuntimeException("{$name} exploded"); }
                return "did {$name}";
            });
        }
        return $p;
    }

    public function testAllStepsRunInOrderAndTheLedgerEndsInstalled(): void
    {
        $calls = [];
        $r = $this->pipeline($calls)->run('fp1');
        $this->assertTrue($r->ok());
        $this->assertSame(['a', 'b', 'c'], $calls);
        $this->assertSame(0, $r->exitCode());
        $this->assertNull($r->error());
        $this->assertSame(['ok', 'ok', 'ok'], array_column($r->toArray()['steps'], 'status'));
        $this->assertTrue((new Tiger_Headless_State($this->root))->installed());
    }

    public function testFirstFailureStopsTheRunNamesItselfAndIsNotInstalled(): void
    {
        $calls = [];
        $r = $this->pipeline($calls, ['b'])->run('fp1');
        $this->assertFalse($r->ok());
        $this->assertSame(['a', 'b'], $calls, 'c never ran');
        $this->assertSame(1, $r->exitCode());
        $this->assertSame('b', $r->error()['step']);
        $this->assertSame('b exploded', $r->error()['message']);
        $state = new Tiger_Headless_State($this->root);
        $this->assertFalse($state->installed(), 'a half-run must never look installed');
        $this->assertTrue($state->stepDone('a'));
        $this->assertFalse($state->stepDone('b'));
    }

    public function testRerunAfterFailureResumesFromTheFailedStep(): void
    {
        $calls = [];
        $this->pipeline($calls, ['b'])->run('fp1');
        $calls = [];
        $r = $this->pipeline($calls)->run('fp1');
        $this->assertTrue($r->ok());
        $this->assertSame(['b', 'c'], $calls, 'a was skipped, b retried, c ran');
        $steps = array_column($r->toArray()['steps'], 'status', 'step');
        $this->assertSame('skipped', $steps['a']);
        $this->assertSame('ok', $steps['b']);
    }

    public function testAlwaysStepsRerunEvenWhenRecordedOk(): void
    {
        $calls = [];
        $p = new Tiger_Headless_Pipeline(new Tiger_Headless_State($this->root));
        $p->add('check', function () use (&$calls) { $calls[] = 'check'; return ''; }, true)
          ->add('work',  function () use (&$calls) { $calls[] = 'work'; throw new RuntimeException('nope'); });
        $p->run('fp1');
        $calls = [];
        $p2 = new Tiger_Headless_Pipeline(new Tiger_Headless_State($this->root));
        $p2->add('check', function () use (&$calls) { $calls[] = 'check'; return ''; }, true)
           ->add('work',  function () use (&$calls) { $calls[] = 'work'; return ''; });
        $this->assertTrue($p2->run('fp1')->ok());
        $this->assertSame(['check', 'work'], $calls);
    }

    public function testRerunAfterSuccessIsANoOpThatSaysSo(): void
    {
        $calls = [];
        $this->pipeline($calls)->run('fp1');
        $calls = [];
        $r = $this->pipeline($calls)->run('fp1');
        $this->assertTrue($r->ok());
        $this->assertTrue($r->toArray()['already_installed']);
        $this->assertSame([], $calls, 'nothing ran');
        $this->assertSame([], $r->toArray()['steps']);
    }

    public function testADifferentSpecAgainstAnExistingLedgerIsRefused(): void
    {
        $calls = [];
        $this->pipeline($calls, ['c'])->run('fp1');
        $calls = [];
        $r = $this->pipeline($calls)->run('fp2');
        $this->assertFalse($r->ok());
        $this->assertSame('ledger', $r->error()['step']);
        $this->assertSame([], $calls, 'no step ran against the wrong tree');
    }

    public function testStepDetailAndTimingAreReported(): void
    {
        $calls = [];
        $r = $this->pipeline($calls)->run('fp1');
        $first = $r->toArray()['steps'][0];
        $this->assertSame('did a', $first['detail']);
        $this->assertArrayHasKey('ms', $first);
    }

    public function testResultJsonShape(): void
    {
        $calls = [];
        $j = json_decode($this->pipeline($calls)->run('fp1')->toJson(), true);
        foreach (['ok', 'verb', 'version', 'layout', 'already_installed', 'steps', 'error'] as $k) {
            $this->assertArrayHasKey($k, $j);
        }
        $this->assertSame('install', $j['verb']);
    }

    /** A front-end keeping web requests short runs a few steps per call; the ledger carries the rest. */
    public function testUntilStopsAfterTheNamedStepAndTheNextCallContinues(): void
    {
        $calls = [];
        $r = $this->pipeline($calls)->run('fp1', 'a');
        $this->assertTrue($r->ok());
        $this->assertSame(['a'], $calls);
        $this->assertFalse($r->toArray()['complete']);
        $this->assertSame('b', $r->toArray()['next_step']);
        $this->assertFalse((new Tiger_Headless_State($this->root))->installed(), 'not installed after a partial run');
        $this->assertArrayHasKey('seconds', (new Tiger_Headless_State($this->root))->steps()['a'], 'the ledger keeps the step time');

        $r = $this->pipeline($calls)->run('fp1', 'b');
        $this->assertSame(['a', 'b'], $calls, 'a was skipped as done, b ran');
        $this->assertSame('c', $r->toArray()['next_step']);
        $this->assertSame('skipped', array_column($r->toArray()['steps'], 'status', 'step')['a']);

        $r = $this->pipeline($calls)->run('fp1', 'c');
        $this->assertTrue($r->toArray()['complete'], 'until = the last step completes the install');
        $this->assertArrayNotHasKey('next_step', $r->toArray());
        $this->assertTrue((new Tiger_Headless_State($this->root))->installed());

    }

    public function testAnUnknownUntilIsRefusedBeforeAnythingRuns(): void
    {
        $calls = [];
        $r = $this->pipeline($calls)->run('fp1', 'nope');
        $this->assertFalse($r->ok());
        $this->assertSame('until', $r->error()['step']);
        $this->assertSame([], $calls);
    }

    /** The host list is what the requirements step enforces — same rows, each with its fix. */
    public function testHostRequirementsListsEveryRowWithAFix(): void
    {
        $rows = Tiger_Headless_Installer::hostRequirements($this->root . '/site/tiger-app', $this->root . '/public_html');
        $keys = array_column($rows, 'key');
        foreach (['php', 'pdo_mysql', 'zip', 'http', 'app_root', 'docroot', 'symlink'] as $k) { $this->assertContains($k, $keys); }
        foreach ($rows as $r) { $this->assertNotSame('', $r['fix'], $r['key'] . ' names its fix'); $this->assertIsBool($r['required']); }
        $this->assertTrue(array_column($rows, 'ok', 'key')['app_root'], 'a creatable path under a writable root passes');
        $this->assertFalse(array_column($rows, 'required', 'key')['symlink'], 'symlink is advisory');
        $bad = Tiger_Headless_Installer::hostRequirements('/proc/nope/tiger-app', $this->root . '/public_html');
        $this->assertFalse(array_column($bad, 'ok', 'key')['app_root']);
        $this->assertStringContainsString('app_root', array_column($bad, 'detail', 'key')['app_root']);
        $this->assertArrayNotHasKey('docroot', array_column(Tiger_Headless_Installer::hostRequirements($this->root . '/a', $this->root . '/a/public', 'docroot'), 'ok', 'key'), 'docroot layout has no separate docroot row');
    }
}
