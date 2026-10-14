<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Admin\Controller;

use OpenCredits\Credits\Entity\CreditEvent as EventEntity;
use XF\Admin\Controller\AbstractController;
use XF\ControllerPlugin\DeletePlugin;
use XF\Mvc\ParameterBag;

class CreditEvent extends AbstractController
{
    public const TRIGGERS = EventEntity::TRIGGER_LABELS;

    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('ocCredits');
        $this->setSectionContext('ocEvents');
    }

    public function actionIndex(): \XF\Mvc\Reply\AbstractReply
    {
        $events = $this->finder('OpenCredits\Credits:CreditEvent')
            ->with('Currency')
            ->order(['currency_id', 'trigger'])
            ->fetch();

        $groups = [];
        foreach ($events as $event) {
            $cid = (int)$event->currency_id;
            if (!isset($groups[$cid])) {
                $groups[$cid] = ['currency' => $event->Currency, 'events' => []];
            }
            $groups[$cid]['events'][] = $event;
        }

        $viewParams = [
            'groups' => $groups,
            'total' => count($events),
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
            'userGroups' => $this->finder('XF:UserGroup')->order('title')->fetch(),
            'eventForumIds' => $this->implodeIds($event->forum_ids),
            'eventGroupIds' => $this->decodeIds($event->usergroup_ids),
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
            'send_alert' => 'bool',
            'forum_ids' => 'str',
            'usergroup_ids' => 'array-uint',
        ]);

        if (!isset(self::TRIGGERS[$input['trigger']])) {
            return $this->error('Unknown event trigger.');
        }
        $currency = $this->em()->find('OpenCredits\Credits:Currency', $input['currency_id']);
        if (!$currency) {
            return $this->error('Please choose an existing currency.');
        }
        if (!$currency->active) {
            return $this->error('Events cannot use an inactive currency. Activate it first.');
        }
        $input['amount'] = round($input['amount'], (int)$currency->decimals);
        if ($input['amount'] == 0.0) {
            return $this->error('Amount cannot be zero. Deactivate the event instead.');
        }
        $input['forum_ids'] = $this->decodeIds($input['forum_ids']);
        $input['usergroup_ids'] = $this->decodeIds($input['usergroup_ids']);

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

    /**
     * Normalizes a comma-separated id string or an id array into a clean
     * int list for the JSON_ARRAY columns. Empty input means unrestricted.
     */
    protected function decodeIds($value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    protected function implodeIds($value): string
    {
        if (is_string($value)) {
            return implode(', ', $this->decodeIds($value));
        }
        if (is_array($value)) {
            return implode(', ', $this->decodeIds(implode(',', $value)));
        }
        return '';
    }
}
