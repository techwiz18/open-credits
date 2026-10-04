<?php

namespace OpenCredits\Credits\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class Currency extends Entity
{
    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_oc_currency';
        $structure->shortName = 'OpenCredits\Credits:Currency';
        $structure->primaryKey = 'currency_id';
        $structure->columns = [
            'currency_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => false],
            'title' => ['type' => self::STR, 'maxLength' => 100, 'required' => true],
            'prefix' => ['type' => self::STR, 'maxLength' => 25, 'default' => '$'],
            'suffix' => ['type' => self::STR, 'maxLength' => 25, 'default' => ''],
            'decimals' => ['type' => self::UINT, 'default' => 2],
            'allow_negative' => ['type' => self::BOOL, 'default' => true],
            'active' => ['type' => self::BOOL, 'default' => true],
            'is_primary' => ['type' => self::BOOL, 'default' => false],
            'visible' => ['type' => self::BOOL, 'default' => true],
        ];
        $structure->getters = [];
        $structure->relations = [];
        return $structure;
    }

    public function format(float $amount): string
    {
        return $this->prefix . number_format($amount, $this->decimals) . $this->suffix;
    }
}
