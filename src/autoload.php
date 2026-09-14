<?php
/**
 * Dependency-free autoloader — the headless installer runs BEFORE Tiger (and Composer) exist on the
 * host, so it cannot lean on vendor/autoload.php. Composer users get the same map via psr-0.
 */
spl_autoload_register(static function ($class) {
    if (strpos($class, 'Tiger_Headless_') !== 0) { return; }
    $file = __DIR__ . '/' . str_replace('_', '/', $class) . '.php';
    if (is_file($file)) { require $file; }
});
