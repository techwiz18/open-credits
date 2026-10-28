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

    /**
     * Normalizes a JSON id list (as stored by the JSON_ARRAY columns), a
     * comma-separated id string (as submitted by admin forms), or an id
     * array into a clean int list. Empty input means unrestricted.
     */
    public static function decodeIdList($value): array
    {
        if (is_array($value)) {
            $ids = $value;
        } elseif (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $ids = is_array($decoded) ? $decoded : explode(',', $value);
        } else {
            return [];
        }
        return array_values(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        }));
    }

    protected function getDayBoundaryLabel(): string
    {
        $mode = 'utc';
        try {
            $mode = (string)(\XF::app()->options()->ocDayBoundary ?? 'utc');
        } catch (\Throwable $e) {
            $mode = 'utc';
        }
        if (!in_array($mode, ['utc', 'board', 'fixed'], true)) {
            $mode = 'utc';
        }
        return \XF::phrase('ocadm_day_' . $mode)->render();
    }

    public function getDisplayHint(): string
    {
        $currency = $this->Currency;
        $decimals = $currency ? (int)$currency->decimals : 2;
        $hint = \XF::phrase('ocadm_hint_each', [
            'amount' => number_format((float)$this->amount, $decimals),
        ])->render();
        if ($this->trigger === 'daily_login') {
            $hint .= ' · ' . \XF::phrase('ocadm_hint_daily')->render() . ' (' . $this->getDayBoundaryLabel() . ')';
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
