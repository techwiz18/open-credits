<?php

namespace OpenCredits\Credits\XF\Entity;

use OpenCredits\Credits\Service\Transact;

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
            return $this->ocTransact()->balancesFor((int)$this->user_id, true);
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
            return $this->ocTransact()->balancesFor((int)$this->user_id, false);
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
        foreach ($this->getOcAllBalances() as $row) {
            if (!empty($row['is_primary'])) {
                return $row;
            }
        }
        return null;
    }

    protected function ocTransact(): Transact
    {
        return $this->app()->service('OpenCredits\Credits:Transact');
    }
}
