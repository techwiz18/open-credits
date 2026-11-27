<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Pub\Controller;

use OpenCredits\Credits\Entity\Currency;
use OpenCredits\Credits\Service\AccountStanding;
use OpenCredits\Credits\Service\ChargeAccess;
use OpenCredits\Credits\Service\ContentCharge;
use OpenCredits\Credits\Service\Ledger;
use OpenCredits\Credits\Service\WalletView;
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
        $view = $this->walletView();
        $currency = $this->viewableCurrency();
        $currencyId = (int)$currency->currency_id;

        // Standing, not just permission: the send form only renders when the
        // transfer endpoint would accept it.
        $canSend = $visitor->hasPermission('general', 'ocTransfer')
            && AccountStanding::canSend((string)$visitor->user_state, !empty($visitor->is_banned));
        $page = $this->filter('page', 'uint');
        $requestedPerPage = (int)$this->filter('per_page', 'uint');
        $perPage = $view->perPage($requestedPerPage ?: null);

        $finder = $this->finder('OpenCredits\Credits:Transaction')
            ->where('user_id', $visitor->user_id)
            ->where('currency_id', $currencyId)
            ->order('log_date', 'DESC')->order('transaction_id', 'DESC');

        $total = $finder->total();
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $transactions = $finder->limitByPage($page, $perPage)->fetch();

        $flags = $view->showFlags($inProfileTab);

        return [
            'currency' => $currency,
            'currencies' => $view->viewable(),
            'showBalance' => $flags['showBalance'],
            'balance' => $view->balanceFor((int)$visitor->user_id, $currencyId),
            'entries' => $this->buildHistoryEntries($transactions),
            'page' => $page,
            'perPage' => $perPage,
            'perPageOptions' => WalletView::PER_PAGE_OPTIONS,
            'total' => $total,
            'canSend' => $canSend,
            'showRedeem' => $flags['showRedeem'],
            'showTransfer' => $flags['showTransfer'],
            'switchUrls' => $view->switchUrls($inProfileTab, (int)$visitor->user_id, $currencyId),
            'formHidden' => $view->formHidden($inProfileTab, (int)$visitor->user_id),
            'amountStep' => $view->step((int)$currency->decimals),
        ] + $view->pager($inProfileTab, (int)$visitor->user_id, $currencyId, $perPage);
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
            if (Ledger::isBackfillNote((string)($txn->note ?? ''))) {
                // Backfill rows aggregate many items; content_id is 0 (or the
                // member id for a per-member run), so never treat it as a link.
                continue;
            }
            if ($txn->trigger === Ledger::TRIGGER_TRANSFER) {
                $userIds[] = (int)$txn->content_id;
            } elseif ($txn->trigger === Ledger::TRIGGER_THREAD
                || ($txn->trigger === Ledger::TRIGGER_REACTION && $txn->content_type === 'thread')
            ) {
                $threadIds[] = (int)$txn->content_id;
            } elseif ($txn->trigger === Ledger::TRIGGER_POST
                || ($txn->trigger === Ledger::TRIGGER_REACTION && $txn->content_type === 'post')
            ) {
                $postIds[] = (int)$txn->content_id;
            } elseif ($txn->trigger === Ledger::TRIGGER_CHARGE && $txn->content_type === 'post') {
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
                'label' => Ledger::label((string)$txn->trigger),
                'detail' => '',
                'detailUser' => null,
                'detailThread' => null,
                'amount' => $amount,
                'logDate' => (int)$txn->log_date,
            ];
            if (Ledger::isBackfillNote((string)($txn->note ?? ''))) {
                $entry['label'] = Ledger::NOTE_BACKFILL;
            } elseif ($txn->trigger === Ledger::TRIGGER_TRANSFER) {
                $entry['label'] = $amount < 0 ? 'Transfer sent' : 'Transfer received';
                $otherId = (int)$txn->content_id;
                if (isset($users[$otherId]) && $users[$otherId]->canViewBasicProfile($profileError)) {
                    $entry['detailUser'] = $users[$otherId];
                }
            } elseif ($txn->trigger === Ledger::TRIGGER_THREAD
                || ($txn->trigger === Ledger::TRIGGER_REACTION && $txn->content_type === 'thread')
            ) {
                $threadId = (int)$txn->content_id;
                if (isset($threads[$threadId]) && $threads[$threadId]->canView()) {
                    $entry['detailThread'] = $threads[$threadId];
                    if ($txn->trigger === Ledger::TRIGGER_REACTION) {
                        $entry['detail'] = 'on';
                    }
                }
            } elseif ($txn->trigger === Ledger::TRIGGER_POST
                || ($txn->trigger === Ledger::TRIGGER_REACTION && $txn->content_type === 'post')
            ) {
                $postId = (int)$txn->content_id;
                if (isset($posts[$postId]) && $posts[$postId]->canView()) {
                    $threadId = (int)$posts[$postId]->thread_id;
                    if (isset($postThreads[$threadId]) && $postThreads[$threadId]->canView()) {
                        $entry['detailThread'] = $postThreads[$threadId];
                        $entry['detail'] = $txn->trigger === Ledger::TRIGGER_REACTION ? 'on your reply in' : 'in reply to';
                    }
                }
            } elseif ($txn->trigger === Ledger::TRIGGER_CHARGE) {
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
        if ($isSelf && $this->walletView()->tabMode(true) === 'wallet') {
            return $this->view(
                'OpenCredits\Credits:Credits\Index',
                'credits_index',
                $this->buildWalletParams(true)
            );
        }

        // Per-member card: balances + a send form locked to this member.
        $view = $this->walletView();
        $currency = $this->viewableCurrency(0);
        $flags = $view->showFlags(false);
        $canSend = $visitor->hasPermission('general', 'ocTransfer')
            && AccountStanding::canSend((string)$visitor->user_state, !empty($visitor->is_banned));
        $lockedTo = $isSelf ? '' : $user->username;
        $targetReceivable = AccountStanding::canReceive(
            (string)$user->user_state,
            !empty($user->is_banned)
        );

        return $this->view(
            'OpenCredits\Credits:Credits\Member',
            'credits_member',
            [
                'user' => $user,
                'isSelf' => $isSelf,
                'currency' => $currency,
                'senderBalance' => $view->balanceFor((int)$visitor->user_id, (int)$currency->currency_id),
                'canSend' => $canSend,
                'lockedTo' => $lockedTo,
                'targetReceivable' => $targetReceivable,
                'showTransfer' => $flags['showTransfer'],
                'amountStep' => $view->step((int)$currency->decimals),
            ]
        );
    }

    protected function walletView(): WalletView
    {
        return new WalletView($this->app);
    }

    /**
     * The requested viewable currency. With fallback disabled an explicit,
     * unknown id is an error; otherwise a missing selection falls back to the
     * primary then the lowest id.
     */
    protected function viewableCurrency(?int $currencyId = null, bool $allowFallback = true): Currency
    {
        if ($currencyId === null) {
            $currencyId = (int)$this->filter('currency_id', 'uint');
        }
        $currency = $this->walletView()->selected($currencyId, $allowFallback);
        if ($currency) {
            return $currency;
        }
        if ($currencyId > 0 && !$allowFallback) {
            throw $this->exception($this->error('Please select a valid currency.'));
        }
        throw $this->exception($this->notFound('No active currencies.'));
    }

    public function actionTransfer(ParameterBag $params): AbstractReply
    {
        $visitor = \XF::visitor();
        if (!$visitor->user_id || !$visitor->hasPermission('general', 'ocView')) {
            return $this->noPermission();
        }
        $currency = $this->viewableCurrency();

        return $this->redirect($this->buildLink('credits', null, [
            'currency_id' => (int)$currency->currency_id,
        ]));
    }

    public function actionTransferSave(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();
        $ocInTab = (bool)$this->filter('oc_in_tab', 'bool');

        $visitor = \XF::visitor();
        if (!$visitor->user_id || !$visitor->hasPermission('general', 'ocView')
            || !$visitor->hasPermission('general', 'ocTransfer')
        ) {
            return $this->noPermission();
        }

        $to = $this->filter('to', 'str');
        $currency = $this->viewableCurrency(null, false);
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
        if (!AccountStanding::canReceive((string)$target->user_state, !empty($target->is_banned))) {
            return $this->error('Credits cannot be transferred to that account.');
        }
        if (!AccountStanding::canSend((string)$visitor->user_state, !empty($visitor->is_banned))) {
            return $this->error('Credits cannot be transferred from that account.');
        }

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        $this->assertNotFlooding('oc_transfer');
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
        if (!$visitor->user_id) {
            return $this->noPermission();
        }
        if (!AccountStanding::canUnlock((string)$visitor->user_state, !empty($visitor->is_banned))) {
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

        // Same verdict as rendering: authors, staff and ocChargeFree holders
        // never pay (even without ocView); everyone else needs ocView.
        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        $charge = $svc->contentCharge();
        $verdict = $charge->access($content, $visitor);
        if ($verdict === ChargeAccess::DENIED) {
            return $this->noPermission();
        }
        if (ChargeAccess::allows($verdict)) {
            return $this->redirect($content->getContentUrl());
        }

        $currencyId = $svc->primaryCurrencyId();
        $currency = $this->em()->find('OpenCredits\Credits:Currency', $currencyId);
        if (!$currency || !$currency->active) {
            return $this->error('This content cannot be unlocked right now.');
        }
        $decimals = (int)$currency->decimals;

        // One purchase unlocks the whole post, so the post's price is the
        // module's decision (highest valid block). Never trust the submitted
        // amount.
        $amount = ContentCharge::priceForMessage((string)$content->message, $decimals);
        if ($amount === null) {
            return $this->error('This content cannot be unlocked right now.');
        }

        $this->assertNotFlooding('oc_unlock');
        try {
            $result = $charge->purchase(
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
        if (!AccountStanding::canEarn((string)$visitor->user_state, !empty($visitor->is_banned))) {
            return $this->noPermission();
        }

        $code = trim($this->filter('code', 'str'));
        if ($code === '') {
            return $this->error('Enter a redeem code.');
        }

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        $this->assertNotFlooding('oc_redeem');
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
                'ineligible' => 'This account cannot redeem codes right now.',
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
