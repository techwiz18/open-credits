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
        if (!$visitor->user_id) {
            return $this->noPermission();
        }

        $currency = $this->finder('OpenCredits\Credits:Currency')->fetchOne();
        $balance = (float)$visitor->oc_credits;

        $page = $this->filter('page', 'uint');
        $perPage = 20;

        $finder = $this->finder('OpenCredits\Credits:Transaction')
            ->where('user_id', $visitor->user_id)
            ->order('log_date', 'DESC');

        $total = $finder->total();
        $transactions = $finder->limitByPage($page, $perPage)->fetch();

        $viewParams = [
            'currency' => $currency,
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

    public function actionTransfer(ParameterBag $params): AbstractReply
    {
        $visitor = \XF::visitor();
        if (!$visitor->user_id) {
            return $this->noPermission();
        }

        $currency = $this->finder('OpenCredits\Credits:Currency')->fetchOne();

        $viewParams = [
            'currency' => $currency,
            'balance' => (float)$visitor->oc_credits,
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
        if (!$visitor->user_id) {
            return $this->noPermission();
        }

        $to = $this->filter('to', 'str');
        $amount = round($this->filter('amount', 'float'), 2);

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
            $ok = $svc->transfer((int)$visitor->user_id, (int)$target->user_id, $amount, 1);
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits transfer failed: ');
            return $this->error('Transfer failed due to a server error. Please try again.');
        }

        if (!$ok) {
            return $this->error('Transfer could not be completed.');
        }

        return $this->redirect($this->buildLink('credits'));
    }
}
