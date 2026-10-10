<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Pub\Controller\AbstractController;

class Credits extends AbstractController
{
    public function actionIndex(ParameterBag $params): AbstractReply
    {
        $visitor = \XF::visitor();
        if (!$visitor->user_id || !$visitor->hasPermission('general', 'ocView')) {
            return $this->noPermission();
        }

        $currency = $this->assertViewableCurrency($this->filter('currency_id', 'uint'));

        $balance = 0.0;
        foreach ($visitor->oc_all_balances as $row) {
            if ((int)$row['currency_id'] === (int)$currency->currency_id) {
                $balance = (float)$row['balance'];
                break;
            }
        }

        $page = $this->filter('page', 'uint');
        $perPage = 20;

        $finder = $this->finder('OpenCredits\Credits:Transaction')
            ->where('user_id', $visitor->user_id)
            ->where('currency_id', $currency->currency_id)
            ->order('log_date', 'DESC');

        $total = $finder->total();
        $transactions = $finder->limitByPage($page, $perPage)->fetch();

        $viewParams = [
            'currency' => $currency,
            'currencies' => $this->finder('OpenCredits\Credits:Currency')
                ->where('active', 1)
                ->order('currency_id')
                ->fetch(),
            'balance' => $balance,
            'entries' => $this->buildHistoryEntries($transactions),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
        ];

        return $this->view(
            'OpenCredits\Credits:Credits\Index',
            'credits_index',
            $viewParams
        );
    }

    protected const HISTORY_LABELS = [
        'thread' => 'New thread',
        'post' => 'Reply',
        'reaction_received' => 'Reaction received',
        'register' => 'Registration',
        'daily_login' => 'Daily visit',
        'redeem' => 'Redeem code',
    ];

    /**
     * Smallest input step for an amount field, e.g. "1" for 0-decimal
     * currencies, "0.01" for 2-decimal ones.
     */
    protected static function amountStep(int $decimals): string
    {
        return $decimals > 0 ? '0.' . str_repeat('0', $decimals - 1) . '1' : '1';
    }

    /**
     * Turns transaction entities into display rows, resolving counterparties
     * and content links in bulk (no per-row queries).
     */
    protected function buildHistoryEntries(\XF\Mvc\Entity\ArrayCollection $transactions): array
    {
        $userIds = [];
        $threadIds = [];
        $postIds = [];
        foreach ($transactions as $txn) {
            if ($txn->trigger === 'transfer') {
                $userIds[] = (int)$txn->content_id;
            } elseif ($txn->trigger === 'thread'
                || ($txn->trigger === 'reaction_received' && $txn->content_type === 'thread')
            ) {
                $threadIds[] = (int)$txn->content_id;
            } elseif ($txn->trigger === 'post'
                || ($txn->trigger === 'reaction_received' && $txn->content_type === 'post')
            ) {
                $postIds[] = (int)$txn->content_id;
            }
        }

        $users = $userIds
            ? $this->finder('XF:User')->where('user_id', $userIds)->fetch()->toArray()
            : [];
        $threads = $threadIds
            ? $this->finder('XF:Thread')->where('thread_id', $threadIds)->fetch()->toArray()
            : [];
        $posts = $postIds
            ? $this->finder('XF:Post')->where('post_id', $postIds)->fetch()->toArray()
            : [];
        $postThreadIds = [];
        foreach ($posts as $postId => $post) {
            $postThreadIds[] = (int)$post->thread_id;
        }
        $postThreads = $postThreadIds
            ? $this->finder('XF:Thread')->where('thread_id', array_unique($postThreadIds))->fetch()->toArray()
            : [];

        $entries = [];
        foreach ($transactions as $txn) {
            $amount = (float)$txn->amount;
            $entry = [
                'label' => self::HISTORY_LABELS[$txn->trigger] ?? ucwords(str_replace('_', ' ', $txn->trigger)),
                'detail' => '',
                'detailUser' => null,
                'detailThread' => null,
                'amount' => $amount,
                'logDate' => (int)$txn->log_date,
            ];
            if ($txn->trigger === 'transfer') {
                $entry['label'] = $amount < 0 ? 'Transfer sent' : 'Transfer received';
                $otherId = (int)$txn->content_id;
                if (isset($users[$otherId]) && $users[$otherId]->canViewBasicProfile($profileError)) {
                    $entry['detailUser'] = $users[$otherId];
                }
            } elseif ($txn->trigger === 'thread'
                || ($txn->trigger === 'reaction_received' && $txn->content_type === 'thread')
            ) {
                $threadId = (int)$txn->content_id;
                if (isset($threads[$threadId]) && $threads[$threadId]->canView()) {
                    $entry['detailThread'] = $threads[$threadId];
                    if ($txn->trigger === 'reaction_received') {
                        $entry['detail'] = 'on';
                    }
                }
            } elseif ($txn->trigger === 'post'
                || ($txn->trigger === 'reaction_received' && $txn->content_type === 'post')
            ) {
                $postId = (int)$txn->content_id;
                if (isset($posts[$postId]) && $posts[$postId]->canView()) {
                    $threadId = (int)$posts[$postId]->thread_id;
                    if (isset($postThreads[$threadId]) && $postThreads[$threadId]->canView()) {
                        $entry['detailThread'] = $postThreads[$threadId];
                        $entry['detail'] = $txn->trigger === 'reaction_received' ? 'on your reply in' : 'in reply to';
                    }
                }
            }
            $entries[] = $entry;
        }
        return $entries;
    }

    public function actionMember(ParameterBag $params): AbstractReply
    {
        if (!\XF::visitor()->user_id || !\XF::visitor()->hasPermission('general', 'ocView')) {
            return $this->noPermission();
        }
        $user = $this->em()->find('XF:User', $this->filter('user_id', 'uint'));
        if (!$user || !$user->canViewBasicProfile($error)) {
            throw $this->exception($this->notFound($error));
        }

        return $this->view(
            'OpenCredits\Credits:Credits\Member',
            'credits_member',
            ['user' => $user, 'isSelf' => $user->user_id == \XF::visitor()->user_id]
        );
    }

    protected function assertViewableCurrency(int $currencyId, bool $fallback = true): \XF\Mvc\Entity\Entity
    {
        $finder = $this->finder('OpenCredits\Credits:Currency')
            ->where('active', 1)
            ->where('visible', 1);
        if ($currencyId) {
            $currency = (clone $finder)->where('currency_id', $currencyId)->fetchOne();
            if ($currency) {
                return $currency;
            }
            if (!$fallback) {
                throw $this->exception($this->error('Please select a valid currency.'));
            }
        }
        $primary = (clone $finder)->where('is_primary', 1)->fetchOne();
        if ($primary) {
            return $primary;
        }
        $fallback = $finder->order('currency_id')->fetchOne();
        if (!$fallback) {
            throw $this->exception($this->notFound('No active currencies.'));
        }
        return $fallback;
    }

    public function actionTransfer(ParameterBag $params): AbstractReply
    {
        $visitor = \XF::visitor();
        if (!$visitor->user_id || !$visitor->hasPermission('general', 'ocTransfer')) {
            return $this->noPermission();
        }

        $currency = $this->assertViewableCurrency($this->filter('currency_id', 'uint'));

        $balance = 0.0;
        foreach ($visitor->oc_all_balances as $row) {
            if ((int)$row['currency_id'] === (int)$currency->currency_id) {
                $balance = (float)$row['balance'];
                break;
            }
        }

        $viewParams = [
            'currency' => $currency,
            'balance' => $balance,
            'to' => $this->filter('to', 'str'),
            'amountStep' => self::amountStep((int)$currency->decimals),
        ];

        return $this->view(
            'OpenCredits\Credits:Credits\Transfer',
            'credits_transfer',
            $viewParams
        );
    }

    public function actionTransferSave(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();

        $visitor = \XF::visitor();
        if (!$visitor->user_id || !$visitor->hasPermission('general', 'ocTransfer')) {
            return $this->noPermission();
        }

        $to = $this->filter('to', 'str');
        $currency = $this->assertViewableCurrency($this->filter('currency_id', 'uint'), false);
        $amount = round($this->filter('amount', 'float'), (int)$currency->decimals);

        if (!strlen($to)) {
            return $this->error('Please enter a username to send credits to.');
        }
        if ($amount <= 0) {
            return $this->error('Amount must be greater than zero.');
        }

        $target = $this->finder('XF:User')->where('username', $to)->fetchOne();
        if (!$target) {
            return $this->error('User not found. Check the spelling and try again.');
        }
        if ((int)$target->user_id === (int)$visitor->user_id) {
            return $this->error('You cannot transfer credits to yourself.');
        }
        if (in_array($target->user_state, ['banned', 'rejected', 'disabled'], true)) {
            return $this->error('Credits cannot be transferred to that account.');
        }
        if (in_array($visitor->user_state, ['banned', 'rejected', 'disabled'], true)) {
            return $this->error('Credits cannot be transferred from that account.');
        }

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        try {
            $ok = $svc->transfer((int)$visitor->user_id, (int)$target->user_id, $amount, (int)$currency->currency_id);
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits transfer failed: ');
            return $this->error('Transfer failed due to a server error. Please try again.');
        }

        if (!$ok) {
            return $this->error('Insufficient credits for this transfer.');
        }

        return $this->redirect($this->buildLink('credits', null, ['currency_id' => $currency->currency_id]));
    }

    public function actionRedeem(ParameterBag $params): AbstractReply
    {
        $visitor = \XF::visitor();
        if (!$visitor->user_id || !$visitor->hasPermission('general', 'ocView')) {
            return $this->noPermission();
        }

        return $this->view(
            'OpenCredits\Credits:Credits\Redeem',
            'credits_redeem',
            ['ran' => false, 'code' => $this->filter('code', 'str')]
        );
    }

    public function actionRedeemSave(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();

        $visitor = \XF::visitor();
        if (!$visitor->user_id || !$visitor->hasPermission('general', 'ocView')) {
            return $this->noPermission();
        }

        $code = trim($this->filter('code', 'str'));
        if ($code === '') {
            return $this->error('Enter a redeem code.');
        }

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        try {
            $granted = null;
            $status = $svc->redeem((int)$visitor->user_id, $code, $granted);
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits redeem failed: ');
            return $this->error('Redemption failed due to a server error. Please try again.');
        }

        if ($status !== 'ok') {
            $messages = [
                'not_found' => 'That code was not found. Check the spelling and try again.',
                'inactive' => 'That code is no longer active.',
                'expired' => 'That code has expired.',
                'exhausted' => 'That code has already been fully redeemed.',
                'already_redeemed' => 'You have already redeemed that code.',
                'invalid_amount' => 'That code cannot be redeemed right now.',
            ];
            return $this->error($messages[$status] ?? 'That code cannot be redeemed.');
        }

        $currency = $this->em()->find(
            'OpenCredits\Credits:Currency',
            (int)$granted['currency_id']
        );
        if (!$currency) {
            return $this->error('Redemption succeeded but the currency is gone. Contact an administrator.');
        }

        return $this->view(
            'OpenCredits\Credits:Credits\Redeem',
            'credits_redeem',
            [
                'ran' => true,
                'code' => $code,
                'amount' => (float)$granted['amount'],
                'currency' => $currency,
            ]
        );
    }
}
