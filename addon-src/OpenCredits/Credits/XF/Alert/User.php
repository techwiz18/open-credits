<?php

namespace OpenCredits\Credits\XF\Alert;

class User extends XFCP_User
{
    public function getOptOutActions()
    {
        return array_merge(parent::getOptOutActions(), ['oc_earn']);
    }
}
