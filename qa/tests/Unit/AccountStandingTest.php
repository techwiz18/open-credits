<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\Service\AccountStanding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The account-standing table pinned in full: every XenForo state × banned ×
 * four verdicts, including the states nobody thought about (bounced email,
 * unknown, null). A loosened rule turns this red, which is the point — the
 * table is the only copy.
 */
class AccountStandingTest extends TestCase
{
    #[DataProvider('matrix')]
    public function testVerdicts(
        ?string $state,
        bool $banned,
        bool $earn,
        bool $receive,
        bool $send,
        bool $unlock
    ): void {
        $this->assertSame($earn, AccountStanding::canEarn($state, $banned));
        $this->assertSame($receive, AccountStanding::canReceive($state, $banned));
        $this->assertSame($send, AccountStanding::canSend($state, $banned));
        $this->assertSame($unlock, AccountStanding::canUnlock($state, $banned));
    }

    public static function matrix(): array
    {
        return [
            'valid' => ['valid', false, true, true, true, true],
            'email bounced' => ['email_bounce', false, true, true, true, true],
            'email confirmation pending' => ['email_confirm', false, true, true, false, false],
            'email change pending' => ['email_confirm_edit', false, true, true, false, false],
            'awaiting approval' => ['moderated', false, true, true, false, false],
            'rejected' => ['rejected', false, false, false, false, false],
            'disabled' => ['disabled', false, false, false, false, false],
            'banned' => ['valid', true, false, false, false, false],
            'banned and unconfirmed' => ['email_confirm', true, false, false, false, false],
            'banned and awaiting approval' => ['moderated', true, false, false, false, false],
            'unknown state' => ['temporarily_awesome', false, false, false, false, false],
            'no state' => [null, false, false, false, false, false],
            'empty state' => ['', false, false, false, false, false],
        ];
    }
}
