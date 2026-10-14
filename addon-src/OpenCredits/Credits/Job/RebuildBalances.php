<?php

namespace OpenCredits\Credits\Job;

use XF\Job\AbstractJob;

/** Rebuilds xf_oc_balance rows (and the primary xf_user.oc_credits mirror) from xf_oc_transaction, in batches. */
class RebuildBalances extends AbstractJob
{
    public function run($maxRunTime)
    {
        $startTime = microtime(true);
        $next = isset($this->data['nextUserId']) ? (int)$this->data['nextUserId'] : 0;

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->app->service('OpenCredits\Credits:Transact');
        do {
            $result = $svc->rebuildAllBalances($next, 5000);
            $next = $result['next'];
            $this->data['nextUserId'] = $next ?? 0;
            $this->data['rows'] = (int)($this->data['rows'] ?? 0) + $result['rows'];
        } while ($next !== null && microtime(true) - $startTime < $maxRunTime);

        if ($next !== null) {
            return $this->resume();
        }
        return $this->complete();
    }

    public function getStatusMessage(): string
    {
        $rows = (int)($this->data['rows'] ?? 0);
        return "Rebuilding OpenCredits balances… ({$rows} rows so far)";
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
