<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\Service\LedgerWrite;
use PHPUnit\Framework\TestCase;

/**
 * The ledger row shape: column-width truncation, defaults for optional
 * movement fields, and the per-leg content_id (a transfer writes the other
 * member's id on each leg). Pure — commit()'s locks and deltas are covered by
 * the integration suite through the public Transact methods.
 */
class LedgerWriteTest extends TestCase
{
    protected function movement(array $overrides = []): array
    {
        return array_merge([
            'currency_id' => 1,
            'trigger' => 'transfer',
            'legs' => [],
        ], $overrides);
    }

    public function testDefaultsFillEveryOptionalColumn(): void
    {
        $row = LedgerWrite::buildRow($this->movement(), ['user_id' => 7, 'amount' => -5.0], 1000);

        $this->assertSame([
            'user_id' => 7,
            'currency_id' => 1,
            'amount' => -5.0,
            'trigger' => 'transfer',
            'content_type' => '',
            'content_id' => 0,
            'event_id' => 0,
            'note' => '',
            'log_date' => 1000,
        ], $row);
    }

    public function testLegContentIdOverridesMovementContentId(): void
    {
        $movement = $this->movement(['content_type' => 'charge', 'content_id' => 99]);

        $from = LedgerWrite::buildRow($movement, ['user_id' => 1, 'amount' => -5.0, 'content_id' => 2], 1000);
        $to = LedgerWrite::buildRow($movement, ['user_id' => 2, 'amount' => 5.0, 'content_id' => 1], 1000);
        $fallback = LedgerWrite::buildRow($movement, ['user_id' => 3, 'amount' => 5.0, 'content_id' => null], 1000);

        $this->assertSame(2, $from['content_id']);
        $this->assertSame(1, $to['content_id']);
        $this->assertSame(99, $fallback['content_id']);
    }

    public function testStringColumnsAreTruncatedToTheirWidth(): void
    {
        $movement = $this->movement([
            'trigger' => str_repeat('t', 60),
            'content_type' => str_repeat('c', 40),
            'note' => str_repeat('n', 300),
        ]);

        $row = LedgerWrite::buildRow($movement, ['user_id' => 1, 'amount' => 1.0], 1000);

        $this->assertSame(50, strlen($row['trigger']));
        $this->assertSame(25, strlen($row['content_type']));
        $this->assertSame(255, strlen($row['note']));
    }

    public function testEventIdIsCarriedForBackfillRows(): void
    {
        $row = LedgerWrite::buildRow(
            $this->movement(['trigger' => 'post', 'event_id' => 12, 'content_type' => 'post']),
            ['user_id' => 1, 'amount' => 4.0],
            1000
        );

        $this->assertSame(12, $row['event_id']);
        $this->assertSame('post', $row['content_type']);
    }
}
