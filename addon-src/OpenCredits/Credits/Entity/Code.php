<?php

namespace OpenCredits\Credits\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class Code extends Entity
{
    public const CODE_LENGTH = 12;
    public const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * Normalizes user input for lookup/storage: uppercase, strip anything
     * outside the code alphabet so "xk7q-..." still matches "XK7Q...".
     */
    public static function normalize(string $code): string
    {
        $code = strtoupper($code);
        return (string)preg_replace('/[^A-Z2-9]/', '', $code);
    }

    /**
     * Generates a random code from the unambiguous alphabet (no 0/O/1/I).
     */
    public static function generate(int $length = self::CODE_LENGTH): string
    {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        return $code;
    }

    public function isExpired(): bool
    {
        return $this->expiry_date > 0 && $this->expiry_date <= \XF::$time;
    }

    public function isExhausted(): bool
    {
        return $this->max_uses > 0 && $this->uses >= $this->max_uses;
    }

    public function getUseLabel(): string
    {
        if ($this->max_uses <= 0) {
            return (int)$this->uses . ' used · unlimited';
        }
        return (int)$this->uses . '/' . (int)$this->max_uses . ' used';
    }

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_oc_code';
        $structure->shortName = 'OpenCredits\Credits:Code';
        $structure->primaryKey = 'code_id';
        $structure->columns = [
            'code_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => false],
            'code' => ['type' => self::STR, 'maxLength' => 32, 'required' => true],
            'currency_id' => ['type' => self::UINT, 'default' => 1],
            'amount' => ['type' => self::FLOAT, 'default' => 0.0],
            'max_uses' => ['type' => self::UINT, 'default' => 1],
            'uses' => ['type' => self::UINT, 'default' => 0],
            'expiry_date' => ['type' => self::UINT, 'default' => 0],
            'active' => ['type' => self::BOOL, 'default' => true],
        ];
        $structure->getters = [
            'use_label' => true,
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
