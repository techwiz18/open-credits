<?php

namespace OpenCredits\Credits\XF\Entity;

use OpenCredits\Credits\Service\WalletView;

class User extends XFCP_User
{
    /**
     * @return array[] Active + visible currencies with this user's balances.
     * Each row: currency_id, title, prefix, suffix, decimals, balance.
     * Fails open to [] so template rendering never 500s when the addon
     * tables are unavailable (mid-upgrade, failed install).
     */
    public function getOcBalances(): array
    {
        try {
            return $this->ocWallet()->visibleBalancesFor((int)$this->user_id);
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits balances lookup failed: ');
            return [];
        }
    }

    /**
     * @return array[] All active currencies with this user's balances.
     */
    public function getOcAllBalances(): array
    {
        try {
            return $this->ocWallet()->balancesFor((int)$this->user_id);
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits balances lookup failed: ');
            return [];
        }
    }

    /**
     * @return array|null Visible primary currency row with balance, or null.
     */
    public function getOcPrimary(): ?array
    {
        try {
            return $this->ocWallet()->primaryBalanceFor((int)$this->user_id);
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits balances lookup failed: ');
            return null;
        }
    }

    protected function ocWallet(): WalletView
    {
        return new WalletView($this->app());
    }
}
