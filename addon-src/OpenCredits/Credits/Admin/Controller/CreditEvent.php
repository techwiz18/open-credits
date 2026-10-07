<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Admin\Controller;

use OpenCredits\Credits\Entity\CreditEvent as EventEntity;
use XF\Admin\Controller\AbstractController;
use XF\ControllerPlugin\DeletePlugin;
use XF\Mvc\ParameterBag;

class CreditEvent extends AbstractController
{
    public const TRIGGERS = [
        'thread' => 'New thread',
        'post' => 'Reply',
        'reaction_received' => 'Content reacted to',
        'register' => 'Registration',
        'daily_login' => 'Daily visit',
    ];

    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('ocCredits');
        $this->setSectionContext('ocEvents');
    }

    public function actionIndex(): \XF\Mvc\Reply\AbstractReply
    {
        $viewParams = [
            'events' => $this->finder('OpenCredits\Credits:CreditEvent')
                ->with('Currency')
                ->order(['currency_id', 'trigger'])
                ->fetch(),
            'triggers' => self::TRIGGERS,
        ];
        return $this->view('OpenCredits\Credits:Event\Listing', 'oc_event_list', $viewParams);
    }

    protected function eventAddEdit(EventEntity $event): \XF\Mvc\Reply\AbstractReply
    {
        $viewParams = [
            'event' => $event,
            'currencies' => $this->finder('OpenCredits\Credits:Currency')
                ->where('active', 1)
                ->order('currency_id')
                ->fetch(),
            'triggers' => self::TRIGGERS,
        ];
        return $this->view('OpenCredits\Credits:Event\Edit', 'oc_event_edit', $viewParams);
    }

    public function actionAdd(): \XF\Mvc\Reply\AbstractReply
    {
        $event = $this->em()->create('OpenCredits\Credits:CreditEvent');
        return $this->eventAddEdit($event);
    }

    public function actionEdit(ParameterBag $params): \XF\Mvc\Reply\AbstractReply
    {
        $event = $this->assertEventExists($params->event_id);
        return $this->eventAddEdit($event);
    }

    public function actionSave(ParameterBag $params): \XF\Mvc\Reply\AbstractReply
    {
        $this->assertPostOnly();

        if ($params->event_id) {
            $event = $this->assertEventExists($params->event_id);
        } else {
            $event = $this->em()->create('OpenCredits\Credits:CreditEvent');
        }

        $input = $this->filter([
            'currency_id' => 'uint',
            'trigger' => 'str',
            'amount' => 'float',
            'max_per_day' => 'uint',
            'active' => 'bool',
        ]);
        $input['amount'] = round($input['amount'], 2);

        if (!isset(self::TRIGGERS[$input['trigger']])) {
            return $this->error('Unknown event trigger.');
        }
        if (!$input['currency_id']) {
            return $this->error('Please choose a currency.');
        }

        $form = $this->formAction();
        $form->basicEntitySave($event, $input);
        $form->run();

        return $this->redirect($this->buildLink('oc-events'));
    }

    public function actionDelete(ParameterBag $params): \XF\Mvc\Reply\AbstractReply
    {
        $event = $this->assertEventExists($params->event_id);

        /** @var DeletePlugin $plugin */
        $plugin = $this->plugin(DeletePlugin::class);
        return $plugin->actionDelete(
            $event,
            $this->buildLink('oc-events/delete', $event),
            $this->buildLink('oc-events/edit', $event),
            $this->buildLink('oc-events'),
            self::TRIGGERS[$event->trigger] ?? $event->trigger
        );
    }

    protected function assertEventExists($id, $with = null, $phraseKey = null): EventEntity
    {
        return $this->assertRecordExists('OpenCredits\Credits:CreditEvent', $id, $with, $phraseKey);
    }
}
