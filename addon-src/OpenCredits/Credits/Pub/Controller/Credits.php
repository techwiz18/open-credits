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
            'transactions' => $transactions,
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

    public function actionMember(ParameterBag $params): AbstractReply
    {
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

    protected function assertViewableCurrency(int $currencyId): \XF\Mvc\Entity\Entity
    {
        $finder = $this->finder('OpenCredits\Credits:Currency')->where('active', 1);
        if ($currencyId) {
            $currency = (clone $finder)->where('currency_id', $currencyId)->fetchOne();
            if ($currency) {
                return $currency;
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
        $amount = round($this->filter('amount', 'float'), 2);
        $currency = $this->assertViewableCurrency($this->filter('currency_id', 'uint'));

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
}
