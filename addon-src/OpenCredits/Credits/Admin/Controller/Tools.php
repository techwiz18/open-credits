<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Admin\Controller;

use OpenCredits\Credits\Service\BackfillRunner;
use OpenCredits\Credits\Service\DemurrageRunner;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class Tools extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('ocCredits');
        $this->setSectionContext('ocTools');
    }

    public function actionIndex(): \XF\Mvc\Reply\AbstractReply
    {
        $tools = [
            [
                'label' => \XF::phrase('ocadm_tool_rebuild_title')->render(),
                'hint' => \XF::phrase('ocadm_tool_rebuild_hint')->render(),
                'link' => $this->buildLink('oc-tools/rebuild-form'),
            ],
            [
                'label' => \XF::phrase('ocadm_tool_backfill_title')->render(),
                'hint' => \XF::phrase('ocadm_tool_backfill_hint')->render(),
                'link' => $this->buildLink('oc-tools/backfill'),
            ],
            [
                'label' => \XF::phrase('ocadm_tool_adjust_title')->render(),
                'hint' => \XF::phrase('ocadm_tool_adjust_hint')->render(),
                'link' => $this->buildLink('oc-tools/adjust'),
            ],
            [
                'label' => \XF::phrase('ocadm_tool_prune_title')->render(),
                'hint' => \XF::phrase('ocadm_tool_prune_hint')->render(),
                'link' => $this->buildLink('oc-tools/prune'),
            ],
            [
                'label' => \XF::phrase('ocadm_tool_demurrage_title')->render(),
                'hint' => \XF::phrase('ocadm_tool_demurrage_hint')->render(),
                'link' => $this->buildLink('oc-tools/demurrage'),
            ],
        ];

        return $this->view(
            'OpenCredits\Credits:Tools\Index',
            'oc_tools_index',
            ['tools' => $tools]
        );
    }

    public function actionRebuildForm(): \XF\Mvc\Reply\AbstractReply
    {
        return $this->view(
            'OpenCredits\Credits:Tools\Rebuild',
            'oc_tools_rebuild',
            ['ran' => false, 'rows' => 0]
        );
    }

    public function actionRebuild(): \XF\Mvc\Reply\AbstractReply
    {
        $this->assertPostOnly();

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        try {
            $rows = $svc->rebuildAllBalancesFully();
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits rebuild failed: ');
            return $this->error('Balance rebuild failed due to a server error. Please try again.');
        }

        return $this->view(
            'OpenCredits\Credits:Tools\Rebuild',
            'oc_tools_rebuild',
            ['ran' => true, 'rows' => $rows]
        );
    }

    public function actionBackfill(): \XF\Mvc\Reply\AbstractReply
    {
        return $this->view(
            'OpenCredits\Credits:Tools\Backfill',
            'oc_tools_backfill',
            [
                'ran' => false,
                'awarded' => 0,
                'skipped' => [],
                'preview' => $this->backfillRunner()->preview(),
            ]
        );
    }

    public function actionBackfillSave(): \XF\Mvc\Reply\AbstractReply
    {
        $this->assertPostOnly();

        $eventIds = $this->filter('event_ids', 'array-uint');
        $username = trim($this->filter('username', 'str'));
        $force = $this->filter('force', 'bool');

        $scopeUserId = 0;
        if (strlen($username)) {
            $target = $this->finder('XF:User')->where('username', $username)->fetchOne();
            if (!$target) {
                return $this->error('User not found. Check the spelling and try again.');
            }
            $scopeUserId = (int)$target->user_id;
        }

        $result = $this->backfillRunner()->run($eventIds, $scopeUserId, $force, 5000);

        switch ($result['verdict']) {
            case 'no_events':
                return $this->error('Select at least one event to backfill.');
            case 'no_eligible_events':
                return $this->error('No eligible events selected. Only active post/thread events can be backfilled.');
            case 'inactive_currencies':
                return $this->error('No eligible events selected. The chosen events use inactive currencies.');
            case 'incomplete':
                return $this->error('Backfill paused part-way. Completed batches are kept — re-run to resume.');
            case 'rebuild_failed':
                return $this->error(
                    'Backfill rows were written, but the balance rebuild failed. '
                    . 'Run AdminCP → Credits → Tools → Rebuild balances to finish.'
                );
        }

        return $this->view(
            'OpenCredits\Credits:Tools\Backfill',
            'oc_tools_backfill',
            [
                'ran' => true,
                'awarded' => $result['awarded'],
                'skipped' => $result['skipped'],
                'preview' => [],
            ]
        );
    }

    protected function backfillRunner(): BackfillRunner
    {
        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');

        return $svc->backfillRunner();
    }

    public function actionAdjust(): \XF\Mvc\Reply\AbstractReply
    {
        $currencies = $this->finder('OpenCredits\Credits:Currency')
            ->where('active', 1)
            ->order('currency_id')
            ->fetch();

        return $this->view(
            'OpenCredits\Credits:Tools\Adjust',
            'oc_tools_adjust',
            [
                'ran' => false,
                'currencies' => $currencies,
                'username' => '',
                'currencyId' => $currencies->first()?->currency_id ?? 1,
                'amount' => '',
                'note' => '',
            ]
        );
    }

    public function actionAdjustSave(): \XF\Mvc\Reply\AbstractReply
    {
        $this->assertPostOnly();

        $username = trim($this->filter('username', 'str'));
        $currencyId = $this->filter('currency_id', 'uint');
        $amount = $this->filter('amount', 'float');
        $note = trim($this->filter('note', 'str'));

        $currencies = $this->finder('OpenCredits\Credits:Currency')
            ->where('active', 1)
            ->order('currency_id')
            ->fetch();

        $viewParams = [
            'ran' => false,
            'currencies' => $currencies,
            'username' => $username,
            'currencyId' => $currencyId,
            'amount' => $this->filter('amount', 'str'),
            'note' => $note,
        ];

        if (!strlen($username)) {
            return $this->error('Enter a username.');
        }
        $target = $this->finder('XF:User')->where('username', $username)->fetchOne();
        if (!$target) {
            return $this->error('User not found. Check the spelling and try again.');
        }
        if ($currencyId <= 0 || !isset($currencies[$currencyId])) {
            return $this->error('Select an active currency.');
        }
        if ($amount == 0.0) {
            return $this->error('Enter a non-zero amount. Positive grants, negative removes.');
        }
        $currency = $currencies[$currencyId];
        if (round($amount, (int)$currency->decimals) == 0.0) {
            return $this->error(
                'That amount is smaller than ' . $currency->title . '\'s smallest unit'
                . ' — enter a larger amount.'
            );
        }

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        try {
            $ok = $svc->adjust(
                (int)$target->user_id,
                $currencyId,
                $amount,
                'admin_adjust',
                0,
                $note !== '' ? $note : 'Admin adjustment'
            );
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits admin adjust failed: ');
            return $this->error('Adjustment failed due to a server error. Please try again.');
        }

        if (!$ok) {
            return $this->error('Adjustment refused — check the currency allows the resulting balance (no overdraft when negatives are disabled).');
        }

        return $this->view(
            'OpenCredits\Credits:Tools\Adjust',
            'oc_tools_adjust',
            array_merge($viewParams, [
                'ran' => true,
                'newBalance' => $svc->getBalance((int)$target->user_id, $currencyId),
                'targetUsername' => $target->username,
            ])
        );
    }

    public function actionDemurrage(): \XF\Mvc\Reply\AbstractReply
    {
        return $this->view(
            'OpenCredits\Credits:Tools\Demurrage',
            'oc_tools_demurrage',
            [
                'ran' => false,
                'preview' => $this->demurrageRunner()->preview(),
                'result' => null,
            ]
        );
    }

    public function actionDemurrageSave(): \XF\Mvc\Reply\AbstractReply
    {
        $this->assertPostOnly();

        $runner = $this->demurrageRunner();
        try {
            // Bounded so a large board cannot exceed the web request limit;
            // an unfinished pass reports its progress and can be resumed.
            $result = $runner->run(500, true, 25);
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits demurrage failed: ');
            return $this->error(
                'Demurrage failed due to a server error. Completed batches are kept — fix the cause and re-run.'
            );
        }

        // A busy first step means another runner owns the pass: report it
        // as ran so the template shows the busy message instead of the
        // form again. An interval skip (not busy) still shows the form.
        $ran = (bool)$result['ran'] || $result['verdict'] === 'busy';
        return $this->view(
            'OpenCredits\Credits:Tools\Demurrage',
            'oc_tools_demurrage',
            [
                'ran' => $ran,
                'busy' => $result['verdict'] === 'busy',
                'preview' => $runner->preview(),
                'result' => $result,
            ]
        );
    }

    protected function demurrageRunner(): DemurrageRunner
    {
        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');

        return $svc->demurrageRunner();
    }

    public function actionPrune(): \XF\Mvc\Reply\AbstractReply
    {
        $retention = (int)$this->app()->options()->ocHistoryRetentionDays;
        $days = $retention > 0 ? $retention : 365;
        return $this->view(
            'OpenCredits\Credits:Tools\Prune',
            'oc_tools_prune',
            [
                'ran' => false,
                'days' => $days,
                'snapshots' => 0,
                'deleted' => 0,
                'preview' => $this->historyPrune()->preview($days),
            ]
        );
    }

    public function actionPruneSave(): \XF\Mvc\Reply\AbstractReply
    {
        $this->assertPostOnly();

        $days = $this->filter('days', 'uint');
        if ($days < 1) {
            return $this->error('Enter at least 1 day.');
        }

        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');
        try {
            $result = $svc->historyPrune()->run($days, 5000);
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits prune failed: ');
            return $this->error('Pruning failed due to a server error. Completed batches are kept — fix the cause and re-run.');
        }

        // Unlock rows the per-post listener missed (bulk-deleted threads) are
        // the charge module's cleanup; ledger rows stay as financial history.
        $svc->contentCharge()->purgeOrphans();

        return $this->view(
            'OpenCredits\Credits:Tools\Prune',
            'oc_tools_prune',
            [
                'ran' => true,
                'days' => $days,
                'snapshots' => $result['snapshots'],
                'deleted' => $result['deleted'],
                'preview' => [],
            ]
        );
    }

    protected function historyPrune(): \OpenCredits\Credits\Service\HistoryPrune
    {
        /** @var \OpenCredits\Credits\Service\Transact $svc */
        $svc = $this->service('OpenCredits\Credits:Transact');

        return $svc->historyPrune();
    }

}
