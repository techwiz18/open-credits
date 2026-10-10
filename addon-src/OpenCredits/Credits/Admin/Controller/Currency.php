<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Admin\Controller;

use OpenCredits\Credits\Entity\Currency as CurrencyEntity;
use XF\Admin\Controller\AbstractController;
use XF\ControllerPlugin\DeletePlugin;
use XF\Mvc\ParameterBag;

class Currency extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('ocCredits');
        $this->setSectionContext('ocCurrencies');
    }

    public function actionIndex(): \XF\Mvc\Reply\AbstractReply
    {
        $viewParams = [
            'currencies' => $this->finder('OpenCredits\Credits:Currency')
                ->order('currency_id')
                ->fetch(),
            'currencyCount' => $this->finder('OpenCredits\Credits:Currency')->total(),
            'eventCount' => $this->finder('OpenCredits\Credits:CreditEvent')->total(),
            'memberCount' => (int)$this->app->db()->fetchOne('SELECT COUNT(DISTINCT user_id) FROM xf_oc_balance'),
            'transactionCount' => (int)$this->app->db()->fetchOne('SELECT COUNT(*) FROM xf_oc_transaction'),
        ];
        return $this->view('OpenCredits\Credits:Currency\Listing', 'oc_currency_list', $viewParams);
    }

    protected function currencyAddEdit(CurrencyEntity $currency): \XF\Mvc\Reply\AbstractReply
    {
        $viewParams = ['currency' => $currency];
        return $this->view('OpenCredits\Credits:Currency\Edit', 'oc_currency_edit', $viewParams);
    }

    public function actionAdd(): \XF\Mvc\Reply\AbstractReply
    {
        $currency = $this->em()->create('OpenCredits\Credits:Currency');
        return $this->currencyAddEdit($currency);
    }

    public function actionEdit(ParameterBag $params): \XF\Mvc\Reply\AbstractReply
    {
        $currency = $this->assertCurrencyExists($params->currency_id);
        return $this->currencyAddEdit($currency);
    }

    public function actionSave(ParameterBag $params): \XF\Mvc\Reply\AbstractReply
    {
        $this->assertPostOnly();

        if ($params->currency_id) {
            $currency = $this->assertCurrencyExists($params->currency_id);
        } else {
            $currency = $this->em()->create('OpenCredits\Credits:Currency');
        }

        $input = $this->filter([
            'title' => 'str',
            'prefix' => 'str',
            'suffix' => 'str',
            'decimals' => 'uint',
            'allow_negative' => 'bool',
            'active' => 'bool',
            'is_primary' => 'bool',
            'visible' => 'bool',
        ]);
        $input['decimals'] = max(0, min(2, $input['decimals']));

        if (!$input['is_primary'] && $currency->is_primary) {
            $otherPrimary = $this->finder('OpenCredits\Credits:Currency')
                ->where('currency_id', '!=', $currency->currency_id ?: 0)
                ->where('is_primary', 1)
                ->fetchOne();
            if (!$otherPrimary && !$currency->isInsert()) {
                return $this->error('There must always be exactly one primary currency. Flag another currency primary first.');
            }
        }

        $form = $this->formAction();
        $form->basicEntitySave($currency, $input);
        $form->apply(function () use ($currency, $input) {
            if ($input['is_primary']) {
                // Exactly one primary: clear the flag everywhere else and
                // resync the legacy balance column to the new primary.
                $this->app->db()->query(
                    'UPDATE xf_oc_currency SET is_primary = 0 WHERE currency_id != ?',
                    $currency->currency_id
                );
                /** @var \OpenCredits\Credits\Service\Transact $svc */
                $svc = $this->service('OpenCredits\Credits:Transact');
                \OpenCredits\Credits\Service\Transact::clearPrimaryCache();
                $svc->rebuildAllBalances();
            }
        });
        $form->run();

        return $this->redirect($this->buildLink('oc-currencies'));
    }

    public function actionDelete(ParameterBag $params): \XF\Mvc\Reply\AbstractReply
    {
        $currency = $this->assertCurrencyExists($params->currency_id);

        if ($currency->is_primary) {
            return $this->error('The primary currency cannot be deleted. Make another currency primary first, or deactivate it instead.');
        }

        $eventCount = $this->finder('OpenCredits\Credits:CreditEvent')
            ->where('currency_id', $currency->currency_id)
            ->total();
        if ($eventCount) {
            return $this->error('This currency still has earning events. Delete or reassign them first.');
        }

        $historyCount = (int)$this->app->db()->fetchOne(
            'SELECT COUNT(*) FROM xf_oc_transaction WHERE currency_id = ?',
            $currency->currency_id
        );
        if ($historyCount) {
            return $this->error('This currency has transaction history. Deactivate it instead of deleting.');
        }

        $codeCount = $this->finder('OpenCredits\Credits:Code')
            ->where('currency_id', $currency->currency_id)
            ->total();
        if ($codeCount) {
            return $this->error('This currency still has redeem codes. Delete or reassign them first.');
        }

        /** @var DeletePlugin $plugin */
        $plugin = $this->plugin(DeletePlugin::class);
        return $plugin->actionDelete(
            $currency,
            $this->buildLink('oc-currencies/delete', $currency),
            $this->buildLink('oc-currencies/edit', $currency),
            $this->buildLink('oc-currencies'),
            $currency->title
        );
    }

    protected function assertCurrencyExists($id, $with = null, $phraseKey = null): CurrencyEntity
    {
        return $this->assertRecordExists('OpenCredits\Credits:Currency', $id, $with, $phraseKey);
    }
}
