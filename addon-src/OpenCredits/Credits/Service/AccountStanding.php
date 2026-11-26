<?php

namespace OpenCredits\Credits\Service;

/**
 * Whether a member's account stage permits a money action. One state table,
 * four verdicts: earning and receiving share a rule, sending and unlocking
 * share another. Pure — primitives in, bool out — so callers pass the state
 * they already hold (entity, locked row or backfill row) and account state is
 * interpreted in exactly one place.
 *
 * shortcut: the whitelist mirrors XF\User::isInActiveUserState() plus
 * 'moderated', and deliberately fails closed for unknown states; revisit when
 * XenForo adds a state.
 */
final class AccountStanding
{
    /** May earn and receive: everything XF calls active, plus awaiting approval. */
    private const PARTICIPATES = [
        'valid',
        'email_bounce',
        'email_confirm',
        'email_confirm_edit',
        'moderated',
    ];

    /** May send and unlock: active accounts, including a bounced email. */
    private const SPENDS = ['valid', 'email_bounce'];

    public static function canEarn(?string $state, bool $banned): bool
    {
        return !$banned && self::participates($state);
    }

    public static function canReceive(?string $state, bool $banned): bool
    {
        return !$banned && self::participates($state);
    }

    public static function canSend(?string $state, bool $banned): bool
    {
        return !$banned && self::spends($state);
    }

    public static function canUnlock(?string $state, bool $banned): bool
    {
        return !$banned && self::spends($state);
    }

    private static function participates(?string $state): bool
    {
        return $state !== null && in_array($state, self::PARTICIPATES, true);
    }

    private static function spends(?string $state): bool
    {
        return $state !== null && in_array($state, self::SPENDS, true);
    }
}
