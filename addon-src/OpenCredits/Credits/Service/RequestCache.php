<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Service;

/**
 * The one request-scoped memo for the reads money and [charge] share:
 * balance rows, active event rows, the primary currency id and the charge
 * render state.
 *
 * Production writes drop it through invalidate(), so a new award or purchase
 * path cannot leave a stale read behind. Tests call reset() between cases.
 */
final class RequestCache
{
    protected static ?self $instance = null;

    /** @var array<string, array<int, array<string, mixed>>> */
    protected array $balances = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    protected array $eventRows = [];

    /** @var array<string, float|null> */
    protected array $prices = [];

    /** @var array<string, int> */
    protected array $unlockCounts = [];

    protected ?int $primaryCurrencyId = null;

    protected ?ContentCharge $contentCharge = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Drops the memo between requests. Writes use invalidate(); this is the
     * test seam (and the long-running-worker seam).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Drops every cached read. Safe mid-transaction: a later read sees the
     * connection's own uncommitted rows, and a rollback only costs a reload.
     */
    public function invalidate(): void
    {
        $this->balances = [];
        $this->eventRows = [];
        $this->prices = [];
        $this->unlockCounts = [];
        $this->primaryCurrencyId = null;
        $this->contentCharge = null;
    }

    /**
     * @param callable(): array<int, array<string, mixed>> $load
     * @return array<int, array<string, mixed>>
     */
    public function balances(int $userId, bool $visibleOnly, callable $load): array
    {
        $key = self::balanceKey($userId, $visibleOnly);
        if (!array_key_exists($key, $this->balances)) {
            $this->balances[$key] = $load();
        }
        return $this->balances[$key];
    }

    public function hasBalances(int $userId, bool $visibleOnly): bool
    {
        return array_key_exists(self::balanceKey($userId, $visibleOnly), $this->balances);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function storeBalances(int $userId, bool $visibleOnly, array $rows): void
    {
        $this->balances[self::balanceKey($userId, $visibleOnly)] = $rows;
    }

    /**
     * @param callable(): array<int, array<string, mixed>> $load
     * @return array<int, array<string, mixed>>
     */
    public function eventRows(string $trigger, callable $load): array
    {
        if (!array_key_exists($trigger, $this->eventRows)) {
            $this->eventRows[$trigger] = $load();
        }
        return $this->eventRows[$trigger];
    }

    /**
     * @param callable(): int $load
     */
    public function primaryCurrencyId(callable $load): int
    {
        if ($this->primaryCurrencyId === null) {
            $this->primaryCurrencyId = $load();
        }
        return $this->primaryCurrencyId;
    }

    /**
     * @param callable(): ?float $load
     */
    public function price(string $key, callable $load): ?float
    {
        if (!array_key_exists($key, $this->prices)) {
            $this->prices[$key] = $load();
        }
        return $this->prices[$key];
    }

    /**
     * @param callable(): int $load
     */
    public function unlockCount(string $key, callable $load): int
    {
        if (!array_key_exists($key, $this->unlockCounts)) {
            $this->unlockCounts[$key] = $load();
        }
        return $this->unlockCounts[$key];
    }

    /**
     * @param callable(): ContentCharge $factory
     */
    public function contentCharge(callable $factory): ContentCharge
    {
        return $this->contentCharge ??= $factory();
    }

    protected static function balanceKey(int $userId, bool $visibleOnly): string
    {
        return $userId . ':' . ($visibleOnly ? 'v' : 'a');
    }
}
