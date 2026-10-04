<?php

namespace OpenCredits\Credits\XF\Entity;

class User extends XFCP_User
{
    protected static $ocBalanceCache = [];

    /**
     * @return array[] Active + visible currencies with this user's balances.
     * Each row: currency_id, title, prefix, suffix, decimals, balance.
     */
    public function getOcBalances(): array
    {
        return $this->fetchOcBalances(true);
    }

    /**
     * @return array[] All active currencies with this user's balances.
     */
    public function getOcAllBalances(): array
    {
        return $this->fetchOcBalances(false);
    }

    /**
     * @return array|null Primary currency row with balance, or null.
     */
    public function getOcPrimary(): ?array
    {
        foreach ($this->fetchOcBalances(false) as $row) {
            if (!empty($row['is_primary'])) {
                return $row;
            }
        }
        return null;
    }

    protected function fetchOcBalances(bool $visibleOnly): array
    {
        if (!$this->user_id) {
            return [];
        }
        $key = $this->user_id . ':' . ($visibleOnly ? 'v' : 'a');
        if (!isset(self::$ocBalanceCache[$key])) {
            self::$ocBalanceCache[$key] = $this->db()->fetchAll(
                'SELECT c.currency_id, c.title, c.prefix, c.suffix, c.decimals, c.is_primary,'
                . ' COALESCE(b.balance, 0) AS balance'
                . ' FROM xf_oc_currency AS c'
                . ' LEFT JOIN xf_oc_balance AS b ON b.currency_id = c.currency_id AND b.user_id = ?'
                . ' WHERE c.active = 1' . ($visibleOnly ? ' AND c.visible = 1' : '')
                . ' ORDER BY c.currency_id',
                $this->user_id
            );
        }
        return self::$ocBalanceCache[$key];
    }

    public static function clearOcBalanceCache(?int $userId = null): void
    {
        if ($userId === null) {
            self::$ocBalanceCache = [];
        } else {
            unset(self::$ocBalanceCache[$userId . ':v'], self::$ocBalanceCache[$userId . ':a']);
        }
    }
}
