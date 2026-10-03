<?php

namespace OpenCredits\Credits\Job;

use XF\Job\AbstractJob;

/** Rebuilds xf_user.oc_credits from xf_oc_transaction (recovery tool). */
class RebuildBalances extends AbstractJob
{
    public function run($maxRunTime): int
    {
        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->app->service('OpenCredits\Credits:Transact');
        $svc->rebuildAllBalances();
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
