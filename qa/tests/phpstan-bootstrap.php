<?php

// Analysis-only doubles for phpstan. Never loaded by PHPUnit: the unit
// bootstrap's class_exists guards defer to these richer stubs so calls on
// $this->db() / $this->app / \XF resolve. All returns are mixed, so only
// real defects report (unknown symbols, undefined variables, bad calls on
// known signatures) — never XF's untyped surface or the missing runtime.

namespace XF\Entity {
    if (!class_exists('XF\Entity\User', false)) {
        class User
        {
            /** @var mixed */
            public $__props;
            /** @return mixed */
            public function __get($name)
            {
                return null;
            }
        }
    }
}

namespace {
    if (!class_exists('XF', false)) {
        class XF
        {
            /** @var int */
            public static $time = 0;
            /** @return mixed */
            public static function app()
            {
                return null;
            }
            /** @return mixed */
            public static function db()
            {
                return null;
            }
            public static function logException(\Throwable $e, $display = null, $message = null): void
            {
            }
        }
    }
    require __DIR__ . '/bootstrap.php';
}
