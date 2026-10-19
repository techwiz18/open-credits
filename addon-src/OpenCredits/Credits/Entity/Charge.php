<?php

namespace OpenCredits\Credits\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * One paid unlock: a member paid `amount` of `currency_id` to reveal
 * `[charge=…]` content in a post. Unique per (content, payer, currency,
 * amount), so repeated views never charge twice.
 */
class Charge extends Entity
{
    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_oc_charge';
        $structure->shortName = 'OpenCredits\Credits:Charge';
        $structure->primaryKey = 'charge_id';
        $structure->columns = [
            'charge_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => false],
            'content_type' => ['type' => self::STR, 'maxLength' => 25, 'required' => true],
            'content_id' => ['type' => self::UINT, 'required' => true],
            'user_id' => ['type' => self::UINT, 'required' => true],
            'author_user_id' => ['type' => self::UINT, 'default' => 0],
            'currency_id' => ['type' => self::UINT, 'default' => 1],
            'amount' => ['type' => self::FLOAT, 'required' => true],
            'charge_date' => ['type' => self::UINT, 'default' => 0],
        ];
        $structure->getters = [];
        $structure->relations = [
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => 'user_id',
                'primary' => true,
            ],
        ];
        return $structure;
    }
}
