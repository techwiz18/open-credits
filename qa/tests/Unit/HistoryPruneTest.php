<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\Service\HistoryPrune;
use PHPUnit\Framework\TestCase;

/**
 * The prune run's batch loop, pinned without a database: it accumulates each
 * batch's totals and stops only when the batch reports no next boundary, and
 * a non-positive cutoff is a no-op.
 */
class HistoryPruneTest extends TestCase
{
    public function testRunAccumulatesBatchesUntilTheEnd(): void
    {
        $prune = $this->prune(100, [
            ['snapshots' => 1, 'deleted' => 50, 'next' => 100],
            ['snapshots' => 2, 'deleted' => 30, 'next' => 200],
            ['snapshots' => 0, 'deleted' => 10, 'next' => null],
        ]);

        $this->assertSame(['snapshots' => 3, 'deleted' => 90], $prune->run(7, 100));
        $this->assertSame(
            [
                [100, 0, 100],
                [100, 100, 100],
                [100, 200, 100],
            ],
            $prune->calls,
            'Each batch starts after the previous boundary and passes the cutoff and size.'
        );
    }

    public function testRunStopsAfterTheFirstBatchWhenDone(): void
    {
        $prune = $this->prune(100, [
            ['snapshots' => 4, 'deleted' => 12, 'next' => null],
        ]);

        $this->assertSame(['snapshots' => 4, 'deleted' => 12], $prune->run(7));
        $this->assertCount(1, $prune->calls);
    }

    public function testNonPositiveCutoffIsANoOp(): void
    {
        $prune = $this->prune(0, [
            ['snapshots' => 1, 'deleted' => 1, 'next' => null],
        ]);

        $this->assertSame(['snapshots' => 0, 'deleted' => 0], $prune->run(7));
        $this->assertSame([], $prune->calls, 'No batch runs without a usable cutoff.');
    }

    /**
     * @param array<int, array{snapshots: int, deleted: int, next: ?int}> $results
     * @return HistoryPrune&object{calls: array<int, array{int, int, int}>}
     */
    private function prune(int $cutoff, array $results): HistoryPrune
    {
        return new class(null, static function (): int {
            return 0;
        }, $cutoff, $results) extends HistoryPrune {
            public array $calls = [];

            /** @var array<int, array{snapshots: int, deleted: int, next: ?int}> */
            private array $results;

            private int $fixedCutoff;

            /**
             * @param array<int, array{snapshots: int, deleted: int, next: ?int}> $results
             */
            public function __construct($db, callable $dayStart, int $fixedCutoff, array $results)
            {
                parent::__construct($db, $dayStart);
                $this->fixedCutoff = $fixedCutoff;
                $this->results = $results;
            }

            public function cutoff(int $days): int
            {
                return $this->fixedCutoff;
            }

            public function prune(int $cutoff, int $startAfterUserId = 0, int $batchSize = 0): array
            {
                $this->calls[] = [$cutoff, $startAfterUserId, $batchSize];
                return array_shift($this->results)
                    ?? ['snapshots' => 0, 'deleted' => 0, 'next' => null];
            }
        };
    }
}
