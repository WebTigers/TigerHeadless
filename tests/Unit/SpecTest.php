<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 */
use PHPUnit\Framework\TestCase;

final class SpecTest extends TestCase
{
    private function valid(array $over = []): array
    {
        return array_replace_recursive([
            'db'    => ['name' => 'tiger', 'user' => 'tiger', 'password' => 'pw'],
            'paths' => ['app_root' => '/home/u/tiger-app', 'docroot' => '/home/u/public_html'],
            'site'  => ['url' => 'https://example.com/'],
            'admin' => ['email' => 'a@example.com', 'password' => 'Correct-Horse-9'],
        ], $over);
    }

    public function testDefaultsAreApplied(): void
    {
        $s = (new Tiger_Headless_Spec($this->valid()))->toArray();
        $this->assertSame('localhost', $s['db']['host']);
        $this->assertSame(3306, $s['db']['port']);
        $this->assertSame(Tiger_Headless_Spec::LAYOUT_ABOVE, $s['layout']);
        $this->assertSame('Tiger', $s['site']['name']);
        $this->assertSame('Tiger', $s['admin']['org'], 'org defaults to the site name');
        $this->assertSame('en', $s['locale']);
        $this->assertSame([], $s['modules']);
        $this->assertSame('', $s['theme']);
        $this->assertSame('https://example.com', $s['site']['url'], 'trailing slash trimmed');
        $this->assertFalse($s['agent']);
    }

    public function testEveryMissingRequiredFieldIsReportedAtOnce(): void
    {
        try {
            new Tiger_Headless_Spec(['db' => []]);
            $this->fail('expected SpecException');
        } catch (Tiger_Headless_SpecException $e) {
            $p = implode("\n", $e->problems());
            foreach (['db.name', 'db.user', 'paths.app_root', 'paths.docroot', 'site.url', 'admin.email', 'admin.password'] as $f) {
                $this->assertStringContainsString($f, $p);
            }
        }
    }

    public function testAboveDocrootForbidsDocrootInsideAppRoot(): void
    {
        $this->expectException(Tiger_Headless_SpecException::class);
        $this->expectExceptionMessage('forbids a docroot inside app_root');
        new Tiger_Headless_Spec($this->valid(['paths' => ['docroot' => '/home/u/tiger-app/public']]));
    }

    public function testAboveDocrootForbidsAppRootInsideDocroot(): void
    {
        $this->expectException(Tiger_Headless_SpecException::class);
        $this->expectExceptionMessage('forbids an app_root inside the docroot');
        new Tiger_Headless_Spec($this->valid(['paths' => ['app_root' => '/home/u/public_html/app']]));
    }

    public function testPrefixSharingIsNotContainment(): void
    {
        // /home/u/tiger-app vs /home/u/tiger-app2 share a prefix but neither contains the other.
        $s = new Tiger_Headless_Spec($this->valid(['paths' => ['app_root' => '/home/u/tiger-app', 'docroot' => '/home/u/tiger-app2']]));
        $this->assertSame('/home/u/tiger-app2', $s->get('paths.docroot'));
    }

    public function testDocrootLayoutMustBeExplicitAndPointAtPublic(): void
    {
        $ok = new Tiger_Headless_Spec($this->valid(['layout' => 'docroot', 'paths' => ['docroot' => '/home/u/tiger-app/public']]));
        $this->assertSame('docroot', $ok->get('layout'));

        $this->expectException(Tiger_Headless_SpecException::class);
        $this->expectExceptionMessage('requires paths.docroot to be paths.app_root + "/public"');
        new Tiger_Headless_Spec($this->valid(['layout' => 'docroot']));
    }

    public function testRejectsBadValues(): void
    {
        $cases = [
            'relative app_root'  => ['paths' => ['app_root' => 'tiger-app']],
            'bad url'            => ['site' => ['url' => 'example.com']],
            'bad email'          => ['admin' => ['email' => 'nope']],
            'quote in db pw'     => ['db' => ['password' => 'a"b']],
            'bad port'           => ['db' => ['port' => 70000]],
            'bad locale'         => ['locale' => 'en_US'],
            'bad module slug'    => ['modules' => ['Docs!']],
            'bad theme slug'     => ['theme' => 'Grey Mist'],
            'relative bundle'    => ['source' => ['bundle' => 'tiger.zip']],
            'short sha'          => ['source' => ['sha256' => 'abc']],
            'unknown layout'     => ['layout' => 'sideways'],
        ];
        foreach ($cases as $label => $over) {
            try {
                new Tiger_Headless_Spec($this->valid($over));
                $this->fail("{$label} should be rejected");
            } catch (Tiger_Headless_SpecException $e) {
                $this->assertNotEmpty($e->problems(), $label);
            }
        }
    }

    public function testModulesAreNormalizedAndDeduplicated(): void
    {
        $s = new Tiger_Headless_Spec($this->valid(['modules' => [' Docs ', 'docs', '', 'tigershield']]));
        $this->assertSame(['docs', 'tigershield'], $s->get('modules'));
    }

    public function testFingerprintDependsOnlyOnDatabaseAndPaths(): void
    {
        $a = new Tiger_Headless_Spec($this->valid());
        $b = new Tiger_Headless_Spec($this->valid(['admin' => ['email' => 'other@example.com'], 'modules' => ['docs']]));
        $c = new Tiger_Headless_Spec($this->valid(['db' => ['name' => 'other']]));
        $this->assertSame($a->fingerprint(), $b->fingerprint());
        $this->assertNotSame($a->fingerprint(), $c->fingerprint());
    }

    public function testRedactedHidesSecrets(): void
    {
        $r = (new Tiger_Headless_Spec($this->valid()))->redacted();
        $this->assertSame('***', $r['db']['password']);
        $this->assertSame('***', $r['admin']['password']);
    }

    public function testConfigMapAcceptsHostDefaultsAndRefusesInstallerOwnedKeys(): void
    {
        $s = new Tiger_Headless_Spec($this->valid(['config' => ['mail.transport' => 'smtp', 'mail.smtp.port' => 587, 'tiger.shield.mode' => true]]));
        $this->assertSame(['mail.transport' => 'smtp', 'mail.smtp.port' => '587', 'tiger.shield.mode' => '1'], $s->get('config'));
        foreach (['tiger.db.host' => 'x', 'tiger.crypto.key' => 'x', 'tiger.security.pepper' => 'x', 'notdotted' => 'x', 'a.b' => 'has"quote', 'a.c' => ['arr']] as $k => $v) {
            try {
                new Tiger_Headless_Spec($this->valid(['config' => [$k => $v]]));
                $this->fail("config {$k} should be refused");
            } catch (Tiger_Headless_SpecException $e) {
                $this->assertStringContainsString($k, implode(' ', $e->problems()));
            }
        }
    }

    public function testSkillsAreValidatedAndDeduplicated(): void
    {
        $s = new Tiger_Headless_Spec($this->valid(['skills' => [
            ['repo' => 'WebTigers/Skills', 'path' => 'skills/tiger-design'],
            ['repo' => 'webtigers/skills', 'path' => '/skills/tiger-design/'],            // same skill, different case/slashes
            ['repo' => 'ComposioHQ/awesome-claude-skills', 'path' => 'content-research-writer', 'ref' => 'master'],
        ]]));
        $this->assertSame([
            ['repo' => 'WebTigers/Skills', 'path' => 'skills/tiger-design', 'ref' => 'main'],
            ['repo' => 'ComposioHQ/awesome-claude-skills', 'path' => 'content-research-writer', 'ref' => 'master'],
        ], $s->get('skills'));
        foreach ([[['repo' => 'nope', 'path' => 'x']], [['repo' => 'a/b', 'path' => '../etc']], [['repo' => 'a/b', 'path' => 'x', 'ref' => 'bad ref']], ['notalist']] as $bad) {
            try { new Tiger_Headless_Spec($this->valid(['skills' => $bad])); $this->fail('should be refused'); }
            catch (Tiger_Headless_SpecException $e) { $this->assertStringContainsString('skills', implode(' ', $e->problems())); }
        }
    }

    public function testFromJsonRejectsNonObjects(): void
    {
        $this->expectException(Tiger_Headless_SpecException::class);
        Tiger_Headless_Spec::fromJson('"just a string"');
    }
}
