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
                $currency = $event->Currency;
                $decimals = $currency ? (int)$currency->decimals : 2;
                $groups[$cid] = [
                    'currency' => $currency,
                    // Preformatted sample unit: template filters reject literals.
                    'sample' => ($currency ? $currency->prefix : '')
                        . number_format(1, $decimals)
                        . ($currency ? $currency->suffix : ''),
                    'events' => [],
                ];
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
            'eventGroupIds' => EventEntity::decodeIdList($event->usergroup_ids),
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
        if ($input['amount'] <= 0.0) {
            return $this->error('Amount must be greater than zero. Deactivate the event instead.');
        }
        $duplicate = $this->finder('OpenCredits\Credits:CreditEvent')
            ->where('trigger', $input['trigger'])
            ->where('currency_id', (int)$currency->currency_id)
            ->where('active', 1);
        if (!$event->isInsert()) {
            $duplicate = $duplicate->where('event_id', '!=', (int)$event->event_id);
        }
        if ($duplicate->fetchOne()) {
            return $this->error(
                'An active event for this trigger and currency already exists. Edit that one instead — duplicates would pay out twice.'
            );
        }
        $input['forum_ids'] = EventEntity::decodeIdList($input['forum_ids']);
        $input['usergroup_ids'] = EventEntity::decodeIdList($input['usergroup_ids']);

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
     * Displays stored ids as a comma-separated string for the edit form.
     */
    protected function implodeIds($value): string
    {
        if (is_string($value)) {
            return implode(', ', EventEntity::decodeIdList($value));
        }
        if (is_array($value)) {
            return implode(', ', EventEntity::decodeIdList(implode(',', $value)));
        }
        return '';
    }
}
