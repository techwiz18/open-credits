<?php

namespace OpenCredits\Credits\Service;

/**
 * One access verdict for [charge] premium content. Pure — primitives in, a
 * reason string out — so render and unlock ask the same question without
 * re-spelling the exemption list.
 *
 * Priority: denied → author → staff → bypass → paid → locked. Author beats
 * staff so the stats line renders exactly once. Denied only for a member who
 * cannot view the add-on and holds no exemption; a paid member without view
 * permission is still denied.
 */
final class ChargeAccess
{
    public const DENIED = 'denied';
    public const AUTHOR = 'author';
    public const STAFF = 'staff';
    public const BYPASS = 'bypass';
    public const PAID = 'paid';
    public const LOCKED = 'locked';

    public static function resolve(
        bool $canView,
        bool $hasAuthor,
        bool $isAuthor,
        bool $isStaff,
        bool $isBypass,
        bool $hasPaid
    ): string {
        if (!$canView && !$isAuthor && !$isStaff && !$isBypass) {
            return self::DENIED;
        }
        // Nobody to pay: show the body to whoever may view the add-on.
        if (!$hasAuthor) {
            return self::BYPASS;
        }
        if ($isAuthor) {
            return self::AUTHOR;
        }
        if ($isStaff) {
            return self::STAFF;
        }
        if ($isBypass) {
            return self::BYPASS;
        }
        if ($hasPaid) {
            return self::PAID;
        }
        return self::LOCKED;
    }

    public static function allows(string $verdict): bool
    {
        return in_array($verdict, [self::AUTHOR, self::STAFF, self::BYPASS, self::PAID], true);
    }

    public static function isAuthor(string $verdict): bool
    {
        return $verdict === self::AUTHOR;
    }
}
