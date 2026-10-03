<?php

namespace OpenCredits\Credits\Cron;

/** Awards daily_login trigger once per user per day (guarded by transaction log). */
class DailyLogin
{
    public static function run(): void
    {
        // MVP stub: actual per-user award happens on session creation.
        // Full cron sweep lands in Phase 3 with paycheck support.
        \XF::logException(new \Exception('OpenCredits DailyLogin cron hit (stub)'), false);
    }
}
