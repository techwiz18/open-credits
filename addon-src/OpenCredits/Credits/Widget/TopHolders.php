<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Widget;

use XF\Http\Request;
use XF\Widget\AbstractWidget;

/**
 * Sidebar/forum-home leaderboard: the members with the highest balance in a
 * single currency. One aggregate query over xf_oc_balance plus one user fetch,
 * so it stays cheap regardless of member count.
 */
class TopHolders extends AbstractWidget
{
    protected $defaultOptions = [
        'limit' => 5,
        'currency_id' => 0,
        'show_guests' => 0,
    ];

    protected function getDefaultTemplateParams($context)
    {
        $params = parent::getDefaultTemplateParams($context);
        if ($context === 'options') {
            $params['currencies'] = $this->app->finder('OpenCredits\Credits:Currency')
                ->where('active', 1)
                ->where('visible', 1)
                ->order('currency_id')
                ->fetch();
        }
        return $params;
    }

    public function render()
    {
        $visitor = \XF::visitor();

        // Members need the Credits view permission. Guests are hidden by
        // default, but the widget's "show_guests" option (or granting ocView
        // to the guest group) exposes it.
        $canView = $visitor->user_id
            ? $visitor->hasPermission('general', 'ocView')
            : (!empty($this->options['show_guests']) || $visitor->hasPermission('general', 'ocView'));
        if (!$canView) {
            return '';
        }

        /** @var \OpenCredits\Credits\Service\Transact $transact */
        $transact = $this->app->service('OpenCredits\Credits:Transact');

        $currencyId = (int)($this->options['currency_id'] ?? 0);
        if ($currencyId <= 0) {
            $currencyId = $transact->primaryCurrencyId();
        }

        /** @var \OpenCredits\Credits\Entity\Currency|null $currency */
        $currency = $this->app->em()->find('OpenCredits\Credits:Currency', $currencyId);
        if (!$currency || !$currency->active || !$currency->visible) {
            return '';
        }

        $limit = min(100, max(1, (int)($this->options['limit'] ?? 5)));

        $rows = $this->app->db()->fetchAll(
            'SELECT b.user_id, b.balance
             FROM xf_oc_balance AS b
             INNER JOIN xf_user AS u ON (u.user_id = b.user_id)
             WHERE b.currency_id = ? AND b.balance > 0
               AND u.user_state = ? AND u.is_banned = 0
             ORDER BY b.balance DESC, b.user_id ASC
             LIMIT ' . $limit,
            [$currencyId, 'valid']
        );

        if (!$rows) {
            return '';
        }

        $userIds = [];
        $balances = [];
        foreach ($rows as $row) {
            $userId = (int)$row['user_id'];
            $userIds[] = $userId;
            $balances[$userId] = (float)$row['balance'];
        }

        $users = $this->finder('XF:User')
            ->where('user_id', $userIds)
            ->fetch();

        $holders = [];
        foreach ($userIds as $userId) {
            if (!isset($users[$userId])) {
                continue;
            }
            $holders[] = [
                'user' => $users[$userId],
                'balance' => $balances[$userId],
                'formatted' => $currency->format($balances[$userId]),
            ];
        }

        if (!$holders) {
            return '';
        }

        return $this->renderer('oc_widget_top_holders', [
            'currency' => $currency,
            'holders' => $holders,
        ]);
    }

    public function verifyOptions(Request $request, array &$options, &$error = null)
    {
        $options = $request->filter([
            'limit' => 'uint',
            'currency_id' => 'uint',
            'show_guests' => 'uint',
        ]);
        $options['limit'] = min(100, max(1, (int)$options['limit']));
        $options['show_guests'] = !empty($options['show_guests']) ? 1 : 0;
        return true;
    }
}
