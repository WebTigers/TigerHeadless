<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * Discovery over fake trees — no database, no boot. What a hosting panel's fleet view relies on:
 * every Tiger under a directory is found, its docroot mapped from the shim, its layout named, and
 * nothing inside an app root (or vendor/, or a symlink) is mistaken for a second install.
 */
use PHPUnit\Framework\TestCase;

final class DetectTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tiger-headless-detect-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        Tiger_Headless_Files::rrmdir($this->root);
    }

    /** A minimal "extracted Tiger" tree. */
    private function tigerTree(string $app, string $version, ?string $dbname = 'site_db'): void
    {
        mkdir($app . '/vendor/webtigers/tiger-core/library/Tiger', 0755, true);
        mkdir($app . '/application/configs', 0755, true);
        mkdir($app . '/public', 0755, true);
        file_put_contents($app . '/vendor/autoload.php', '<?php');
        file_put_contents($app . '/vendor/webtigers/tiger-core/library/Tiger/Version.php', "<?php class Tiger_Version { const VERSION = '{$version}'; }");
        file_put_contents($app . '/public/index.php', "<?php\n(new Tiger_Application(dirname(__DIR__)))->run();\n");
        if ($dbname !== null) {
            file_put_contents($app . '/application/configs/local.ini', "[production]\ntiger.db.host = \"127.0.0.1\"\ntiger.db.port = \"1\"\ntiger.db.dbname = \"{$dbname}\"\ntiger.db.username = \"u\"\ntiger.db.password = \"p\"\n");
        }
    }

    private function shim(string $docroot, string $app): void
    {
        @mkdir($docroot, 0755, true);
        file_put_contents($docroot . '/index.php', "<?php\n// shim\ndefine('APPLICATION_ROOT', " . var_export($app, true) . ");\nrequire APPLICATION_ROOT . '/vendor/autoload.php';\n(new Tiger_Application(APPLICATION_ROOT))->run();\n");
    }

    public function testFindsAboveDocrootAndDocrootLayoutsAndMapsShims(): void
    {
        $this->tigerTree($this->root . '/user1/tiger-app', '1.7.0');
        $this->shim($this->root . '/user1/public_html', $this->root . '/user1/tiger-app');
        $this->tigerTree($this->root . '/user2/site', '1.6.4');                     // docroot layout: no shim anywhere
        mkdir($this->root . '/user3/public_html', 0755, true);                       // an ordinary account, no Tiger
        file_put_contents($this->root . '/user3/public_html/index.php', '<?php echo "wordpress";');

        $found = Tiger_Headless_Detect::discover($this->root, 4);
        $this->assertCount(2, $found);
        $byApp = array_column($found, null, 'app_root');

        $a = $byApp[$this->root . '/user1/tiger-app'];
        $this->assertSame('1.7.0', $a['version']);
        $this->assertSame($this->root . '/user1/public_html', $a['docroot']);
        $this->assertSame('above-docroot', $a['layout']);
        $this->assertTrue($a['configured']);
        $this->assertSame('site_db', $a['db']['name']);
        $this->assertNull($a['installed'], 'DB unreachable → unknown, never a false "not installed"');
        $this->assertNotNull($a['db_error']);

        $b = $byApp[$this->root . '/user2/site'];
        $this->assertSame($this->root . '/user2/site/public', $b['docroot']);
        $this->assertSame('docroot', $b['layout']);
    }

    public function testDoesNotDescendIntoAnAppRootOrVendorOrSymlinks(): void
    {
        $app = $this->root . '/u/tiger-app';
        $this->tigerTree($app, '1.7.0');
        // A second Tiger-looking tree buried INSIDE the first (e.g. a module's fixtures) must not count.
        $this->tigerTree($app . '/application/modules/x/fixtures/tiger-app', '0.0.1');
        // A Tiger under vendor/ of something else must not count either.
        $this->tigerTree($this->root . '/other/vendor/pkg/tiger-app', '0.0.2');
        // A symlink loop must not hang the walk.
        @symlink($this->root, $this->root . '/u/loop');

        $found = Tiger_Headless_Detect::discover($this->root, 6);
        $this->assertSame([$app], array_column($found, 'app_root'));
    }

    public function testDepthLimitIsHonoured(): void
    {
        $this->tigerTree($this->root . '/a/b/c/d/tiger-app', '1.7.0');
        $this->assertCount(0, Tiger_Headless_Detect::discover($this->root, 3));
        $this->assertCount(1, Tiger_Headless_Detect::discover($this->root, 5));
    }

    public function testProbeOnAnUnconfiguredOrEmptyTree(): void
    {
        $this->tigerTree($this->root . '/bare', '1.7.0', null);
        $p = Tiger_Headless_Detect::probe($this->root . '/bare');
        $this->assertTrue($p['tree']);
        $this->assertFalse($p['configured']);
        $this->assertFalse($p['installed'], 'no local.ini → definitely not a site');

        $q = Tiger_Headless_Detect::probe($this->root . '/nothing-here');
        $this->assertFalse($q['tree']);
        $this->assertFalse($q['installed']);
    }

    public function testShimTargetParsing(): void
    {
        $f = $this->root . '/index.php';
        $this->shim($this->root, "/home/o'neil/tiger-app");
        $this->assertSame("/home/o'neil/tiger-app", Tiger_Headless_Detect::shimTarget($f));
        file_put_contents($f, '<?php echo 1;');
        $this->assertSame('', Tiger_Headless_Detect::shimTarget($f));
    }
}
