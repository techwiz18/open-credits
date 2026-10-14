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

namespace {
    // Addon root: repo layout (<repo>/addon-src/OpenCredits/Credits) by
    // default; override with OC_ADDON_SRC when the checkout is mounted
    // elsewhere (e.g. a temp copy inside the web container).
    $addonRoot = getenv('OC_ADDON_SRC')
        ?: (__DIR__ . '/../../addon-src/OpenCredits/Credits');
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
