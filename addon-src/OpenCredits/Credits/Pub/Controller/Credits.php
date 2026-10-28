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

        return $this->view(
            'OpenCredits\Credits:Credits\Index',
            'credits_index',
            $this->buildWalletParams()
        );
    }

    /**
     * View params for the wallet page: balance, compact actions, paged history.
     */
    protected function buildWalletParams(bool $inProfileTab = false): array
    {
        $visitor = \XF::visitor();
        $currency = $this->assertViewableCurrency($this->filter('currency_id', 'uint'));

        $balance = 0.0;
        foreach ($visitor->oc_all_balances as $row) {
            if ((int)$row['currency_id'] === (int)$currency->currency_id) {
                $balance = (float)$row['balance'];
                break;
            }
        }

        $canSend = $visitor->hasPermission('general', 'ocTransfer');
        $page = $this->filter('page', 'uint');

        $perPageOptions = [10, 20, 50];
        $requestedPerPage = $this->filter('per_page', 'uint');
        if ($requestedPerPage && in_array($requestedPerPage, $perPageOptions, true)) {
            $perPage = $requestedPerPage;
            \XF::app()->session()->set('ocWalletPerPage', $perPage);
        } else {
            $perPage = (int)\XF::app()->session()->get('ocWalletPerPage');
            if (!in_array($perPage, $perPageOptions, true)) {
                $perPage = 10;
            }
        }

        $finder = $this->finder('OpenCredits\Credits:Transaction')
            ->where('user_id', $visitor->user_id)
            ->where('currency_id', $currency->currency_id)
            ->order('log_date', 'DESC')->order('transaction_id', 'DESC');

        $total = $finder->total();
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $transactions = $finder->limitByPage($page, $perPage)->fetch();

        $currencies = $this->finder('OpenCredits\Credits:Currency')
            ->where('active', 1)
            ->where('visible', 1)
            ->order('currency_id')
            ->fetch();

        // When the wallet is embedded in the profile tab, paging and per-page
        // links go through the member route so they can reload the pane.
        $pagerLink = $inProfileTab ? 'credits/member' : 'credits';
        $pagerBaseParams = $inProfileTab
            ? ['user_id' => (int)$visitor->user_id, 'currency_id' => (int)$currency->currency_id]
            : ['currency_id' => (int)$currency->currency_id];
        $perPageUrls = [];
        foreach ($perPageOptions AS $option) {
            $perPageUrls[$option] = $this->buildLink(
                $pagerLink,
                null,
                $pagerBaseParams + ['per_page' => $option]
            );
        }

        return [
            'currency' => $currency,
            'currencies' => $currencies,
            'showBalance' => !$inProfileTab || count($currencies) > 1
                || !\XF::app()->options()->ocShowProfileStats,
            'balance' => $balance,
            'entries' => $this->buildHistoryEntries($transactions),
            'page' => $page,
            'perPage' => $perPage,
            'perPageOptions' => $perPageOptions,
            'total' => $total,
            'canSend' => $canSend,
            'showRedeem' => (bool)\XF::app()->options()->ocShowWalletRedeem,
            'showTransfer' => (bool)\XF::app()->options()->ocShowTransfer,
            'inProfileTab' => $inProfileTab,
            'returnUserId' => (int)$visitor->user_id,
            'pagerLink' => $pagerLink,
            'pagerParams' => $pagerBaseParams + ['per_page' => $perPage],
            'perPageUrls' => $perPageUrls,
            'amountStep' => self::amountStep((int)$currency->decimals),
        ];
    }

    protected const HISTORY_LABELS = [
        'thread' => 'New thread',
        'post' => 'Reply',
        'reaction_received' => 'Reaction received',
        'register' => 'Registration',
        'daily_login' => 'Daily visit',
        'redeem' => 'Redeem code',
        'charge' => 'Premium content',
        'demurrage' => 'Demurrage',
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
            if (($txn->note ?? '') === 'Historical backfill') {
                // Backfill rows aggregate many items; content_id is 0 (or the
                // member id for a per-member run), so never treat it as a link.
                continue;
            }
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
            } elseif ($txn->trigger === 'charge' && $txn->content_type === 'post') {
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
            if (($txn->note ?? '') === 'Historical backfill') {
                $entry['label'] = 'Historical backfill';
            } elseif ($txn->trigger === 'transfer') {
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
            } elseif ($txn->trigger === 'charge') {
                $entry['label'] = $amount < 0 ? 'Premium content unlocked' : 'Premium content sold';
                $postId = (int)$txn->content_id;
                if (isset($posts[$postId]) && $posts[$postId]->canView()) {
                    $threadId = (int)$posts[$postId]->thread_id;
                    if (isset($postThreads[$threadId]) && $postThreads[$threadId]->canView()) {
                        $entry['detailThread'] = $postThreads[$threadId];
                        $entry['detail'] = 'in';
                    }
                }
            }
            $entries[] = $entry;
        }
        return $entries;
    }

    public function actionMember(ParameterBag $params): AbstractReply
    {
        $visitor = \XF::visitor();
        if (!$visitor->user_id || !$visitor->hasPermission('general', 'ocView')) {
            return $this->noPermission();
        }
        if (!\XF::app()->options()->ocShowProfileTab) {
            return $this->noPermission();
        }
        $user = $this->em()->find('XF:User', $this->filter('user_id', 'uint'));
        if (!$user || !$user->canViewFullProfile($error)) {
            throw $this->exception($this->notFound($error));
        }
        $isSelf = (int)$user->user_id === (int)$visitor->user_id;

        // Profile tab mode: auto (wallet on single-currency forums), wallet, or member.
        if ($isSelf) {
            $mode = (string)\XF::app()->options()->ocProfileTabMode;
            $useWallet = false;
            if ($mode === 'wallet') {
                $useWallet = true;
            } elseif ($mode !== 'member') {
                $visibleCurrencyCount = $this->finder('OpenCredits\Credits:Currency')
                    ->where('active', 1)
                    ->where('visible', 1)
                    ->total();
                $useWallet = $visibleCurrencyCount <= 1;
            }

            if ($useWallet) {
                return $this->view(
                    'OpenCredits\Credits:Credits\Index',
                    'credits_index',
                    $this->buildWalletParams(true)
                );
            }
        }

        // Per-member card: balances + a send form locked to this member.
        $currency = $this->assertViewableCurrency(0);
        $canSend = $visitor->hasPermission('general', 'ocTransfer');
        $lockedTo = $isSelf ? '' : $user->username;
        $targetReceivable = !$user->is_banned
            && !in_array($user->user_state, ['rejected', 'disabled'], true);

        $senderBalance = 0.0;
        foreach ($visitor->oc_all_balances as $row) {
            if ((int)$row['currency_id'] === (int)$currency->currency_id) {
                $senderBalance = (float)$row['balance'];
                break;
            }
        }

        return $this->view(
            'OpenCredits\Credits:Credits\Member',
            'credits_member',
            [
                'user' => $user,
                'isSelf' => $isSelf,
                'currency' => $currency,
                'senderBalance' => $senderBalance,
                'canSend' => $canSend,
                'lockedTo' => $lockedTo,
                'targetReceivable' => $targetReceivable,
                'showTransfer' => (bool)\XF::app()->options()->ocShowTransfer,
                'amountStep' => self::amountStep((int)$currency->decimals),
            ]
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
        if (!$visitor->user_id || !$visitor->hasPermission('general', 'ocView')) {
            return $this->noPermission();
        }
        $currency = $this->assertViewableCurrency($this->filter('currency_id', 'uint'));

        return $this->redirect($this->buildLink('credits', null, [
            'currency_id' => (int)$currency->currency_id,
        ]));
    }

    public function actionTransferSave(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();
        $ocInTab = (bool)$this->filter('oc_in_tab', 'bool');

        $visitor = \XF::visitor();
        if (!$visitor->user_id || $visitor->is_banned || !$visitor->hasPermission('general', 'ocView')
            || !$visitor->hasPermission('general', 'ocTransfer')
        ) {
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
        if ($target->is_banned || in_array($target->user_state, ['rejected', 'disabled'], true)) {
            return $this->error('Credits cannot be transferred to that account.');
        }
        if ($visitor->is_banned || in_array($visitor->user_state, ['rejected', 'disabled'], true)) {
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

        if ($ocInTab) {
            $returnId = (int)$this->filter('oc_return_user_id', 'uint');
            $returnUser = $returnId > 0 ? $this->em()->find('XF:User', $returnId) : $target;
            if ($returnUser) {
                return $this->redirect($this->buildLink('members', $returnUser, ['currency_id' => (int)$currency->currency_id]) . '#credits');
            }
        }
        return $this->redirect($this->buildLink('credits', null, ['currency_id' => $currency->currency_id]));
    }

    /**
     * POST target for the [charge] BBCode unlock form. Charges the viewer and
     * credits the post author, then redirects back to the content.
     */
    public function actionUnlock(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();

        $visitor = \XF::visitor();
        if (!$visitor->user_id || !$visitor->hasPermission('general', 'ocView')) {
            return $this->noPermission();
        }
        if ($visitor->is_banned
            || in_array($visitor->user_state, ['rejected', 'disabled', 'email_confirm', 'email_confirm_edit'], true)
        ) {
            return $this->noPermission();
        }

        $contentType = $this->filter('content_type', 'str');
        $contentId = (int)$this->filter('content_id', 'uint');

        $entityClass = ['post' => 'XF:Post'][$contentType] ?? null;
        if (!$entityClass || $contentId <= 0) {
            return $this->error('Invalid unlock request.');
        }

        $content = $this->em()->find($entityClass, $contentId);
        if (!$content || !$content->canView()) {
            return $this->notFound();
        }

        $authorId = (int)$content->user_id;
        if ($authorId <= 0) {
            return $this->error('This content cannot be unlocked.');
        }

        // Authors, staff, and bypass-permission holders never pay, even via a
        // crafted POST.
        if ((int)$visitor->user_id === $authorId
            || $visitor->is_staff
            || $visitor->is_admin
            || $visitor->is_moderator
            || $visitor->hasPermission('general', 'ocChargeFree')) {
            return $this->redirect($content->getContentUrl());
        }

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        $currencyId = $svc->primaryCurrencyId();
        $currency = $this->em()->find('OpenCredits\Credits:Currency', $currencyId);
        if (!$currency || !$currency->active) {
            return $this->error('This content cannot be unlocked right now.');
        }
        $decimals = (int)$currency->decimals;

        // One purchase unlocks the whole post, so the post's price is the
        // most expensive valid block. Never trust the submitted amount.
        $amount = \OpenCredits\Credits\BbCode\Charge::maxAmount(
            (string)$content->message,
            $decimals
        );
        if ($amount === null) {
            return $this->error('This content cannot be unlocked right now.');
        }

        try {
            $result = $svc->chargeForContent(
                (int)$visitor->user_id,
                $authorId,
                $currencyId,
                $amount,
                $contentType,
                $contentId
            );
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits unlock failed: ');
            return $this->error('Unlock failed due to a server error. Please try again.');
        }

        if ($result === 'no_funds') {
            return $this->error('You do not have enough credits to unlock this content.');
        }
        if (!in_array($result, ['ok', 'already_paid', 'self'], true)) {
            return $this->error('This content cannot be unlocked right now.');
        }

        \OpenCredits\Credits\BbCode\Charge::clearPaidCache();
        return $this->redirect($content->getContentUrl());
    }

    public function actionRedeem(ParameterBag $params): AbstractReply
    {
        $visitor = \XF::visitor();
        if (!$visitor->user_id || !$visitor->hasPermission('general', 'ocView')) {
            return $this->noPermission();
        }

        return $this->redirect($this->buildLink('credits'));
    }

    public function actionRedeemSave(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();
        $ocInTab = (bool)$this->filter('oc_in_tab', 'bool');
        $ocReturnUserId = (int)$this->filter('oc_return_user_id', 'uint');

        $visitor = \XF::visitor();
        if (!$visitor->user_id || !$visitor->hasPermission('general', 'ocView')) {
            return $this->noPermission();
        }
        if ($visitor->is_banned
            || in_array($visitor->user_state, ['rejected', 'disabled', 'email_confirm', 'email_confirm_edit'], true)
        ) {
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
                'capped' => 'That code would put you over the balance cap. Spend some credits and try again.',
            ];
            return $this->error($messages[$status] ?? 'That code cannot be redeemed.');
        }

        $redirect = $this->buildLink('credits', null, [
            'currency_id' => (int)$granted['currency_id'],
        ]);
        if ($ocInTab) {
            $returnUser = $ocReturnUserId > 0 ? $this->em()->find('XF:User', $ocReturnUserId) : null;
            if ($returnUser) {
                $redirect = $this->buildLink('members', $returnUser, [
                    'currency_id' => (int)$granted['currency_id'],
                ]) . '#credits';
            }
        }
        return $this->redirect($redirect);
    }
}
