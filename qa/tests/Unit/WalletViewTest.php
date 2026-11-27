<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\Service\WalletView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The wallet's view decisions, pinned without XenForo: selection fallback
 * order, the visitor's balance lookup, the amount step, the show flags, the
 * profile-tab mode, the per-page choice and the per-page URLs. The controller,
 * entity getters, widget and templates read these, so a rule change here is
 * the only place visibility can drift.
 */
class WalletViewTest extends TestCase
{
    #[DataProvider('amountSteps')]
    public function testAmountStep(int $decimals, string $expected): void
    {
        $this->assertSame($expected, WalletView::amountStep($decimals));
    }

    public static function amountSteps(): array
    {
        return [
            'whole units' => [0, '1'],
            'one decimal' => [1, '0.1'],
            'two decimals' => [2, '0.01'],
            'three decimals' => [3, '0.001'],
        ];
    }

    #[DataProvider('selections')]
    public function testChooseCurrencyId(
        array $currencies,
        int $requested,
        bool $allowFallback,
        ?int $expected
    ): void {
        $this->assertSame($expected, WalletView::chooseCurrencyId($currencies, $requested, $allowFallback));
    }

    public static function selections(): array
    {
        $two = [
            ['currency_id' => 1, 'is_primary' => 1],
            ['currency_id' => 2, 'is_primary' => 0],
        ];
        $primarySecond = [
            ['currency_id' => 1, 'is_primary' => 0],
            ['currency_id' => 2, 'is_primary' => 1],
        ];

        return [
            'requested wins' => [$two, 2, true, 2],
            'requested wins without fallback' => [$two, 2, false, 2],
            'missing request falls back to primary' => [$primarySecond, 9, true, 2],
            'missing request without fallback is null' => [$two, 9, false, null],
            'no request uses primary' => [$primarySecond, 0, true, 2],
            'no primary uses lowest id' => [$two, 0, true, 1],
            // A missing selection is not an invalid request: it always falls
            // back, even on the transfer-save path (fallback disabled).
            'no request without fallback uses primary' => [$primarySecond, 0, false, 2],
            'no request without fallback uses lowest id' => [$two, 0, false, 1],
            'negative request without fallback falls back' => [$primarySecond, -1, false, 2],
            'empty list is null' => [[], 1, true, null],
            'empty list without fallback is null' => [[], 1, false, null],
            'hidden or inactive rows are not candidates' => [[], 2, true, null],
        ];
    }

    #[DataProvider('balances')]
    public function testBalanceIn(array $rows, int $currencyId, float $expected): void
    {
        $this->assertSame($expected, WalletView::balanceIn($rows, $currencyId));
    }

    public static function balances(): array
    {
        $rows = [
            ['currency_id' => 1, 'balance' => '12.50'],
            ['currency_id' => 2, 'balance' => 3],
        ];

        return [
            'first currency' => [$rows, 1, 12.5],
            'second currency' => [$rows, 2, 3.0],
            'missing row is zero' => [$rows, 9, 0.0],
            'empty list is zero' => [[], 1, 0.0],
        ];
    }

    public function testPrimaryInReturnsThePrimaryRow(): void
    {
        $rows = [
            ['currency_id' => 1, 'is_primary' => 0, 'balance' => '1.00'],
            ['currency_id' => 2, 'is_primary' => 1, 'balance' => '2.00'],
        ];

        $this->assertSame($rows[1], WalletView::primaryIn($rows));
        $this->assertNull(WalletView::primaryIn([$rows[0]]));
        $this->assertNull(WalletView::primaryIn([]));
    }

    #[DataProvider('balanceVisibilities')]
    public function testShowBalance(
        bool $inProfileTab,
        int $currencyCount,
        bool $showProfileStats,
        bool $expected
    ): void {
        $this->assertSame(
            $expected,
            WalletView::showBalanceFor($inProfileTab, $currencyCount, $showProfileStats)
        );
    }

    public static function balanceVisibilities(): array
    {
        return [
            'standalone always shows' => [false, 1, true, true],
            'embedded, one currency, stats on hides' => [true, 1, true, false],
            'embedded, one currency, stats off shows' => [true, 1, false, true],
            'embedded, several currencies shows' => [true, 2, true, true],
        ];
    }

    #[DataProvider('tabModes')]
    public function testTabModeFor(
        string $configuredMode,
        bool $isSelf,
        int $currencyCount,
        string $expected
    ): void {
        $this->assertSame($expected, WalletView::tabModeFor($configuredMode, $isSelf, $currencyCount));
    }

    public static function tabModes(): array
    {
        return [
            'other member is always the member card' => ['wallet', false, 1, 'member'],
            'wallet mode forces the wallet' => ['wallet', true, 3, 'wallet'],
            'member mode forces the card' => ['member', true, 1, 'member'],
            'auto uses the wallet with one currency' => ['auto', true, 1, 'wallet'],
            'auto uses the card with several currencies' => ['auto', true, 2, 'member'],
            'unknown mode behaves as auto' => ['', true, 1, 'wallet'],
        ];
    }

    #[DataProvider('perPageChoices')]
    public function testPickPerPage(?int $requested, ?int $stored, int $expected): void
    {
        $this->assertSame($expected, WalletView::pickPerPage($requested, $stored));
    }

    public static function perPageChoices(): array
    {
        return [
            'requested wins' => [20, 50, 20],
            'invalid request keeps stored' => [30, 50, 50],
            'no request keeps stored' => [null, 50, 50],
            'invalid stored falls back to ten' => [null, 7, 10],
            'missing stored falls back to ten' => [null, null, 10],
            'zero stored falls back to ten' => [null, 0, 10],
        ];
    }

    public function testPerPageUrlsKeepTheOptionOrderAndBaseParams(): void
    {
        $calls = [];
        $build = function (string $link, array $params) use (&$calls): string {
            $calls[] = [$link, $params];
            return $link . '?' . http_build_query($params);
        };

        $urls = WalletView::perPageUrls(
            [10, 20, 50],
            'credits/member',
            ['user_id' => 7, 'currency_id' => 2],
            $build
        );

        $this->assertSame([10, 20, 50], array_keys($urls));
        $this->assertSame(
            ['credits/member', ['user_id' => 7, 'currency_id' => 2, 'per_page' => 10]],
            $calls[0]
        );
        $this->assertSame(
            ['credits/member', ['user_id' => 7, 'currency_id' => 2, 'per_page' => 50]],
            $calls[2]
        );
        $this->assertSame('credits/member?user_id=7&currency_id=2&per_page=20', $urls[20]);
    }
}
