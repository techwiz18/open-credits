<?php

// Pure-unit bootstrap: no XenForo boot. Only dependency-free static helpers
// under test. Parent XF classes are stubbed because the file layout mirrors
// the addon (Entity\Code extends XF Entity); the statics under test never
// touch the parent.
//
// DB-backed service tests live in tests/Integration (see tests/README.md)
// and need the dev forum stack.

namespace XF\Mvc\Entity {
    if (!class_exists('XF\Mvc\Entity\Entity', false)) {
        class Entity
        {
            public const UINT = 'uint';
            public const STR = 'str';
            public const BOOL = 'bool';
            public const FLOAT = 'float';
            public const JSON_ARRAY = 'json-array';
            public const TO_ONE = 'to_one';

            // Mirrors the runtime's column magic so phpstan honours entity
            // @property annotations instead of reporting every column.
            /** @return mixed */
            public function __get($name)
            {
                return null;
            }

            /** @param mixed $value */
            public function __set($name, $value)
            {
            }

            /** @return mixed */
            public function __isset($name)
            {
                return false;
            }
        }
    }
    if (!class_exists('XF\Mvc\Entity\Structure', false)) {
        class Structure
        {
            public $table;
            public $shortName;
            public $primaryKey;
            public $columns = [];
            public $getters = [];
            public $relations = [];
        }
    }
}

namespace XF\Service {
    if (!class_exists('XF\Service\AbstractService', false)) {
        class AbstractService
        {
            // Note: this stub is the single definition phpstan sees. The
            // analysis bootstrap (tests/phpstan-bootstrap.php) requires
            // this file and must NOT redeclare anything here: a second
            // static definition shadows the runtime one and blinds the
            // analysis to these members.
            /** @var mixed */
            protected $app;
            public function __construct(...$args)
            {
            }
            /** @return mixed */
            protected function db()
            {
                return null;
            }
        }
    }
}

namespace {
    // Addon root: repo layout (<repo>/addon-src/OpenCredits/Credits) by
    // default; override with OC_ADDON_SRC when the checkout is mounted
    // elsewhere (e.g. a temp copy inside the web container). The docker
    // adapter mounts the licensed tree at /var/www/html, so .qatmp's sibling
    // copy sits under src/addons rather than addon-src.
    $addonRoot = getenv('OC_ADDON_SRC');
    if (!$addonRoot) {
        foreach ([
            __DIR__ . '/../../addon-src/OpenCredits/Credits',
            __DIR__ . '/../../src/addons/OpenCredits/Credits',
        ] as $candidate) {
            if (is_dir($candidate)) {
                $addonRoot = $candidate;
                break;
            }
        }
    }
    $addonRoot = $addonRoot ?: (__DIR__ . '/../../addon-src/OpenCredits/Credits');
    spl_autoload_register(function ($class) use ($addonRoot) {
        $prefix = 'OpenCredits\\Credits\\';
        if (strpos($class, $prefix) === 0) {
            $file = $addonRoot . '/'
                . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });
}
