<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The install, as ordered steps over the Pipeline engine. Before the `extract` step nothing of
 * Tiger exists on the host, so the early steps are self-contained; from `configure` on, every step
 * calls Tiger's own library (Tiger_Install, Tiger_Db_Migrator, Tiger_Module_Installer …) — the
 * same authorities `bin/tiger` and the web installer use. Nothing is re-implemented here.
 *
 * Order matters:
 *   requirements → fetch → extract → configure → migrate → storage → owner → modules → theme
 *   → assets → agent → expose
 *
 * `expose` (the docroot front-controller shim) is LAST on purpose: until it runs, nothing in the
 * docroot serves the app, so a run that dies half-way leaves a tree that is not web-reachable and
 * a ledger that says "not installed". A half-installed site never looks installed.
 */
class Tiger_Headless_Installer
{
    /** @var Tiger_Headless_Spec */
    protected $_spec;
    /** @var Tiger_Headless_State */
    protected $_state;
    /** @var callable|null */
    protected $_log;
    /** @var string */
    protected $_appRoot;
    /** @var string */
    protected $_docroot;
    /** @var string */
    protected $_work;
    /** @var array|null the owner row returned by createOwner (for the agent step) */
    protected $_owner;
    /** @var array extra fields for the result (login, agent, …) */
    protected $_extra = [];

    public function __construct(Tiger_Headless_Spec $spec, $log = null)
    {
        $this->_spec    = $spec;
        $this->_log     = $log;
        $this->_appRoot = $spec->get('paths.app_root');
        $this->_docroot = $spec->get('paths.docroot');
        $this->_work    = $this->_appRoot . '/var/headless';
        $this->_state   = new Tiger_Headless_State($this->_appRoot);
    }

    /** @return Tiger_Headless_Result */
    public function run()
    {
        // A Tiger that is already live here — put down by the web installer, Composer, or a run of
        // this tool whose ledger is gone — is "already installed", not a half-install to finish.
        // Adopt it: write the ledger and report, exactly as a re-run after our own success would.
        if (!$this->_state->installed()) {
            $live = Tiger_Headless_Detect::probe($this->_appRoot);
            if ($live['installed'] === true && strcasecmp($live['db']['name'], $this->_spec->get('db.name')) === 0) {
                $this->_state->bind($this->_spec->fingerprint())->setVersion($live['version'])
                    ->setLayout($this->_spec->get('layout'))->markStep('adopted', 'ok', 'live install found (' . $live['db']['name'] . ')')
                    ->markInstalled()->save();
                $r = new Tiger_Headless_Result('install');
                $r->alreadyInstalled($live['version'], $this->_spec->get('layout'))->set('adopted', true);
                $r->set('admin_url', $this->_spec->get('site.url') . '/admin');
                return $r;
            }
        }

        $p = new Tiger_Headless_Pipeline($this->_state, $this->_log);
        $p->add('requirements', [$this, 'stepRequirements'], true)
          ->add('fetch',        [$this, 'stepFetch'])
          ->add('extract',      [$this, 'stepExtract'])
          ->add('configure',    [$this, 'stepConfigure'])
          ->add('migrate',      [$this, 'stepMigrate'])
          ->add('storage',      [$this, 'stepStorage'])
          ->add('owner',        [$this, 'stepOwner'])
          ->add('modules',      [$this, 'stepModules'])
          ->add('theme',        [$this, 'stepTheme'])
          ->add('assets',       [$this, 'stepAssets'])
          ->add('agent',        [$this, 'stepAgent'])
          ->add('expose',       [$this, 'stepExpose']);

        $result = $p->run($this->_spec->fingerprint());
        $result->set('layout', $this->_spec->get('layout'));
        if ($result->ok() && !$result->toArray()['already_installed']) {
            $result->set('version', $this->_state->version());
            $result->set('admin_url', $this->_spec->get('site.url') . '/admin');
            $result->set('login', ['username' => $this->_spec->get('admin.username') ?: null, 'email' => $this->_spec->get('admin.email')]);
            foreach ($this->_extra as $k => $v) { $result->set($k, $v); }
        }
        return $result;
    }

    // ------------------------------------------------------------------------------------ steps

    /** Everything the install needs, checked up front — including that the database accepts us. */
    public function stepRequirements()
    {
        $problems = [];
        if (version_compare(PHP_VERSION, '8.1.0', '<'))  { $problems[] = 'PHP 8.1+ required (running ' . PHP_VERSION . ')'; }
        foreach (['pdo_mysql', 'mbstring', 'json'] as $ext) {
            if (!extension_loaded($ext)) { $problems[] = "PHP extension {$ext} is not loaded"; }
        }
        if (!class_exists('ZipArchive'))                 { $problems[] = 'PHP extension zip (ZipArchive) is required to extract the bundle'; }
        if ($this->_spec->get('source.bundle') === '' && !Tiger_Headless_Http::available()) {
            $problems[] = 'neither curl nor allow_url_fopen is available to download the bundle (or pass source.bundle)';
        }
        if ($this->_spec->get('source.bundle') !== '' && !is_file($this->_spec->get('source.bundle'))) {
            $problems[] = 'source.bundle does not exist: ' . $this->_spec->get('source.bundle');
        }

        // Paths: the app root is created here (its parent must be writable); the docroot must exist.
        $app = $this->_appRoot;
        if (!is_dir($app)) {
            $parent = dirname($app);
            if (!is_dir($parent) || !is_writable($parent)) { $problems[] = "cannot create app_root {$app}: parent is not writable"; }
            elseif (!@mkdir($app, 0755, true))            { $problems[] = "cannot create app_root {$app}"; }
        } elseif (!is_writable($app))                      { $problems[] = "app_root {$app} is not writable"; }

        $doc = $this->_docroot;
        if ($this->_spec->get('layout') === Tiger_Headless_Spec::LAYOUT_ABOVE) {
            if (!is_dir($doc) && !@mkdir($doc, 0755, true)) { $problems[] = "docroot {$doc} does not exist and cannot be created"; }
            elseif (!is_writable($doc))                      { $problems[] = "docroot {$doc} is not writable"; }
        }

        // An existing tree configured for a different database is somebody else's site.
        $ini = $app . '/application/configs/local.ini';
        if (is_file($ini)) {
            $have = Tiger_Headless_Files::iniValue((string) file_get_contents($ini), 'tiger.db.dbname');
            if ($have !== '' && strcasecmp($have, $this->_spec->get('db.name')) !== 0) {
                $problems[] = "{$app} is already configured for database \"{$have}\"; refusing to repoint it at \"" . $this->_spec->get('db.name') . '"';
            }
        }

        // The database was created by the caller — prove it accepts these credentials now, not
        // after 18 MB has been extracted.
        if (!$problems) {
            try {
                $pdo = new PDO(
                    'mysql:host=' . $this->_spec->get('db.host') . ';port=' . $this->_spec->get('db.port') . ';dbname=' . $this->_spec->get('db.name') . ';charset=utf8mb4',
                    $this->_spec->get('db.user'), $this->_spec->get('db.password'),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
                );
                $engine = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            } catch (Throwable $e) {
                $problems[] = 'database connection failed: ' . $e->getMessage();
                $engine = '';
            }
        }

        if ($problems) {
            throw new RuntimeException(implode('; ', $problems));
        }
        return 'PHP ' . PHP_VERSION . ', ' . ($engine ?? '') . ', symlink() ' . (function_exists('symlink') ? 'available' : 'unavailable (assets will be copied)');
    }

    /** Resolve + download + verify the bundle, or take the caller's local bundle. */
    public function stepFetch()
    {
        if (Tiger_Headless_App::looksExtracted($this->_appRoot) && $this->_state->stepDone('extract')) {
            return 'tree already extracted';
        }
        @mkdir($this->_work, 0700, true);
        $zip = $this->_work . '/bundle.zip';

        $local = $this->_spec->get('source.bundle');
        if ($local !== '') {
            $sha = $this->_spec->get('source.sha256');
            if ($sha !== '' && !hash_equals($sha, strtolower((string) hash_file('sha256', $local)))) {
                throw new RuntimeException('source.bundle does not match source.sha256 — refusing to install it.');
            }
            if (!@copy($local, $zip)) { throw new RuntimeException("Could not copy {$local} into the work dir."); }
            return basename($local) . ($sha !== '' ? ' (sha256 verified)' : ' (local bundle, no checksum supplied)');
        }

        $rel = Tiger_Headless_Release::resolve($this->_spec->get('source.version'));
        if (!Tiger_Headless_Http::download($rel['zip'], $zip)) {
            throw new RuntimeException('Download of ' . $rel['name'] . ' failed.');
        }
        list($shaBody,) = Tiger_Headless_Http::get($rel['sha']);
        $expected = Tiger_Headless_Release::digestFrom($shaBody);
        if ($expected === '') {
            throw new RuntimeException('Could not fetch a valid checksum for ' . $rel['name'] . '; stopping before extraction.');
        }
        if (!hash_equals($expected, strtolower((string) hash_file('sha256', $zip)))) {
            @unlink($zip);
            throw new RuntimeException('Checksum mismatch for ' . $rel['name'] . ' — the download may be corrupt or tampered.');
        }
        return $rel['name'] . ' (' . $rel['tag'] . ', sha256 verified)';
    }

    /** Extract the bundle into the app root (above the docroot). */
    public function stepExtract()
    {
        if (Tiger_Headless_App::looksExtracted($this->_appRoot)) {
            $v = Tiger_Headless_App::version($this->_appRoot);
            $this->_state->setVersion($v);
            return 'already present (tiger-core ' . $v . ')';
        }
        $zip = $this->_work . '/bundle.zip';
        if (!is_file($zip)) { throw new RuntimeException('No bundle in the work dir — the fetch step did not leave one.'); }
        $ex = $this->_work . '/extract';
        Tiger_Headless_Files::rrmdir($ex);
        @mkdir($ex, 0755, true);
        $za = new ZipArchive();
        if ($za->open($zip) !== true) { throw new RuntimeException('Could not open the bundle ZIP.'); }
        $za->extractTo($ex);
        $za->close();

        $root    = $ex;
        $entries = array_values(array_diff(scandir($ex), ['.', '..']));
        if (count($entries) === 1 && is_dir($ex . '/' . $entries[0]) && !is_dir($ex . '/application')) {
            $root = $ex . '/' . $entries[0];
        }
        if (!is_dir($root . '/application') || !is_dir($root . '/vendor')) {
            throw new RuntimeException('The bundle is missing application/ or vendor/ — expected a vendored full-app tiger-<version>.zip.');
        }
        Tiger_Headless_Files::rcopy($root, $this->_appRoot);
        Tiger_Headless_Files::rrmdir($ex);
        @unlink($zip);
        if (!Tiger_Headless_App::looksExtracted($this->_appRoot)) {
            throw new RuntimeException('Extraction incomplete (no vendor/autoload.php after copy).');
        }
        $v = Tiger_Headless_App::version($this->_appRoot);
        $this->_state->setVersion($v)->setLayout($this->_spec->get('layout'));
        return 'tiger-core ' . $v . ' at ' . $this->_appRoot;
    }

    /** local.ini (chmod 600, merged never replaced) + the minted secrets. */
    public function stepConfigure()
    {
        $ini  = $this->_appRoot . '/application/configs/local.ini';
        $text = is_file($ini) ? (string) file_get_contents($ini) : '';
        $kv   = [
            'tiger.db.host'     => $this->_spec->get('db.host'),
            'tiger.db.port'     => (string) $this->_spec->get('db.port'),
            'tiger.db.dbname'   => $this->_spec->get('db.name'),
            'tiger.db.username' => $this->_spec->get('db.user'),
            'tiger.db.password' => $this->_spec->get('db.password'),
            'tiger.db.charset'  => 'utf8mb4',
            'tiger.site.name'   => $this->_spec->get('site.name'),
        ];
        if ($this->_spec->get('locale') !== 'en') { $kv['tiger.i18n.default'] = $this->_spec->get('locale'); }
        if (!Tiger_Headless_Files::writeAtomic($ini, Tiger_Headless_Files::iniMerge($text, $kv), 0600)) {
            throw new RuntimeException("Could not write {$ini}.");
        }
        Tiger_Headless_App::load($this->_appRoot);
        $minted = Tiger_Install::provisionSecrets($ini);
        @chmod($ini, 0600);
        return 'local.ini written (0600)' . ($minted ? ', secrets minted: ' . implode(', ', $minted) : ', secrets already present');
    }

    /** Boot and build the schema — ONE authority for the migration scan. */
    public function stepMigrate()
    {
        Tiger_Headless_App::boot($this->_appRoot);
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        if (!$db) { throw new RuntimeException('Tiger booted without a database adapter — local.ini was not picked up.'); }
        $paths = Tiger_Module_Installer::migrationPaths();
        $applied = [];
        (new Tiger_Db_Migrator($db, $paths))->migrate(function ($line) use (&$applied) { $applied[] = $line; });
        if (method_exists('Tiger_Module_Installer', 'publishAllAssets')) {
            try { Tiger_Module_Installer::publishAllAssets(); } catch (Throwable $e) { /* best-effort, as bin/tiger migrate */ }
        }
        $summary = trim((string) end($applied));   // the migrator's own summary line: "applied N migration(s)." / "nothing to migrate."
        return $summary !== '' ? $summary : 'schema current';
    }

    /** Writable runtime dirs (idempotent). */
    public function stepStorage()
    {
        Tiger_Headless_App::boot($this->_appRoot);
        $made = Tiger_Install::provisionStorage($this->_appRoot);
        return $made ? 'created ' . implode(', ', $made) : 'already present';
    }

    /** Founding org + admin, via Tiger's own rules (password policy included). */
    public function stepOwner()
    {
        Tiger_Headless_App::boot($this->_appRoot);
        $username = $this->_spec->get('admin.username') ?: null;
        $this->_owner = Tiger_Install::createOwner(
            $this->_spec->get('admin.email'), $this->_spec->get('admin.password'),
            $this->_spec->get('admin.org'), null, 'developer', $username
        );
        return 'org "' . $this->_spec->get('admin.org') . '", admin ' . $this->_spec->get('admin.email');
    }

    /** Install + activate each requested Directory module (skips ones already on disk). */
    public function stepModules()
    {
        $slugs = $this->_spec->get('modules', []);
        if (!$slugs) { return 'none requested'; }
        Tiger_Headless_App::boot($this->_appRoot);
        $done = [];
        foreach ($slugs as $slug) {
            $done[] = $slug . ' ' . $this->_installFromDirectory($slug);
        }
        return implode(', ', $done);
    }

    /** Install the requested theme from the Directory and make it the active theme. */
    public function stepTheme()
    {
        $slug = $this->_spec->get('theme');
        if ($slug === '') { return 'none requested (platform base theme)'; }
        Tiger_Headless_App::boot($this->_appRoot);
        $how = $this->_installFromDirectory($slug);
        $this->_activateTheme($slug);
        return $slug . ' ' . $how . ', activated';
    }

    /** Docroot asset links (copied where symlink() is unavailable) — above-docroot layout only. */
    public function stepAssets()
    {
        Tiger_Headless_App::boot($this->_appRoot);
        if ($this->_spec->get('layout') === Tiger_Headless_Spec::LAYOUT_DOCROOT) {
            Tiger_Install::republishAssets($this->_appRoot);
            return 'docroot layout — assets published under public/';
        }
        Tiger_Install::linkPublicAssets($this->_docroot, $this->_appRoot, 'puma');
        $mode = [];
        foreach (['_media', '_code', '_modules'] as $pub) {
            $target = $this->_appRoot . '/public/' . $pub;
            $link   = $this->_docroot . '/' . $pub;
            if (!is_dir($target) || file_exists($link)) { continue; }
            if (function_exists('symlink') && @symlink($target, $link)) { $mode[] = "{$pub} linked"; }
            else { Tiger_Headless_Files::rcopy($target, $link); $mode[] = "{$pub} copied"; }
        }
        return (Tiger_Install::assetsAreCopied($this->_docroot) ? 'assets copied' : 'assets linked') . ($mode ? ' (' . implode(', ', $mode) . ')' : '');
    }

    /** Optional TIGER-90 connect handshake: mint an agent credential + switch /mcp on. */
    public function stepAgent()
    {
        if (!$this->_spec->get('agent')) { return 'not requested'; }
        Tiger_Headless_App::boot($this->_appRoot);
        $userId = is_array($this->_owner) ? ($this->_owner['user_id'] ?? null) : null;
        $orgId  = is_array($this->_owner) ? ($this->_owner['org_id']  ?? null) : null;
        if ($userId === null) {
            // A resume that skipped `owner` has no owner row in hand; look it up by email.
            $u = (new Tiger_Model_User())->findByEmail($this->_spec->get('admin.email'));
            $userId = $u ? $u['id'] : null;
            $orgId  = $u ? ($u['org_id'] ?? null) : null;
        }
        if ($userId === null) { throw new RuntimeException('No owner to bind the agent credential to.'); }
        $cred = (new Tiger_Model_UserCredential())->createToken($userId);
        Tiger_Mcp_Token::saveConfig($cred['credential_id'], [
            'modules' => Tiger_Mcp_Token::DEFAULT_MODULES, 'read_only' => false, 'org_scoped' => true,
            'role' => 'developer', 'org_id' => (string) $orgId,
        ]);
        (new Tiger_Model_Config())->set(Tiger_Model_Config::SCOPE_GLOBAL, '', Tiger_Mcp::CONFIG_ENABLED, '1');
        $this->_extra['agent'] = ['token' => $cred['token'], 'modules' => Tiger_Mcp_Token::DEFAULT_MODULES, 'endpoint' => $this->_spec->get('site.url') . '/mcp'];
        return 'credential minted, /mcp enabled';
    }

    /** The docroot front controller — last, so nothing serves until everything else has passed. */
    public function stepExpose()
    {
        if ($this->_spec->get('layout') === Tiger_Headless_Spec::LAYOUT_DOCROOT) {
            return 'docroot layout — public/index.php from the bundle serves the app';
        }
        $shim = "<?php\n"
              . "// Generated by tiger-headless — Tiger front controller shim. The app lives above the docroot.\n"
              . "define('APPLICATION_ROOT', " . var_export($this->_appRoot, true) . ");\n"
              . "require APPLICATION_ROOT . '/vendor/autoload.php';\n"
              . "(new Tiger_Application(APPLICATION_ROOT))->run();\n";
        if (!Tiger_Headless_Files::writeAtomic($this->_docroot . '/index.php', $shim, 0644)) {
            throw new RuntimeException('Could not write ' . $this->_docroot . '/index.php');
        }
        if (is_file($this->_appRoot . '/public/.htaccess') && !is_file($this->_docroot . '/.htaccess')) {
            @copy($this->_appRoot . '/public/.htaccess', $this->_docroot . '/.htaccess');
        }
        return 'front controller written to ' . $this->_docroot . '/index.php';
    }

    // ---------------------------------------------------------------------------------- helpers

    /**
     * Install a Directory slug through Tiger's module installer (its own manifest/checksum/placement
     * rules). Returns a short "how" for the step detail.
     */
    protected function _installFromDirectory($slug)
    {
        if ((new Tiger_Model_Module())->bySlug($slug)) {
            return 'already installed';
        }
        $listing = Tiger_Module_Registry::listing($slug);
        if (!$listing) {
            throw new RuntimeException("\"{$slug}\" is not in the Directory feed (" . Tiger_Module_Registry::indexUrl() . ').');
        }
        $repo = (string) ($listing['repository'] ?? '');
        if ($repo === '') { throw new RuntimeException("Directory listing for \"{$slug}\" has no repository URL."); }
        $model = (string) (($listing['pricing']['model'] ?? 'free'));
        if ($model !== 'free') {
            throw new RuntimeException("\"{$slug}\" is not a free listing ({$model}); the headless installer only installs free modules.");
        }
        $r = Tiger_Module_Installer::installFromUrl($repo, null, []);
        $installed = (string) ($r['slug'] ?? $slug);
        // installFromTarball records the row active; make sure its schema + assets are live too.
        Tiger_Module_Installer::migrateModule($installed);
        (new Tiger_Model_Module())->setActive($installed, true);
        Tiger_Module_Installer::publishAssets($installed);
        return 'installed ' . ($r['version'] ?? $r['ref'] ?? '');
    }

    /** Make a theme the active one — via core's seam when the core has it, else the documented config key. */
    protected function _activateTheme($slug)
    {
        if (class_exists('Tiger_Theme') && method_exists('Tiger_Theme', 'activate')) {
            Tiger_Theme::activate($slug);
            return;
        }
        $rows = Tiger_Module_Discovery::all();
        $d    = $rows[$slug] ?? null;
        if (!$d) { throw new RuntimeException("Theme \"{$slug}\" is not on disk after install."); }
        $key = (string) ($d['key'] ?? preg_replace('/^theme-/', '', $slug));
        (new Tiger_Model_Config())->set(Tiger_Model_Config::SCOPE_GLOBAL, '', 'tiger.theme', $key);
        $base   = ((string) ($d['asset_base'] ?? '')) !== '' ? $d['asset_base'] : '/_' . $key;
        $root   = (($d['area'] ?? 'app') === 'app' && defined('APPLICATION_PATH')) ? APPLICATION_PATH : TIGER_CORE_PATH;
        $assets = $root . '/modules/' . $slug . '/assets';
        if (is_dir($assets)) {
            $link = PUBLIC_PATH . '/' . ltrim($base, '/');
            if (is_link($link)) { @unlink($link); }
            if (!(function_exists('symlink') && @symlink($assets, $link)) && !is_dir($link)) {
                Tiger_Module_Installer::publishAssets($slug);
            }
        }
    }
}
