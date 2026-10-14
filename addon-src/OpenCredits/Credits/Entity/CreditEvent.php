<?php

namespace OpenCredits\Credits\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class CreditEvent extends Entity
{
    public const TRIGGER_LABELS = [
        'thread' => 'New thread',
        'post' => 'Reply',
        'reaction_received' => 'Reaction received',
        'register' => 'Registration',
        'daily_login' => 'Daily visit',
    ];

    public function getDisplayLabel(): string
    {
        return self::TRIGGER_LABELS[$this->trigger] ?? ucwords(str_replace('_', ' ', (string)$this->trigger));
    }

    public function getDisplayHint(): string
    {
        $currency = $this->Currency;
        $decimals = $currency ? (int)$currency->decimals : 2;
        $hint = \XF::phrase('ocadm_hint_each', [
            'amount' => number_format((float)$this->amount, $decimals),
        ])->render();
        if ($this->trigger === 'daily_login') {
            $hint .= ' · ' . \XF::phrase('ocadm_hint_daily')->render();
        } elseif ($this->max_per_day > 0) {
            $hint .= ' · ' . \XF::phrase('ocadm_hint_max_x', ['n' => $this->max_per_day])->render();
        } else {
            $hint .= ' · ' . \XF::phrase('ocadm_hint_unlimited')->render();
        }
        if (!$this->active) {
            $hint .= ' · ' . \XF::phrase('ocadm_hint_inactive')->render();
        }
        if (!$this->send_alert) {
            $hint .= ' · ' . \XF::phrase('ocadm_hint_no_alert')->render();
        }
        if (!empty($this->forum_ids) || !empty($this->usergroup_ids)) {
            $hint .= ' · ' . \XF::phrase('ocadm_hint_scoped')->render();
        }
        return $hint;
    }

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_oc_event';
        $structure->shortName = 'OpenCredits\Credits:CreditEvent';
        $structure->primaryKey = 'event_id';
        $structure->columns = [
            'event_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => false],
            'currency_id' => ['type' => self::UINT, 'default' => 1],
            'trigger' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
            'amount' => ['type' => self::FLOAT, 'default' => 0.0],
            'forum_ids' => ['type' => self::JSON_ARRAY, 'nullable' => true, 'default' => null],
            'usergroup_ids' => ['type' => self::JSON_ARRAY, 'nullable' => true, 'default' => null],
            'max_per_day' => ['type' => self::UINT, 'default' => 0],
            'active' => ['type' => self::BOOL, 'default' => true],
            'send_alert' => ['type' => self::BOOL, 'default' => true],
        ];
        $structure->getters = [
            'display_label' => true,
            'display_hint' => true,
        ];
        $structure->relations = [
            'Currency' => [
                'entity' => 'OpenCredits\Credits:Currency',
                'type' => self::TO_ONE,
                'conditions' => 'currency_id',
                'primary' => true,
            ],
        ];
        return $structure;
    }
}
