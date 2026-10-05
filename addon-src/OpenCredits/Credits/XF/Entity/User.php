<?php

namespace OpenCredits\Credits\XF\Entity;

use OpenCredits\Credits\Service\Transact;

class User extends XFCP_User
{
    /**
     * @return array[] Active + visible currencies with this user's balances.
     * Each row: currency_id, title, prefix, suffix, decimals, balance.
     */
    public function getOcBalances(): array
    {
        return $this->ocTransact()->balancesFor((int)$this->user_id, true);
    }

    /**
     * @return array[] All active currencies with this user's balances.
     */
    public function getOcAllBalances(): array
    {
        return $this->ocTransact()->balancesFor((int)$this->user_id, false);
    }

    /**
     * @return array|null Primary currency row with balance, or null.
     */
    public function getOcPrimary(): ?array
    {
        foreach ($this->ocTransact()->balancesFor((int)$this->user_id, false) as $row) {
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
