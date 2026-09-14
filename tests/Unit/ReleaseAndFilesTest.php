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

    public function testUnder(): void
    {
        $this->assertTrue(Tiger_Headless_Files::under('/a/b/c', '/a/b'));
        $this->assertTrue(Tiger_Headless_Files::under('/a/b', '/a/b'));
        $this->assertFalse(Tiger_Headless_Files::under('/a/bc', '/a/b'));
        $this->assertFalse(Tiger_Headless_Files::under('/a', '/a/b'));
    }
}
