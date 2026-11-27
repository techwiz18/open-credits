<?php

// Integration bootstrap: boots the real XenForo application from a licensed
// dev install so DB-backed service tests (prune/rebuild, etc.) can run against
// a live database.
//
// Point it at a forum root that contains src/XF.php, e.g.:
//
//   OC_XF_ROOT=/var/www/html ./vendor/bin/phpunit -c phpunit-integration.xml
//
// Without OC_XF_ROOT the framework is not loaded; public CI runs only the
// unit suite, so there is nothing for a bare checkout to boot.

// Shared request-isolation base: PHPUnit only loads *Test.php files, so the
// abstract base is not discovered on its own. The standalone seeder also
// includes this bootstrap without PHPUnit's autoloader.
if (class_exists(\PHPUnit\Framework\TestCase::class)) {
    require_once __DIR__ . '/Integration/IntegrationTestCase.php';
}

$root = getenv('OC_XF_ROOT') ?: '';
if ($root !== '' && is_file($root . '/src/XF.php')) {
    require $root . '/src/XF.php';
    \XF::start($root);
    \XF::setupApp('XF\Cli\App');
}
