<?php

namespace OpenCredits\Credits\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class CreditEvent extends Entity
{
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
        ];
        $structure->getters = [];
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
