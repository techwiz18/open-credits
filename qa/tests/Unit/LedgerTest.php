<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\Service\Ledger;
use PHPUnit\Framework\TestCase;

/**
 * One label map and one set of marker strings. The label values are the
 * pre-consolidation union unchanged; stored markers must keep matching rows
 * written by older versions, so their exact strings are pinned here.
 */
class LedgerTest extends TestCase
{
    public function testLabelReturnsTheStoredLabels(): void
    {
        $this->assertSame('New thread', Ledger::label('thread'));
        $this->assertSame('Reply', Ledger::label('post'));
        $this->assertSame('Reaction received', Ledger::label('reaction_received'));
        $this->assertSame('Registration', Ledger::label('register'));
        $this->assertSame('Daily visit', Ledger::label('daily_login'));
        $this->assertSame('Redeem code', Ledger::label('redeem'));
        $this->assertSame('Premium content', Ledger::label('charge'));
        $this->assertSame('Demurrage', Ledger::label('demurrage'));
    }

    public function testUnknownTriggerStillHumanises(): void
    {
        $this->assertSame('Custom Thing', Ledger::label('custom_thing'));
        $this->assertSame('Transfer', Ledger::label('transfer'));
        $this->assertSame('', Ledger::label(''));
    }

    public function testEventTriggersCoverAwardTriggersOnly(): void
    {
        $this->assertSame([
            'thread' => 'New thread',
            'post' => 'Reply',
            'reaction_received' => 'Reaction received',
            'register' => 'Registration',
            'daily_login' => 'Daily visit',
        ], Ledger::eventTriggers());
    }

    public function testMarkersKeepTheirStoredStrings(): void
    {
        $this->assertSame('Historical backfill', Ledger::NOTE_BACKFILL);
        $this->assertSame('reaction:', Ledger::NOTE_REACTION_PREFIX);
        $this->assertTrue(Ledger::isBackfillNote('Historical backfill'));
        $this->assertFalse(Ledger::isBackfillNote(''));
        $this->assertFalse(Ledger::isBackfillNote('code #3'));
    }

    public function testPruneGuardStillMatchesOlderMarkerRows(): void
    {
        [$trigger, $note, $noteLike] = Ledger::pruneGuardParams();
        $this->assertSame('redeem', $trigger);
        $this->assertSame(Ledger::NOTE_BACKFILL, $note);
        $this->assertSame('reaction:%', $noteLike);
        // Behave like SQL LIKE: rows written by older versions must match.
        $likeRegex = '/^' . str_replace('%', '.*', preg_quote($noteLike, '/')) . '$/';
        $this->assertMatchesRegularExpression($likeRegex, Ledger::NOTE_REACTION_PREFIX . '42');
        $this->assertDoesNotMatchRegularExpression($likeRegex, 'code #3');
    }
}
