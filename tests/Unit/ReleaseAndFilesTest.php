<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 */
use PHPUnit\Framework\TestCase;

final class ReleaseAndFilesTest extends TestCase
{
    private function releases(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/releases.json'), true);
    }

    public function testPickSkipsDraftsAndTheVendorOnlyZipAndFindsTheChecksum(): void
    {
        $r = Tiger_Headless_Release::pick($this->releases());
        $this->assertSame('v1.0.17', $r['tag']);
        $this->assertSame('tiger-1.0.17.zip', $r['name']);
        $this->assertSame('https://x/tiger-1.0.17.zip', $r['zip']);
        $this->assertSame('https://x/tiger-1.0.17.zip.sha256', $r['sha']);
    }

    public function testPickFailsClosedWhenTheBundleHasNoChecksum(): void
    {
        $rels = $this->releases();
        $rels[1]['assets'] = array_values(array_filter($rels[1]['assets'], fn ($a) => $a['name'] !== 'tiger-1.0.17.zip.sha256'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no tiger-1.0.17.zip.sha256');
        Tiger_Headless_Release::pick($rels);
    }

    public function testPickFailsWhenNoReleaseCarriesABundle(): void
    {
        $this->expectException(RuntimeException::class);
        Tiger_Headless_Release::pick([['tag_name' => 'v9', 'assets' => [['name' => 'notes.txt', 'browser_download_url' => 'https://x/n']]]]);
    }

    public function testResolveByVersionUsesTheTagEndpointAndAcceptsBareVersions(): void
    {
        $urls = [];
        Tiger_Headless_Release::setFetcher(function ($url) use (&$urls) {
            $urls[] = $url;
            if (str_ends_with($url, '/releases/tags/v1.0.16')) { return [json_encode($this->releases()[2]), 200]; }
            return [null, 404];
        });
        try {
            $r = Tiger_Headless_Release::resolve('1.0.16');
            $this->assertSame('v1.0.16', $r['tag']);
            $this->assertStringEndsWith('/releases/tags/v1.0.16', $urls[0]);
        } finally {
            Tiger_Headless_Release::setFetcher(null);
        }
    }

    public function testResolveLatestListsReleasesRatherThanLatestEndpoint(): void
    {
        $urls = [];
        Tiger_Headless_Release::setFetcher(function ($url) use (&$urls) { $urls[] = $url; return [json_encode($this->releases()), 200]; });
        try {
            $r = Tiger_Headless_Release::resolve('');
            $this->assertSame('v1.0.17', $r['tag']);
            $this->assertStringContainsString('/releases?per_page=', $urls[0], 'GitHub /releases/latest skips pre-releases');
        } finally {
            Tiger_Headless_Release::setFetcher(null);
        }
    }

    public function testDigestParsing(): void
    {
        $hex = str_repeat('ab', 32);
        $this->assertSame($hex, Tiger_Headless_Release::digestFrom("{$hex}  tiger-1.0.17.zip\n"));
        $this->assertSame($hex, Tiger_Headless_Release::digestFrom(strtoupper($hex)));
        $this->assertSame('', Tiger_Headless_Release::digestFrom(''));
        $this->assertSame('', Tiger_Headless_Release::digestFrom("<html>404</html>"));
        $this->assertSame('', Tiger_Headless_Release::digestFrom(substr($hex, 0, 40)));
    }

    public function testIniMergePreservesOtherKeysAndReplacesInPlace(): void
    {
        $existing = "[production]\ntiger.crypto.key = \"KEEP\"\ntiger.db.dbname = \"old\"\n";
        $out = Tiger_Headless_Files::iniMerge($existing, ['tiger.db.dbname' => 'new', 'tiger.db.host' => 'localhost']);
        $this->assertStringContainsString('tiger.crypto.key = "KEEP"', $out);
        $this->assertStringContainsString('tiger.db.dbname = "new"', $out);
        $this->assertStringNotContainsString('"old"', $out);
        $this->assertSame(1, substr_count($out, 'tiger.db.dbname'), 'replaced, not duplicated');
        $this->assertStringContainsString('tiger.db.host = "localhost"', $out);
        $this->assertSame('new', Tiger_Headless_Files::iniValue($out, 'tiger.db.dbname'));
        $this->assertSame('', Tiger_Headless_Files::iniValue($out, 'tiger.nope'));
    }

    public function testIniMergeStartsAProductionSectionWhenEmpty(): void
    {
        $out = Tiger_Headless_Files::iniMerge('', ['a.b' => 'c']);
        $this->assertStringStartsWith("[production]\n", $out);
        $this->assertStringContainsString('a.b = "c"', $out);
    }

    public function testWriteAtomicSetsModeAndReportsUnwritable(): void
    {
        $dir = sys_get_temp_dir() . '/tiger-headless-files-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $this->assertTrue(Tiger_Headless_Files::writeAtomic($dir . '/sub/local.ini', "x", 0600));
            $this->assertSame('0600', substr(sprintf('%o', fileperms($dir . '/sub/local.ini')), -4));
            chmod($dir . '/sub', 0500);
            $this->assertFalse(Tiger_Headless_Files::writeAtomic($dir . '/sub/other.ini', "x", 0600));
        } finally {
            @chmod($dir . '/sub', 0755);
            Tiger_Headless_Files::rrmdir($dir);
        }
    }

    public function testHtaccessMergeKeepsCpanelsBlockAndIsIdempotent(): void
    {
        $dir = sys_get_temp_dir() . '/tiger-headless-ht-' . bin2hex(random_bytes(4)); mkdir($dir);
        try {
            file_put_contents($dir . '/tiger.htaccess', "RewriteEngine On\nRewriteRule . /index.php [L]\n");
            // 1. nothing there → written as-is
            $this->assertSame('written', Tiger_Headless_Installer::mergeHtaccess($dir . '/tiger.htaccess', $dir . '/a/.htaccess'));
            $this->assertSame("RewriteEngine On\nRewriteRule . /index.php [L]\n", file_get_contents($dir . '/a/.htaccess'));
            // 2. cPanel's handler block already there → kept, Tiger's appended under the marker
            $cp = "<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php81 .php .php8 .phtml\n</IfModule>\n";
            file_put_contents($dir . '/b.htaccess', $cp);
            $this->assertSame('merged', Tiger_Headless_Installer::mergeHtaccess($dir . '/tiger.htaccess', $dir . '/b.htaccess'));
            $out = file_get_contents($dir . '/b.htaccess');
            $this->assertStringStartsWith($cp, $out, 'cPanel\'s PHP handler survives');
            $this->assertStringContainsString(Tiger_Headless_Installer::HTACCESS_MARK, $out);
            $this->assertStringContainsString('RewriteRule . /index.php [L]', $out);
            // 3. a re-run changes nothing
            $this->assertSame('already merged', Tiger_Headless_Installer::mergeHtaccess($dir . '/tiger.htaccess', $dir . '/b.htaccess'));
            $this->assertSame($out, file_get_contents($dir . '/b.htaccess'));
            $this->assertSame(1, substr_count($out, 'RewriteRule . /index.php'));
        } finally {
            Tiger_Headless_Files::rrmdir($dir);
        }
    }

    public function testUnder(): void
    {
        $this->assertTrue(Tiger_Headless_Files::under('/a/b/c', '/a/b'));
        $this->assertTrue(Tiger_Headless_Files::under('/a/b', '/a/b'));
        $this->assertFalse(Tiger_Headless_Files::under('/a/bc', '/a/b'));
        $this->assertFalse(Tiger_Headless_Files::under('/a', '/a/b'));
    }

    /** A metadata fetch must give up inside its budget; a hung host must not become a hung page (TIGER-135). */
    public function testHttpGetHonoursASmallTimeoutBudget(): void
    {
        $t = microtime(true);
        [$body, $code] = Tiger_Headless_Http::get('https://10.255.255.1/catalog.json', 'application/json', 3);
        $took = microtime(true) - $t;
        $this->assertNull($body);
        $this->assertSame(0, $code);
        $this->assertLessThan(6.0, $took, "took {$took}s against a 3s budget");
    }

    /** A sha passes through untouched; junk never reaches the network (TIGER-136). */
    public function testGithubShaPassesShasThroughAndRefusesJunk(): void
    {
        $sha = str_repeat('ab', 20);
        $this->assertSame($sha, Tiger_Headless_Http::githubSha('WebTigers/Skills', $sha));
        $this->assertNull(Tiger_Headless_Http::githubSha('../x', 'main'));
        $this->assertNull(Tiger_Headless_Http::githubSha('WebTigers/Skills', 'ref with spaces'));
    }

    /** The Authorization pass-through: prepended once, never twice, never into a file that is not there. */
    public function testEnsureAuthPassthroughIsPrependedOnceAndBeforeAnyLRule(): void
    {
        $dir = sys_get_temp_dir() . '/tiger-headless-ht-' . bin2hex(random_bytes(4)); mkdir($dir);
        $f = $dir . '/.htaccess';
        $this->assertSame('', Tiger_Headless_Installer::ensureAuthPassthrough($f), 'no file, nothing to do');
        file_put_contents($f, "# cPanel handler\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^.*$ index.php [NC,L]\n</IfModule>\n");
        $this->assertSame('added', Tiger_Headless_Installer::ensureAuthPassthrough($f));
        $s = (string) file_get_contents($f);
        $this->assertStringNotContainsString('CGIPassAuth', $s, 'AuthConfig-context directive: a 500 on a FileInfo-only vhost — never written');
        $this->assertLessThan(strpos($s, 'index.php [NC,L]'), strpos($s, 'E=HTTP_AUTHORIZATION'), 'the env rule runs BEFORE the front controller ends processing');
        $this->assertStringEndsWith("index.php [NC,L]\n</IfModule>\n", $s, 'the existing rules are untouched');
        $this->assertSame('present', Tiger_Headless_Installer::ensureAuthPassthrough($f));
        $this->assertSame(1, substr_count((string) file_get_contents($f), 'E=HTTP_AUTHORIZATION'), 'idempotent');
        // a file carrying the 1.2.0 / skeleton-1.0.21 directive is REPAIRED: directive gone, rewrite kept/added
        file_put_contents($f, Tiger_Headless_Installer::AUTH_MARK . "\n<IfModule mod_version.c>\n    <IfVersion >= 2.4.13>\n        CGIPassAuth On\n    </IfVersion>\n</IfModule>\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteCond %{HTTP:Authorization} .\n    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n</IfModule>\n# --- end Tiger Authorization block ---\n\n# rest\n");
        $this->assertSame('repaired', Tiger_Headless_Installer::ensureAuthPassthrough($f));
        $s = (string) file_get_contents($f);
        $this->assertStringNotContainsString('CGIPassAuth', $s);
        $this->assertStringNotContainsString('mod_version', $s);
        $this->assertSame(1, substr_count($s, 'E=HTTP_AUTHORIZATION'));
        $this->assertStringEndsWith("# rest\n", $s);
        // the skeleton 1.0.21 shape (directive at the top, rewrite line inside the main block)
        file_put_contents($f, "<IfModule mod_version.c>\n    <IfVersion >= 2.4.13>\n        CGIPassAuth On\n    </IfVersion>\n</IfModule>\n\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteCond %{HTTP:Authorization} .\n    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n    RewriteRule ^.*$ index.php [NC,L]\n</IfModule>\n");
        $this->assertSame('repaired', Tiger_Headless_Installer::ensureAuthPassthrough($f));
        $this->assertStringNotContainsString('CGIPassAuth', (string) file_get_contents($f));
        $this->assertSame('present', Tiger_Headless_Installer::ensureAuthPassthrough($f));
        Tiger_Headless_Files::rrmdir($dir);
    }
}
