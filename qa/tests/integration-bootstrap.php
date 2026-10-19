<?php

// Integration bootstrap: boots the real XenForo application from a licensed
// dev install so DB-backed service tests (prune/rebuild, etc.) can run against
// a live database.
//
// Point it at a forum root that contains src/XF.php, e.g.:
//
//   OC_XF_ROOT=/var/www/html ./vendor/bin/phpunit -c phpunit-integration.xml
//
// When OC_XF_ROOT is unset (or has no XF.php), the framework is not loaded and
// the integration tests mark themselves skipped, so public CI stays green
// without a licensed runtime.

$root = getenv('OC_XF_ROOT') ?: '';
if ($root !== '' && is_file($root . '/src/XF.php')) {
    require $root . '/src/XF.php';
    \XF::start($root);
    \XF::setupApp('XF\Cli\App');
}
