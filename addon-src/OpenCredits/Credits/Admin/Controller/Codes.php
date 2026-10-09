<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Admin\Controller;

use OpenCredits\Credits\Entity\Code as CodeEntity;
use XF\Admin\Controller\AbstractController;
use XF\ControllerPlugin\DeletePlugin;
use XF\Mvc\ParameterBag;

class Codes extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('ocCredits');
        $this->setSectionContext('ocCodes');
    }

    public function actionIndex(): \XF\Mvc\Reply\AbstractReply
    {
        $codes = $this->finder('OpenCredits\Credits:Code')
            ->with('Currency')
            ->order('code_id', 'DESC')
            ->fetch();

        return $this->view(
            'OpenCredits\Credits:Code\Listing',
            'oc_code_list',
            ['codes' => $codes, 'total' => count($codes)]
        );
    }

    protected function codeAddEdit(CodeEntity $code): \XF\Mvc\Reply\AbstractReply
    {
        if ($code->isInsert() && !$code->code) {
            $code->code = CodeEntity::generate();
        }
        return $this->view(
            'OpenCredits\Credits:Code\Edit',
            'oc_code_edit',
            [
                'code' => $code,
                'currencies' => $this->finder('OpenCredits\Credits:Currency')
                    ->where('active', 1)
                    ->order('currency_id')
                    ->fetch(),
            ]
        );
    }

    public function actionAdd(): \XF\Mvc\Reply\AbstractReply
    {
        return $this->codeAddEdit($this->em()->create('OpenCredits\Credits:Code'));
    }

    public function actionEdit(ParameterBag $params): \XF\Mvc\Reply\AbstractReply
    {
        return $this->codeAddEdit($this->assertCodeExists($params->code_id));
    }

    public function actionSave(ParameterBag $params): \XF\Mvc\Reply\AbstractReply
    {
        $this->assertPostOnly();

        if ($params->code_id) {
            $code = $this->assertCodeExists($params->code_id);
        } else {
            $code = $this->em()->create('OpenCredits\Credits:Code');
        }

        $input = $this->filter([
            'code' => 'str',
            'currency_id' => 'uint',
            'amount' => 'float',
            'max_uses' => 'uint',
            'expiry' => 'str',
            'active' => 'bool',
        ]);

        $normalized = CodeEntity::normalize($input['code']);
        if ($normalized === '') {
            $normalized = CodeEntity::generate();
        }
        $exists = $this->finder('OpenCredits\Credits:Code')
            ->where('code', $normalized)
            ->where('code_id', '!=', (int)$code->code_id)
            ->fetchOne();
        if ($exists) {
            return $this->error('That code already exists. Generate another one.');
        }

        $currency = $this->em()->find('OpenCredits\Credits:Currency', $input['currency_id']);
        if (!$currency) {
            return $this->error('Please choose an existing currency.');
        }
        if (!$currency->active) {
            return $this->error('Codes cannot use an inactive currency. Activate it first.');
        }
        $amount = round($input['amount'], (int)$currency->decimals);
        if ($amount <= 0) {
            return $this->error('Amount must be greater than zero.');
        }

        $expiryInput = trim($input['expiry']);
        $expiryDate = 0;
        if ($expiryInput !== '') {
            $parsed = strtotime($expiryInput);
            if ($parsed === false) {
                return $this->error('Expiry date not understood. Use YYYY-MM-DD or leave blank for no expiry.');
            }
            $expiryDate = (int)$parsed;
        }

        $form = $this->formAction();
        $form->basicEntitySave($code, [
            'code' => $normalized,
            'currency_id' => (int)$currency->currency_id,
            'amount' => $amount,
            'max_uses' => $input['max_uses'],
            'expiry_date' => $expiryDate,
            'active' => $input['active'],
        ]);
        $form->run();

        return $this->redirect($this->buildLink('oc-codes'));
    }

    public function actionDelete(ParameterBag $params): \XF\Mvc\Reply\AbstractReply
    {
        $code = $this->assertCodeExists($params->code_id);

        /** @var DeletePlugin $plugin */
        $plugin = $this->plugin(DeletePlugin::class);
        return $plugin->actionDelete(
            $code,
            $this->buildLink('oc-codes/delete', $code),
            $this->buildLink('oc-codes/edit', $code),
            $this->buildLink('oc-codes'),
            $code->code
        );
    }

    protected function assertCodeExists($id, $with = null, $phraseKey = null): CodeEntity
    {
        return $this->assertRecordExists('OpenCredits\Credits:Code', $id, $with, $phraseKey);
    }
}
