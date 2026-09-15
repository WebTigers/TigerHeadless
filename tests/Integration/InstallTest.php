<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The acceptance list, run for real: a full install from a bundle into a temp home, through the CLI
 * as a subprocess (one Tiger boot per process, exactly as a front-end would call it).
 *
 * Needs:
 *   TIGER_HEADLESS_BUNDLE   path to a tiger-<version>.zip (the CI workflow downloads the latest release)
 *   TIGER_HEADLESS_DB_*     a database the test may DROP EVERY TABLE IN (host, port, name, user, pass)
 *   TIGER_HEADLESS_SYMLINK  "off" runs the CLI with symlink() disabled (the copy-fallback case)
 *   TIGER_HEADLESS_NETWORK  "1" also installs Directory modules + a theme (needs GitHub)
 *
 * Every "it fails honestly" case is a deliberate breakage checked for the NAMED step and exit code —
 * a green happy path alone proves nothing about the error contract.
 */
use PHPUnit\Framework\TestCase;

final class InstallTest extends TestCase
{
    private static string $bundle;
    private static array  $db;
    private static string $home;
    private static bool   $symlinkOff;

    public static function setUpBeforeClass(): void
    {
        self::$bundle = (string) getenv('TIGER_HEADLESS_BUNDLE');
        if (self::$bundle === '' || !is_file(self::$bundle)) {
            self::markTestSkipped('TIGER_HEADLESS_BUNDLE is not set to a tiger-<version>.zip');
        }
        self::$db = [
            'host' => getenv('TIGER_HEADLESS_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('TIGER_HEADLESS_DB_PORT') ?: 3306),
            'name' => getenv('TIGER_HEADLESS_DB_NAME') ?: 'tiger_headless_test',
            'user' => getenv('TIGER_HEADLESS_DB_USER') ?: 'root',
            'password' => (string) getenv('TIGER_HEADLESS_DB_PASS'),
        ];
        self::$symlinkOff = getenv('TIGER_HEADLESS_SYMLINK') === 'off';
        try { self::pdo(); } catch (Throwable $e) { self::markTestSkipped('database not reachable: ' . $e->getMessage()); }
        self::$home = sys_get_temp_dir() . '/tiger-headless-it-' . bin2hex(random_bytes(4));
        mkdir(self::$home, 0755, true);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$home)) { Tiger_Headless_Files::rrmdir(self::$home); }
    }

    // ------------------------------------------------------------------------------------ helpers

    private static function pdo(): PDO
    {
        $d = self::$db;
        return new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4", $d['user'], $d['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    }

    /** The test owns this database: empty it between installs. */
    private static function resetDb(): void
    {
        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec("DROP TABLE `{$t}`"); }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function spec(string $site, array $over = []): array
    {
        // The cPanel convention (CPANEL.md §7a): /home/<user>/<domain>/tiger-app — and <domain>/ does
        // NOT exist before the first install, so the installer must create more than one level.
        $home = self::$home . '/' . $site;
        @mkdir($home . '/public_html', 0755, true);
        return array_replace_recursive([
            'db'     => self::$db,
            'paths'  => ['app_root' => $home . '/' . $site . '.test/tiger-app', 'docroot' => $home . '/public_html'],
            'site'   => ['url' => "https://{$site}.test", 'name' => ucfirst($site)],
            'admin'  => ['username' => 'owner', 'email' => "owner@{$site}.test", 'password' => 'Correct-Horse-Battery-9'],
            'source' => ['bundle' => self::$bundle, 'sha256' => hash_file('sha256', self::$bundle)],
        ], $over);
    }

    /** Run the CLI; return [exit, decoded json, raw stdout]. */
    private function cli(array $spec, string $until = ''): array
    {
        $file = tempnam(sys_get_temp_dir(), 'spec');
        file_put_contents($file, json_encode($spec));
        $php = PHP_BINARY . (self::$symlinkOff ? ' -d disable_functions=symlink' : '');
        $cmd = $php . ' ' . escapeshellarg(__DIR__ . '/../../bin/tiger-headless') . ' install --spec=' . escapeshellarg($file) . ($until !== '' ? ' --until=' . escapeshellarg($until) : '') . ' 2>' . escapeshellarg($file . '.err');
        exec($cmd, $lines, $exit);
        $raw = implode("\n", $lines);
        @unlink($file); @unlink($file . '.err');
        $json = json_decode($raw, true);
        $this->assertIsArray($json, "stdout must be JSON and nothing else; got:\n{$raw}");
        return [$exit, $json, $raw];
    }

    private function serve(string $docroot, callable $fn): void
    {
        $port = 8900 + random_int(0, 99);
        $desc = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $proc = proc_open(PHP_BINARY . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docroot), $desc, $pipes, $docroot);
        try {
            for ($i = 0; $i < 30; $i++) { if (@fsockopen('127.0.0.1', $port, $e, $s, 0.2)) { break; } usleep(100000); }
            $fn('http://127.0.0.1:' . $port);
        } finally {
            proc_terminate($proc); proc_close($proc);
        }
    }

    private function http(string $url): array
    {
        $ctx  = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        $body = (string) @file_get_contents($url, false, $ctx);
        $code = 0; $loc = '';
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int) $m[1]; }
            if (stripos($h, 'Location:') === 0) { $loc = trim(substr($h, 9)); }
        }
        return [$code, $body, $loc];
    }

    // ------------------------------------------------------------------------------------- tests

    public function testFullInstallAboveTheDocrootServesAWorkingSite(): void
    {
        self::resetDb();
        $spec = $this->spec('alpha');
        [$exit, $r] = $this->cli($spec);
        $this->assertSame(0, $exit, json_encode($r));
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['already_installed']);
        $this->assertSame('above-docroot', $r['layout']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $r['version']);
        $this->assertSame('https://alpha.test/admin', $r['admin_url']);
        $this->assertSame(['ok'], array_values(array_unique(array_column($r['steps'], 'status'))), 'every step ok on a fresh run');
        $this->assertSame('expose', end($r['steps'])['step'], 'the docroot shim is written LAST');

        $app = $spec['paths']['app_root']; $doc = $spec['paths']['docroot'];
        // The security story: app + secrets above the docroot, config 0600, only a shim + assets below.
        $this->assertFileExists($app . '/vendor/autoload.php');
        $this->assertFileExists($app . '/application/configs/local.ini');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($app . '/application/configs/local.ini')), -4));
        $this->assertFileExists($doc . '/index.php');
        $this->assertFileExists($doc . '/.htaccess');
        $this->assertFileDoesNotExist($doc . '/vendor/autoload.php');
        $this->assertStringContainsString("define('APPLICATION_ROOT', '" . $app . "')", (string) file_get_contents($doc . '/index.php'));
        foreach (['_tiger', '_theme', '_media', '_code', '_modules'] as $pub) {
            $this->assertDirectoryExists($doc . '/' . $pub, "{$pub} is served from the docroot");
            if (self::$symlinkOff) { $this->assertFalse(is_link($doc . '/' . $pub), "{$pub} must be a COPY when symlink() is disabled"); }
            else                   { $this->assertTrue(is_link($doc . '/' . $pub), "{$pub} is a link when symlink() works"); }
        }
        // The ledger says installed, and nothing else does.
        $state = new Tiger_Headless_State($app);
        $this->assertTrue($state->installed());
        $this->assertSame($r['version'], $state->version());

        // The database: schema built by Tiger's migrator, the founding admin present.
        $pdo = self::pdo();
        $this->assertGreaterThan(20, (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn());
        $this->assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM tiger_migration')->fetchColumn());
        $this->assertSame('owner@alpha.test', $pdo->query("SELECT email FROM user WHERE username = 'owner'")->fetchColumn());

        // And it actually serves.
        $this->serve($doc, function ($base) {
            [$code]            = $this->http($base . '/');
            [$adm, , $loc]     = $this->http($base . '/admin');
            [$asset]           = $this->http($base . '/_theme/js/tiger.button.js');
            $this->assertSame(200, $code, 'home page');
            $this->assertSame(302, $adm, '/admin redirects a guest');
            $this->assertStringContainsString('/auth/login', $loc);
            $this->assertSame(200, $asset, 'theme asset served from the docroot');
        });
    }

    /** @depends testFullInstallAboveTheDocrootServesAWorkingSite */
    public function testASecondRunAfterSuccessChangesNothingAndSaysSo(): void
    {
        $spec = $this->spec('alpha');
        $before = md5_file($spec['paths']['app_root'] . '/application/configs/local.ini');
        [$exit, $r] = $this->cli($spec);
        $this->assertSame(0, $exit);
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['already_installed']);
        $this->assertSame([], $r['steps']);
        $this->assertSame($before, md5_file($spec['paths']['app_root'] . '/application/configs/local.ini'), 'secrets untouched');
        $this->assertSame(1, (int) self::pdo()->query("SELECT COUNT(*) FROM user WHERE email = 'owner@alpha.test'")->fetchColumn(), 'no second owner');
    }

    /** @depends testFullInstallAboveTheDocrootServesAWorkingSite */
    public function testRepointingAnInstalledTreeAtAnotherDatabaseIsRefused(): void
    {
        $spec = $this->spec('alpha', ['db' => ['name' => 'some_other_db']]);
        [$exit, $r] = $this->cli($spec);
        $this->assertSame(1, $exit);
        $this->assertSame('ledger', $r['error']['step']);
    }

    public function testWrongDatabasePasswordFailsAtRequirementsBeforeAnythingIsExtracted(): void
    {
        $spec = $this->spec('badpw', ['db' => ['password' => 'definitely-wrong-' . bin2hex(random_bytes(3))]]);
        [$exit, $r] = $this->cli($spec);
        $this->assertSame(1, $exit);
        $this->assertFalse($r['ok']);
        $this->assertSame('requirements', $r['error']['step']);
        $this->assertStringContainsString('database connection failed', $r['error']['message']);
        $this->assertFileDoesNotExist($spec['paths']['app_root'] . '/vendor/autoload.php', 'nothing extracted');
        $this->assertFileDoesNotExist($spec['paths']['docroot'] . '/index.php', 'nothing exposed');
        $this->assertFalse((new Tiger_Headless_State($spec['paths']['app_root']))->installed());
    }

    public function testUnwritableAppRootParentFailsAtRequirements(): void
    {
        $ro = self::$home . '/ro';
        mkdir($ro, 0500);
        try {
            $spec = $this->spec('rodir', ['paths' => ['app_root' => $ro . '/rodir.test/tiger-app']]);
            [$exit, $r] = $this->cli($spec);
            $this->assertSame(1, $exit);
            $this->assertSame('requirements', $r['error']['step']);
            $this->assertStringContainsString('not writable', $r['error']['message']);
        } finally {
            chmod($ro, 0755);
        }
    }

    public function testATamperedBundleChecksumStopsBeforeExtraction(): void
    {
        self::resetDb();
        $spec = $this->spec('tamper', ['source' => ['sha256' => str_repeat('0', 64)]]);
        [$exit, $r] = $this->cli($spec);
        $this->assertSame(1, $exit);
        $this->assertSame('fetch', $r['error']['step']);
        $this->assertStringContainsString('does not match', $r['error']['message']);
        $this->assertFileDoesNotExist($spec['paths']['app_root'] . '/vendor/autoload.php');
    }

    /** @depends testATamperedBundleChecksumStopsBeforeExtraction */
    public function testAFailedRunResumesFromTheFailedStepWithTheCorrectedSpec(): void
    {
        $spec = $this->spec('tamper');   // same paths + db, now with the real checksum
        [$exit, $r] = $this->cli($spec);
        $this->assertSame(0, $exit, json_encode($r));
        $steps = array_column($r['steps'], 'status', 'step');
        $this->assertSame('ok', $steps['requirements'], 'always re-checked');
        $this->assertSame('ok', $steps['fetch'], 'the failed step was retried');
        $this->assertSame('ok', $steps['expose']);
        $this->assertTrue((new Tiger_Headless_State($spec['paths']['app_root']))->installed());
    }

    /**
     * A failure AFTER the tree probes as live (step 9, theme: tree + local.ini + migrations + org all
     * exist) must resume on retry — not be adopted as "already installed" with the rest never run.
     * The exact shape TigerWHM's retry hit on host3 (TIGER-133).
     */
    public function testAFailureAfterTheTreeIsLiveResumesRatherThanAdopts(): void
    {
        self::resetDb();
        $spec = $this->spec('late', ['theme' => 'theme-does-not-exist']);
        [$exit, $r] = $this->cli($spec);
        $this->assertSame(1, $exit, json_encode($r));
        $this->assertSame('theme', $r['error']['step']);
        $steps = array_column($r['steps'], 'status', 'step');
        $this->assertSame('ok', $steps['owner'], 'the org and owner exist — the tree now probes as live');
        $this->assertTrue(Tiger_Headless_Detect::probe($spec['paths']['app_root'])['installed'], 'precondition: probe says live');

        $spec['theme'] = '';
        [$exit, $r] = $this->cli($spec);
        $this->assertSame(0, $exit, json_encode($r));
        $this->assertFalse($r['already_installed'], 'resumed, not adopted');
        $this->assertArrayNotHasKey('adopted', $r);
        $steps = array_column($r['steps'], 'status', 'step');
        $this->assertSame('ok', $steps['theme']);
        $this->assertSame('ok', $steps['expose'], 'the steps after the failure ran');
        $this->assertFileExists($spec['paths']['docroot'] . '/index.php');
        $this->assertTrue((new Tiger_Headless_State($spec['paths']['app_root']))->installed());
    }

    /** The web installer's shape: a whole install in short hops, one --until per request (TIGER-127). */
    public function testAnInstallInHopsWithUntilArrivesAtTheSamePlace(): void
    {
        self::resetDb();
        $spec = $this->spec('hops');
        [$exit, $r] = $this->cli($spec, 'extract');
        $this->assertSame(0, $exit, json_encode($r));
        $this->assertFalse($r['complete']); $this->assertSame('configure', $r['next_step']);
        $this->assertArrayNotHasKey('admin_url', $r, 'a partial run promises nothing');
        $this->assertFileDoesNotExist($spec['paths']['docroot'] . '/index.php', 'nothing exposed yet');
        [$exit, $r] = $this->cli($spec, 'owner');
        $this->assertSame(0, $exit, json_encode($r));
        $this->assertSame('modules', $r['next_step']);
        $this->assertSame('skipped', array_column($r['steps'], 'status', 'step')['fetch']);
        [$exit, $r] = $this->cli($spec, 'expose');
        $this->assertSame(0, $exit, json_encode($r));
        $this->assertTrue($r['complete']);
        $this->assertSame($spec['site']['url'] . '/admin', $r['admin_url']);
        $this->assertFileExists($spec['paths']['docroot'] . '/index.php');
        $this->assertTrue((new Tiger_Headless_State($spec['paths']['app_root']))->installed());
        // and a fourth call is the ordinary "already installed"
        [$exit, $r] = $this->cli($spec);
        $this->assertTrue($r['already_installed']);
    }

    public function testInvalidSpecExitsTwoWithoutTouchingTheHost(): void
    {
        $spec = $this->spec('badspec', ['paths' => ['docroot' => self::$home . '/badspec/badspec.test/tiger-app/public']]);
        [$exit, $r] = $this->cli($spec);
        $this->assertSame(2, $exit);
        $this->assertSame('spec', $r['error']['step']);
        $this->assertNotEmpty($r['problems']);
        $this->assertDirectoryDoesNotExist($spec['paths']['app_root']);
    }

    public function testDocrootLayoutWhenOptedInExplicitly(): void
    {
        self::resetDb();
        $home = self::$home . '/inroot';
        $spec = $this->spec('inroot', ['layout' => 'docroot', 'paths' => ['app_root' => $home . '/tiger-app', 'docroot' => $home . '/tiger-app/public']]);
        [$exit, $r] = $this->cli($spec);
        $this->assertSame(0, $exit, json_encode($r));
        $this->assertSame('docroot', $r['layout']);
        $steps = array_column($r['steps'], 'detail', 'step');
        $this->assertStringContainsString('docroot layout', $steps['expose']);
        $this->assertStringContainsString('public/index.php from the bundle', $steps['expose']);
        $this->assertFileExists($home . '/tiger-app/public/index.php');
        $this->assertStringNotContainsString('tiger-headless', (string) file_get_contents($home . '/tiger-app/public/index.php'), 'no generated shim in this layout');
    }

    /** @depends testFullInstallAboveTheDocrootServesAWorkingSite */
    public function testALiveTigerWithoutALedgerIsAdoptedNotReinstalled(): void
    {
        // A site the web installer (or Composer) put down has no ledger. Same database, same paths:
        // it must read as already installed — and NOT fail at the owner step or make a second org.
        $spec = $this->spec('alpha');
        Tiger_Headless_Files::rrmdir($spec['paths']['app_root'] . '/var/headless');
        $this->assertFileDoesNotExist($spec['paths']['app_root'] . '/var/headless/state.json');
        $orgs = (int) self::pdo()->query('SELECT COUNT(*) FROM org WHERE deleted = 0')->fetchColumn();

        [$exit, $r] = $this->cli($spec);
        $this->assertSame(0, $exit, json_encode($r));
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['already_installed']);
        $this->assertTrue($r['adopted']);
        $this->assertSame([], $r['steps']);
        $this->assertSame($orgs, (int) self::pdo()->query('SELECT COUNT(*) FROM org WHERE deleted = 0')->fetchColumn(), 'no second org');
        $state = new Tiger_Headless_State($spec['paths']['app_root']);
        $this->assertTrue($state->installed(), 'the ledger was written by adoption');
        $this->assertSame($r['version'], $state->version());
    }

    /** @depends testFullInstallAboveTheDocrootServesAWorkingSite */
    public function testDiscoverFindsTheInstallWithItsDocrootAndLiveState(): void
    {
        $spec = $this->spec('alpha');
        $file = tempnam(sys_get_temp_dir(), 'dsc');
        exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../../bin/tiger-headless') . ' discover --root=' . escapeshellarg(self::$home) . ' 2>' . escapeshellarg($file), $lines, $exit);
        @unlink($file);
        $this->assertSame(0, $exit);
        $d = json_decode(implode("\n", $lines), true);
        $this->assertIsArray($d);
        $this->assertTrue($d['ok']);
        $byApp = array_column($d['installs'], null, 'app_root');
        $this->assertArrayHasKey($spec['paths']['app_root'], $byApp, 'alpha found');
        $row = $byApp[$spec['paths']['app_root']];
        $this->assertSame($spec['paths']['docroot'], $row['docroot']);
        $this->assertSame('above-docroot', $row['layout']);
        $this->assertTrue($row['installed'], 'live: schema + org present');
        $this->assertSame(self::$db['name'], $row['db']['name']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $row['version']);
        // The deliberately broken 'badpw' tree (requirements failed, nothing extracted) is NOT an install.
        $this->assertArrayNotHasKey(self::$home . '/badpw/badpw.test/tiger-app', $byApp);
    }

    public function testDirectoryModulesThemeAndAgentHandshake(): void
    {
        if (getenv('TIGER_HEADLESS_NETWORK') !== '1') { $this->markTestSkipped('TIGER_HEADLESS_NETWORK=1 to install from the Directory'); }
        self::resetDb();
        $spec = $this->spec('full', ['modules' => ['docs'], 'theme' => 'theme-grey-mist', 'agent' => true, 'locale' => 'es']);
        // cPanel writes this into a new subdomain's docroot BEFORE anything is installed.
        file_put_contents($spec['paths']['docroot'] . '/.htaccess', "<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php81 .php .php8 .phtml\n</IfModule>\n");
        [$exit, $r] = $this->cli($spec);
        $this->assertSame(0, $exit, json_encode($r));
        $steps = array_column($r['steps'], 'detail', 'step');
        $this->assertStringContainsString('docs installed', $steps['modules']);
        $this->assertStringContainsString('theme-grey-mist installed', $steps['theme']);
        $this->assertStringContainsString('activated', $steps['theme']);
        $this->assertStringContainsString('credential minted', $steps['agent']);
        $this->assertStringStartsWith('tgr_', $r['agent']['token']);
        $this->assertSame('https://full.test/mcp', $r['agent']['endpoint']);

        $pdo = self::pdo();
        $this->assertSame('grey-mist', $pdo->query("SELECT config_value FROM config WHERE config_key = 'tiger.theme'")->fetchColumn());
        $this->assertSame('1', (string) $pdo->query("SELECT config_value FROM config WHERE config_key = 'tiger.mcp.enabled'")->fetchColumn());
        $this->assertSame(['docs', 'theme-grey-mist'], $pdo->query("SELECT slug FROM module WHERE active = 1 AND slug IN ('docs','theme-grey-mist') ORDER BY slug")->fetchAll(PDO::FETCH_COLUMN));
        $this->assertStringContainsString('tiger.i18n.default = "es"', (string) file_get_contents($spec['paths']['app_root'] . '/application/configs/local.ini'));

        $ht = (string) file_get_contents($spec['paths']['docroot'] . '/.htaccess');
        $this->assertStringContainsString('x-httpd-ea-php81', $ht, 'cPanel\'s handler block survives');
        $this->assertStringContainsString('RewriteRule', $ht, 'and Tiger\'s front controller rules are in');
        $this->assertDirectoryExists($spec['paths']['docroot'] . '/_greymist', 'the theme\'s asset base reaches the docroot');
        $this->serve($spec['paths']['docroot'], function ($base) {
            [$code, $body] = $this->http($base . '/');
            $this->assertSame(200, $code);
            $this->assertStringContainsString('/_greymist/css/grey-mist.css', $body, 'the installed theme is the one rendering');
            [$css] = $this->http($base . '/_greymist/css/grey-mist.css');
            $this->assertSame(200, $css, 'and its CSS actually SERVES from the docroot');
            [$docs] = $this->http($base . '/docs');
            $this->assertSame(200, $docs, 'the installed module routes');
        });
    }
}
