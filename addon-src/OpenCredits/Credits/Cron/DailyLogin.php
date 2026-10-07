<?php

namespace OpenCredits\Credits\Cron;

/** Daily login is awarded via the visitor_setup listener, not cron. */
class DailyLogin
{
    public static function run(): void
    {
        // Intentionally empty: kept so any stray schedule referencing this
        // class is a silent no-op instead of an error. See issue tracking
        // interest/paycheck schedules for real cron work.
    }
}
