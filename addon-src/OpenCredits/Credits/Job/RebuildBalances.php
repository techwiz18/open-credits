<?php

namespace OpenCredits\Credits\Job;

use XF\Job\AbstractJob;

/** Rebuilds xf_user.oc_credits from xf_oc_transaction (recovery tool). */
class RebuildBalances extends AbstractJob
{
    public function run($maxRunTime): int
    {
        $db = $this->app->db();
        $db->query('UPDATE xf_user u LEFT JOIN (SELECT user_id, SUM(amount) AS total FROM xf_oc_transaction GROUP BY user_id) t ON t.user_id = u.user_id SET u.oc_credits = COALESCE(t.total, 0)');
        return $this->complete();
    }

    public function getStatusMessage(): string
    {
        return 'Rebuilding OpenCredits balances…';
    }

    public function canCancel(): bool
    {
        return true;
    }

    public function canTriggerByChoice(): bool
    {
        return true;
    }
}
