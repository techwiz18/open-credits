<?php

namespace OpenCredits\Credits\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class Transaction extends Entity
{
    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_oc_transaction';
        $structure->shortName = 'OpenCredits\Credits:Transaction';
        $structure->primaryKey = 'transaction_id';
        $structure->columns = [
            'transaction_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => false],
            'user_id' => ['type' => self::UINT, 'required' => true],
            'currency_id' => ['type' => self::UINT, 'default' => 1],
            'amount' => ['type' => self::FLOAT, 'required' => true],
            'trigger' => ['type' => self::STR, 'maxLength' => 50, 'default' => ''],
            'content_type' => ['type' => self::STR, 'maxLength' => 25, 'default' => ''],
            'content_id' => ['type' => self::UINT, 'default' => 0],
            'note' => ['type' => self::STR, 'maxLength' => 255, 'default' => ''],
            'log_date' => ['type' => self::UINT, 'default' => 0],
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
