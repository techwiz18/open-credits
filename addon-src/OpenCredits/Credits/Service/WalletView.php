<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Service;

use OpenCredits\Credits\Entity\Currency;

/**
 * One answer to "what does the wallet show": which currencies are viewable,
 * which one is selected (request → primary → lowest id), the viewer's balance,
 * the amount input step, the wallet's show flags and pager/switch/form links,
 * and whether the profile tab is the wallet or the member card.
 *
 * The controller, the User entity getters, the top-holders widget and both
 * wallet templates read this module, so selection order and visibility cannot
 * drift between surfaces. Reads only — no balance writes.
 */
final class WalletView
{
    public const PER_PAGE_OPTIONS = [10, 20, 50];

    /** @var mixed XenForo app */
    protected $app;

    /** @var Currency[]|null */
    protected $viewable;

    /** @var mixed Transact service */
    protected $transact;

    /**
     * @param mixed $app
     */
    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Active, visible currencies in id order: everything the wallet may show.
     *
     * @return Currency[]
     */
    public function viewable(): array
    {
        if ($this->viewable === null) {
            $this->viewable = $this->app->finder('OpenCredits\Credits:Currency')
                ->where('active', 1)
                ->where('visible', 1)
                ->order('currency_id')
                ->fetch()
                ->toArray();
        }
        return $this->viewable;
    }

    /**
     * A viewable currency by id, or null when it is missing, inactive or
     * hidden. This is the widget's option lookup: no fallback currency.
     */
    public function currency(int $currencyId): ?Currency
    {
        foreach ($this->viewable() AS $currency) {
            if ((int)$currency->currency_id === $currencyId) {
                return $currency;
            }
        }
        return null;
    }

    /**
     * The widget option's currency: an explicit id, else the primary currency,
     * and only when that currency is viewable. Never another fallback.
     */
    public function configuredCurrency(int $currencyId): ?Currency
    {
        if ($currencyId <= 0) {
            $currencyId = (int)$this->transact()->primaryCurrencyId();
        }
        return $this->currency($currencyId);
    }

    /**
     * The currency a surface is showing: the requested viewable currency,
     * falling back to the primary then the lowest id. Null means no viewable
     * currency (or an invalid request with fallback disabled).
     */
    public function selected(int $currencyId = 0, bool $allowFallback = true): ?Currency
    {
        $rows = [];
        foreach ($this->viewable() AS $currency) {
            $rows[] = [
                'currency_id' => (int)$currency->currency_id,
                'is_primary' => (int)$currency->is_primary,
            ];
        }
        $id = self::chooseCurrencyId($rows, $currencyId, $allowFallback);
        return $id === null ? null : $this->currency($id);
    }

    /**
     * All active currencies with this member's balances (missing row = 0).
     *
     * @return array<int, array<string, mixed>>
     */
    public function balancesFor(int $userId): array
    {
        return $this->transact()->balancesFor($userId, false);
    }

    /**
     * Active + visible currencies with this member's balances.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visibleBalancesFor(int $userId): array
    {
        return $this->transact()->balancesFor($userId, true);
    }

    /**
     * This member's balance in one currency; 0 when there is no row.
     */
    public function balanceFor(int $userId, int $currencyId): float
    {
        return self::balanceIn($this->balancesFor($userId), $currencyId);
    }

    /**
     * The visible primary currency row with this member's balance, or null.
     *
     * @return array<string, mixed>|null
     */
    public function primaryBalanceFor(int $userId): ?array
    {
        return self::primaryIn($this->visibleBalancesFor($userId));
    }

    /**
     * Smallest amount input step for a currency.
     */
    public function step(int $decimals): string
    {
        return self::amountStep($decimals);
    }

    /**
     * The wallet's show flags. Balance is hidden when the embedded single-
     * currency tab already shows it above the wallet (and profile stats are on).
     *
     * @return array{showBalance: bool, showRedeem: bool, showTransfer: bool}
     */
    public function showFlags(bool $inProfileTab): array
    {
        $options = $this->app->options();
        return [
            'showBalance' => self::showBalanceFor(
                $inProfileTab,
                count($this->viewable()),
                (bool)$options->ocShowProfileStats
            ),
            'showRedeem' => (bool)$options->ocShowWalletRedeem,
            'showTransfer' => (bool)$options->ocShowTransfer,
        ];
    }

    /**
     * Whether this profile Credits tab renders the wallet ('wallet') or the
     * per-member card ('member').
     */
    public function tabMode(bool $isSelf): string
    {
        return self::tabModeFor(
            (string)$this->app->options()->ocProfileTabMode,
            $isSelf,
            count($this->viewable())
        );
    }

    /**
     * Persist and resolve the history per-page choice (10/20/50).
     */
    public function perPage(?int $requested): int
    {
        $session = $this->app->session();
        if ($requested !== null && in_array($requested, self::PER_PAGE_OPTIONS, true)) {
            $session->set('ocWalletPerPage', $requested);
        }
        return self::pickPerPage($requested, (int)$session->get('ocWalletPerPage'));
    }

    /**
     * Pager link, params and per-page URLs. Embedded in the profile tab, they
     * stay on the member route so the pane can reload them.
     *
     * @return array{pagerLink: string, pagerParams: array, perPageUrls: array<int, string>}
     */
    public function pager(bool $inProfileTab, int $userId, int $currencyId, int $perPage): array
    {
        $link = $inProfileTab ? 'credits/member' : 'credits';
        $baseParams = $inProfileTab
            ? ['user_id' => $userId, 'currency_id' => $currencyId]
            : ['currency_id' => $currencyId];

        return [
            'pagerLink' => $link,
            'pagerParams' => $baseParams + ['per_page' => $perPage],
            'perPageUrls' => self::perPageUrls(
                self::PER_PAGE_OPTIONS,
                $link,
                $baseParams,
                function (string $link, array $params): string {
                    return $this->app->router()->buildLink($link, null, $params);
                }
            ),
        ];
    }

    /**
     * Currency switcher URLs, keyed by currency id.
     *
     * @return array<int, string>
     */
    public function switchUrls(bool $inProfileTab, int $userId, int $currencyId): array
    {
        $link = $inProfileTab ? 'credits/member' : 'credits';
        $urls = [];
        foreach ($this->viewable() AS $currency) {
            $params = ['currency_id' => (int)$currency->currency_id];
            if ($inProfileTab) {
                $params = ['user_id' => $userId] + $params;
            }
            $urls[(int)$currency->currency_id] = $this->app->router()->buildLink($link, null, $params);
        }
        return $urls;
    }

    /**
     * Hidden form fields that keep the wallet's redeem/transfer in the profile
     * tab. Empty on the standalone wallet.
     *
     * @return array<string, int>
     */
    public function formHidden(bool $inProfileTab, int $userId): array
    {
        return $inProfileTab
            ? ['oc_in_tab' => 1, 'oc_return_user_id' => $userId]
            : [];
    }

    /**
     * Selection order: requested viewable id → primary → lowest id.
     * $currencies is already filtered/sorted and each row has currency_id and
     * is_primary. Pure so the fallback order is unit-testable.
     *
     * A missing/zero request always falls back: `false` only rejects an
     * explicit non-zero id that is not viewable.
     *
     * @param array<int, array{currency_id: int, is_primary: int}> $currencies
     */
    public static function chooseCurrencyId(array $currencies, int $currencyId, bool $allowFallback): ?int
    {
        if ($currencyId > 0) {
            foreach ($currencies AS $currency) {
                if ((int)$currency['currency_id'] === $currencyId) {
                    return $currencyId;
                }
            }
            if (!$allowFallback) {
                return null;
            }
        }
        foreach ($currencies AS $currency) {
            if (!empty($currency['is_primary'])) {
                return (int)$currency['currency_id'];
            }
        }
        return $currencies ? (int)$currencies[0]['currency_id'] : null;
    }

    /**
     * A member's balance in one currency from balance rows; missing = 0.
     *
     * @param array<int, array<string, mixed>> $balances
     */
    public static function balanceIn(array $balances, int $currencyId): float
    {
        foreach ($balances AS $row) {
            if ((int)$row['currency_id'] === $currencyId) {
                return (float)$row['balance'];
            }
        }
        return 0.0;
    }

    /**
     * The primary row from balance rows.
     *
     * @param array<int, array<string, mixed>> $balances
     * @return array<string, mixed>|null
     */
    public static function primaryIn(array $balances): ?array
    {
        foreach ($balances AS $row) {
            if (!empty($row['is_primary'])) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Standalone always shows the hero balance; the embedded tab hides it only
     * when the profile stat above already shows the same single currency.
     */
    public static function showBalanceFor(bool $inProfileTab, int $currencyCount, bool $showProfileStats): bool
    {
        return !$inProfileTab || $currencyCount > 1 || !$showProfileStats;
    }

    /**
     * Profile tab mode: other members always get the card; self follows
     * wallet/member, and auto means "wallet when there is one viewable currency".
     */
    public static function tabModeFor(string $configuredMode, bool $isSelf, int $currencyCount): string
    {
        if (!$isSelf) {
            return 'member';
        }
        if ($configuredMode === 'wallet') {
            return 'wallet';
        }
        if ($configuredMode === 'member') {
            return 'member';
        }
        return $currencyCount <= 1 ? 'wallet' : 'member';
    }

    /**
     * The per-page choice from a valid request, else the stored valid choice,
     * else the default 10.
     */
    public static function pickPerPage(?int $requested, ?int $stored): int
    {
        if ($requested !== null && in_array($requested, self::PER_PAGE_OPTIONS, true)) {
            return $requested;
        }
        if ($stored !== null && in_array($stored, self::PER_PAGE_OPTIONS, true)) {
            return $stored;
        }
        return 10;
    }

    /**
     * Per-page URLs in option order, built through the supplied link builder.
     *
     * @param int[] $options
     * @param array<string, int> $baseParams
     * @param callable(string, array): string $build
     * @return array<int, string>
     */
    public static function perPageUrls(array $options, string $link, array $baseParams, callable $build): array
    {
        $urls = [];
        foreach ($options AS $option) {
            $urls[$option] = $build($link, $baseParams + ['per_page' => $option]);
        }
        return $urls;
    }

    /**
     * Smallest input step for an amount field, e.g. "1" for 0-decimal
     * currencies, "0.01" for 2-decimal ones.
     */
    public static function amountStep(int $decimals): string
    {
        return $decimals > 0 ? '0.' . str_repeat('0', $decimals - 1) . '1' : '1';
    }

    /**
     * @return mixed Transact
     */
    protected function transact()
    {
        if ($this->transact === null) {
            $this->transact = $this->app->service('OpenCredits\Credits:Transact');
        }
        return $this->transact;
    }
}
