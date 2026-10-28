<?php

namespace OpenCredits\Credits;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\Db\Schema\Create;
use XF\Db\Schema\Alter;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1()
    {
        $this->createTable('xf_oc_currency', function (Create $table) {
            $table->addColumn('currency_id', 'int')->autoIncrement();
            $table->addColumn('title', 'varchar', 100);
            $table->addColumn('prefix', 'varchar', 25)->setDefault('$');
            $table->addColumn('suffix', 'varchar', 25)->setDefault('');
            $table->addColumn('decimals', 'tinyint')->setDefault(2);
            $table->addColumn('allow_negative', 'tinyint')->setDefault(1);
            $table->addColumn('active', 'tinyint')->setDefault(1);
            $table->addColumn('is_primary', 'tinyint')->setDefault(0);
            $table->addColumn('visible', 'tinyint')->setDefault(1);
        });
    }

    public function installStep2()
    {
        $this->createTable('xf_oc_event', function (Create $table) {
            $table->addColumn('event_id', 'int')->autoIncrement();
            $table->addColumn('currency_id', 'int')->setDefault(1);
            $table->addColumn('trigger', 'varchar', 50);
            $table->addColumn('amount', 'decimal', '10,2')->unsigned(false)->setDefault('0.00');
            $table->addColumn('forum_ids', 'blob')->nullable();
            $table->addColumn('usergroup_ids', 'blob')->nullable();
            $table->addColumn('max_per_day', 'int')->setDefault(0);
            $table->addColumn('active', 'tinyint')->setDefault(1);
            $table->addColumn('send_alert', 'tinyint')->setDefault(1);
            $table->addKey(['trigger', 'active']);
        });
    }

    public function installStep3()
    {
        $this->createTable('xf_oc_transaction', function (Create $table) {
            $table->addColumn('transaction_id', 'int')->autoIncrement();
            $table->addColumn('user_id', 'int');
            $table->addColumn('currency_id', 'int')->setDefault(1);
            $table->addColumn('amount', 'decimal', '10,2')->unsigned(false);
            $table->addColumn('trigger', 'varchar', 50);
            $table->addColumn('content_type', 'varchar', 25)->setDefault('');
            $table->addColumn('content_id', 'int')->setDefault(0);
            $table->addColumn('event_id', 'int')->setDefault(0);
            $table->addColumn('note', 'varchar', 255)->setDefault('');
            $table->addColumn('log_date', 'int');
            $table->addKey(['user_id', 'log_date']);
            $table->addKey(['user_id', 'currency_id', 'log_date'], 'user_currency_date');
            $table->addKey(['user_id', 'trigger', 'content_id', 'content_type'], 'user_trigger_content');
            $table->addKey(['user_id', 'trigger', 'log_date'], 'user_trigger_date');
            $table->addKey(['log_date'], 'log_date');
            $table->addKey(['event_id'], 'event_id');
        });
    }

    public function installStep4()
    {
        $this->schemaManager()->alterTable('xf_user', function (Alter $table) {
            $table->addColumn('oc_credits', 'decimal', '10,2')->setDefault('0.00');
        });
    }

    public function installStep5()
    {
        // Seed default currency: Credits, $, 2 decimals, negatives allowed
        $this->db()->insert('xf_oc_currency', [
            'currency_id' => 1,
            'title' => 'Credits',
            'prefix' => '$',
            'suffix' => '',
            'decimals' => 2,
            'allow_negative' => 1,
            'active' => 1,
            'is_primary' => 1,
            'visible' => 1,
        ], false, 'currency_id = VALUES(currency_id)');
    }

    public function installStep6()
    {
        $this->seedDefaultEvents();
    }

    public function installStep7()
    {
        $this->createTable('xf_oc_balance', function (Create $table) {
            $table->addColumn('user_id', 'int');
            $table->addColumn('currency_id', 'int')->setDefault(1);
            $table->addColumn('balance', 'decimal', '10,2')->unsigned(false)->setDefault('0.00');
            $table->addPrimaryKey(['user_id', 'currency_id']);
        });
    }

    public function installStep8()
    {
        $this->grantRegisteredDefaults();
    }

    public function installStep9()
    {
        $this->createCodeTable();
    }

    public function installStep10()
    {
        $this->createSnapshotTable();
    }

    public function installStep11()
    {
        $this->addBalanceLeaderboardIndex();
    }

    public function installStep12()
    {
        $this->createChargeTable();
    }

    public function installStep13()
    {
        $this->syncEditorButton(true);
    }

    public function installStep14()
    {
        $this->createBackfillTable();
    }

    public function installStep15()
    {
        $this->createInflationTable();
    }

    public function installStep16()
    {
        $this->createFaucetTable();
    }

    public function installStep17()
    {
        $this->createDailyClaimTable();
    }

    public function uninstallStep1()
    {
        $sm = $this->schemaManager();
        $sm->dropTable('xf_oc_currency');
        $sm->dropTable('xf_oc_event');
        $sm->dropTable('xf_oc_transaction');
        $sm->dropTable('xf_oc_balance');
        $sm->dropTable('xf_oc_code');
        $sm->dropTable('xf_oc_snapshot');
        $sm->dropTable('xf_oc_charge');
        $sm->dropTable('xf_oc_backfill');
        $sm->dropTable('xf_oc_inflation');
        $sm->dropTable('xf_oc_faucet');
        $sm->dropTable('xf_oc_daily_claim');
    }

    public function uninstallStep2()
    {
        $this->schemaManager()->alterTable('xf_user', function (Alter $table) {
            $table->dropColumns('oc_credits');
        });
    }

    public function uninstallStep3()
    {
        $db = $this->db();
        $db->delete('xf_permission_entry', "permission_group_id = 'general' AND permission_id IN ('ocView', 'ocTransfer', 'ocChargeFree')");
        $db->delete('xf_admin_permission_entry', "admin_permission_id = 'ocCredits'");
    }

    public function uninstallStep4()
    {
        $this->syncEditorButton(false);
    }

    public function upgrade900Step1()
    {
        $this->createTable('xf_oc_balance', function (Create $table) {
            $table->addColumn('user_id', 'int');
            $table->addColumn('currency_id', 'int')->setDefault(1);
            $table->addColumn('balance', 'decimal', '10,2')->unsigned(false)->setDefault('0.00');
            $table->addPrimaryKey(['user_id', 'currency_id']);
        });
        $this->schemaManager()->alterTable('xf_oc_currency', function (Alter $table) {
            $table->addColumn('is_primary', 'tinyint')->setDefault(0);
            $table->addColumn('visible', 'tinyint')->setDefault(1);
        });
        // Currency 1 stays the primary, visible default
        $this->db()->query('UPDATE xf_oc_currency SET is_primary = 1, visible = 1 WHERE currency_id = 1');
        // Backfill per-currency balances from the ledger
        $this->db()->query(
            'INSERT INTO xf_oc_balance (user_id, currency_id, balance)'
            . ' SELECT user_id, currency_id, SUM(amount) FROM xf_oc_transaction GROUP BY user_id, currency_id'
        );
        // Keep the legacy column mirroring the primary currency
        $this->db()->query(
            'UPDATE xf_user u LEFT JOIN xf_oc_balance b ON b.user_id = u.user_id AND b.currency_id = 1'
            . ' SET u.oc_credits = COALESCE(b.balance, 0)'
        );
    }

    public function upgrade900Step2()
    {
        $this->grantRegisteredDefaults();
    }

    protected function grantRegisteredDefaults(): void
    {
        // Prefer the stable group id; fall back to the (mutable) title.
        $groupId = (int)$this->db()->fetchOne(
            'SELECT user_group_id FROM xf_user_group WHERE user_group_id = 2'
        );
        if ($groupId <= 0) {
            $groupId = (int)$this->db()->fetchOne(
                "SELECT user_group_id FROM xf_user_group WHERE title = 'Registered'"
            );
        }
        if ($groupId <= 0) {
            return;
        }
        foreach (['ocView', 'ocTransfer'] as $permId) {
            $this->db()->insert('xf_permission_entry', [
                'user_group_id' => $groupId,
                'user_id' => 0,
                'permission_group_id' => 'general',
                'permission_id' => $permId,
                'permission_value' => 'allow',
                'permission_value_int' => 0,
            ], false, "permission_value = VALUES(permission_value)");
        }
    }

    public function upgrade200Step1()
    {
        $this->seedDefaultEvents();
    }

    public function upgrade907Step1()
    {
        $this->schemaManager()->alterTable('xf_oc_transaction', function (Alter $table) {
            $table->addColumn('content_type', 'varchar', 25)->setDefault('');
        });
    }

    public function upgrade921Step1()
    {
        $this->schemaManager()->alterTable('xf_oc_transaction', function (Alter $table) {
            $table->addKey(['user_id', 'currency_id', 'log_date'], 'user_currency_date');
        });
    }

    public function upgrade923Step1()
    {
        $this->schemaManager()->alterTable('xf_oc_event', function (Alter $table) {
            $table->addColumn('send_alert', 'tinyint')->setDefault(1);
        });
    }

    public function upgrade924Step1()
    {
        $this->createCodeTable();
    }

    public function upgrade930Step1()
    {
        $this->createSnapshotTable();
    }

    public function upgrade931Step1()
    {
        $this->addBalanceLeaderboardIndex();
    }

    public function upgrade935Step1()
    {
        // 933 is v0.11.1 and 934 is v0.11.2, so installs on either skip
        // straight to 935. Create the table here so every upgrade path works,
        // and guard it for early dev installs that already have it.
        if (!$this->tableExists('xf_oc_charge')) {
            $this->createChargeTable();
        }
        $this->syncEditorButton(true);
    }

    public function upgrade937Step1()
    {
        if (!$this->tableExists('xf_oc_charge')) {
            $this->createChargeTable();
            return;
        }

        $indexes = $this->schemaManager()->getTableIndexDefinitions('xf_oc_charge');
        $hasOldKey = isset($indexes['content_user_currency_amount']);
        $hasNewKey = isset($indexes['content_user_currency']);

        if ($hasOldKey || !$hasNewKey) {
            // Move from per-price purchases to one purchase per content item.
            // Keep the earliest purchase if a member paid more than one price.
            $this->db()->query(
                'DELETE c1 FROM xf_oc_charge AS c1'
                . ' INNER JOIN xf_oc_charge AS c2 ON c1.content_type = c2.content_type'
                . ' AND c1.content_id = c2.content_id AND c1.user_id = c2.user_id'
                . ' AND c1.currency_id = c2.currency_id AND c1.charge_id > c2.charge_id'
            );
            $this->schemaManager()->alterTable('xf_oc_charge', function (Alter $table) use ($hasOldKey, $hasNewKey) {
                if (!$hasNewKey) {
                    $table->addUniqueKey(['content_type', 'content_id', 'user_id', 'currency_id'], 'content_user_currency');
                }
                if ($hasOldKey) {
                    $table->dropIndexes('content_user_currency_amount');
                }
            });
        }

        if (!isset($indexes['content_currency_amount'])) {
            $this->schemaManager()->alterTable('xf_oc_charge', function (Alter $table) {
                $table->addKey(['content_type', 'content_id', 'currency_id', 'amount'], 'content_currency_amount');
            });
        }
    }

    public function upgrade938Step1()
    {
        $needColumn = !$this->columnExists('xf_oc_transaction', 'event_id');
        $indexes = $this->schemaManager()->getTableIndexDefinitions('xf_oc_transaction');
        $needLogIndex = !isset($indexes['log_date']);
        $needEventIndex = !isset($indexes['event_id']);

        if ($needColumn || $needLogIndex || $needEventIndex) {
            $this->schemaManager()->alterTable('xf_oc_transaction', function (Alter $table) use ($needColumn, $needLogIndex, $needEventIndex) {
                if ($needColumn) {
                    $table->addColumn('event_id', 'int')->setDefault(0);
                }
                if ($needLogIndex) {
                    $table->addKey(['log_date'], 'log_date');
                }
                if ($needEventIndex) {
                    $table->addKey(['event_id'], 'event_id');
                }
            });
        }

        if (!$this->tableExists('xf_oc_backfill')) {
            $this->createBackfillTable();
        }

        $chargeIndexes = $this->schemaManager()->getTableIndexDefinitions('xf_oc_charge');
        if (!isset($chargeIndexes['author_user_id'])) {
            $this->schemaManager()->alterTable('xf_oc_charge', function (Alter $table) {
                $table->addKey(['author_user_id'], 'author_user_id');
            });
        }
    }

    public function upgrade940Step1()
    {
        if (!$this->tableExists('xf_oc_inflation')) {
            $this->createInflationTable();
        } else {
            // Dev installs that already have the table need the pass columns.
            $columns = $this->schemaManager()->getTableColumnDefinitions('xf_oc_inflation');
            $missing = array_diff(
                ['pass_token', 'pass_rate', 'pass_decimals'],
                array_keys($columns)
            );
            if ($missing) {
                $this->schemaManager()->alterTable('xf_oc_inflation', function (Alter $table) use ($missing) {
                    if (in_array('pass_token', $missing, true)) {
                        $table->addColumn('pass_token', 'int')->unsigned(false)->setDefault(0);
                    }
                    if (in_array('pass_rate', $missing, true)) {
                        $table->addColumn('pass_rate', 'decimal', '5,2')->unsigned(false)->setDefault('0.00');
                    }
                    if (in_array('pass_decimals', $missing, true)) {
                        $table->addColumn('pass_decimals', 'tinyint')->setDefault(2);
                    }
                });
            }
        }
        if (!$this->tableExists('xf_oc_faucet')) {
            $this->createFaucetTable();
        }
        if (!$this->tableExists('xf_oc_daily_claim')) {
            $this->createDailyClaimTable();
        }
    }

    /**
     * Adds (or removes) the charge BBCode button in the rich-text editor
     * toolbar's text group, for every viewport configuration. Idempotent and
     * additive: existing button layout is preserved.
     */
    protected function syncEditorButton(bool $add): void
    {
        $config = \XF::options()->editorToolbarConfig;
        if (!is_array($config) || !$config) {
            return;
        }
        $button = 'xfCustom_charge';
        $changed = false;

        if ($add) {
            // Only add when it is missing; never move an existing placement
            // (admins may have rearranged the toolbar).
            foreach ($config AS $groups) {
                if (!is_array($groups)) {
                    continue;
                }
                foreach ($groups AS $group) {
                    if (is_array($group) && !empty($group['buttons']) && is_array($group['buttons'])
                        && in_array($button, $group['buttons'], true)) {
                        return;
                    }
                }
            }

            foreach ($config AS &$groups) {
                if (!is_array($groups)) {
                    continue;
                }
                if (isset($groups['moreText']) && is_array($groups['moreText'])) {
                    $groups['moreText']['buttons'][] = $button;
                    $changed = true;
                }
            }
            unset($groups);
        } else {
            foreach ($config AS &$groups) {
                if (!is_array($groups)) {
                    continue;
                }
                foreach ($groups AS &$group) {
                    if (!is_array($group) || empty($group['buttons']) || !is_array($group['buttons'])) {
                        continue;
                    }
                    $filtered = array_values(array_filter($group['buttons'], function ($existing) use ($button) {
                        return $existing !== $button;
                    }));
                    if ($filtered !== $group['buttons']) {
                        $group['buttons'] = $filtered;
                        $changed = true;
                    }
                }
                unset($group);
            }
            unset($groups);
        }

        if ($changed) {
            \XF::repository(\XF\Repository\OptionRepository::class)
                ->updateOption('editorToolbarConfig', $config);
        }
    }

    protected function addBalanceLeaderboardIndex(): void
    {
        $this->schemaManager()->alterTable('xf_oc_balance', function (Alter $table) {
            $table->addKey(['currency_id', 'balance'], 'currency_balance');
        });
    }

    public function upgrade927Step1()
    {
        $this->schemaManager()->alterTable('xf_oc_transaction', function (Alter $table) {
            $table->addKey(['user_id', 'trigger', 'content_id', 'content_type'], 'user_trigger_content');
            $table->addKey(['user_id', 'trigger', 'log_date'], 'user_trigger_date');
        });
    }

    protected function createCodeTable(): void
    {
        $this->createTable('xf_oc_code', function (Create $table) {
            $table->addColumn('code_id', 'int')->autoIncrement();
            $table->addColumn('code', 'varchar', 32);
            $table->addColumn('currency_id', 'int')->setDefault(1);
            $table->addColumn('amount', 'decimal', '10,2')->unsigned(false)->setDefault('0.00');
            $table->addColumn('max_uses', 'int')->setDefault(1);
            $table->addColumn('uses', 'int')->setDefault(0);
            $table->addColumn('expiry_date', 'int')->setDefault(0);
            $table->addColumn('active', 'tinyint')->setDefault(1);
            $table->addUniqueKey('code', 'code');
        });
    }

    /**
     * Prune snapshots: one row per (member, currency) holding the summed
     * balance of all pruned (deleted) ledger rows. Rebuilds add the snapshot
     * to the surviving rows, so totals stay exact.
     */
    protected function createSnapshotTable(): void
    {
        $this->createTable('xf_oc_snapshot', function (Create $table) {
            $table->addColumn('user_id', 'int');
            $table->addColumn('currency_id', 'int')->setDefault(1);
            $table->addColumn('balance', 'decimal', '10,2')->unsigned(false)->setDefault('0.00');
            $table->addColumn('log_date', 'int')->setDefault(0);
            $table->addPrimaryKey(['user_id', 'currency_id']);
        });
    }

    /**
     * Premium-content purchases for the [charge] BBCode. Unique per
     * content/payer/currency so a member never pays twice for the same
     * content, even if its price changes.
     */
    protected function createChargeTable(): void
    {
        $this->createTable('xf_oc_charge', function (Create $table) {
            $table->addColumn('charge_id', 'int')->autoIncrement();
            $table->addColumn('content_type', 'varchar', 25);
            $table->addColumn('content_id', 'int')->setDefault(0);
            $table->addColumn('user_id', 'int');
            $table->addColumn('author_user_id', 'int')->setDefault(0);
            $table->addColumn('currency_id', 'int')->setDefault(1);
            $table->addColumn('amount', 'decimal', '10,2')->unsigned(false)->setDefault('0.00');
            $table->addColumn('charge_date', 'int')->setDefault(0);
            $table->addUniqueKey(
                ['content_type', 'content_id', 'user_id', 'currency_id'],
                'content_user_currency'
            );
            $table->addKey(['user_id', 'charge_date'], 'user_date');
            $table->addKey(['author_user_id'], 'author_user_id');
            $table->addKey(['content_type', 'content_id', 'currency_id', 'amount'], 'content_currency_amount');
        });
    }

    /**
     * Resumable backfill state: one row per (event, scope). user_id is 0 for a
     * site-wide run or the member id for a per-member run; last_user_id is the
     * last completed batch boundary; completed_date is 0 while running.
     */
    protected function createBackfillTable(): void
    {
        $this->createTable('xf_oc_backfill', function (Create $table) {
            $table->addColumn('backfill_id', 'int')->autoIncrement();
            $table->addColumn('event_id', 'int');
            $table->addColumn('user_id', 'int')->setDefault(0);
            $table->addColumn('last_user_id', 'int')->setDefault(0);
            $table->addColumn('started_date', 'int')->setDefault(0);
            $table->addColumn('completed_date', 'int')->setDefault(0);
            $table->addUniqueKey(['event_id', 'user_id'], 'event_user');
        });
    }

    /**
     * Resumable demurrage state for the primary currency: last_run is the
     * last completed pass, cursor_user_id resumes an in-flight pass.
     * pass_token claims a pass for exactly one runner (0 = idle); pass_rate
     * and pass_decimals snapshot the configuration for the whole pass so a
     * resumable run burns every member at the same rate.
     */
    protected function createInflationTable(): void
    {
        $this->createTable('xf_oc_inflation', function (Create $table) {
            $table->addColumn('currency_id', 'int');
            $table->addColumn('last_run', 'int')->setDefault(0);
            $table->addColumn('cursor_user_id', 'int')->setDefault(0);
            $table->addColumn('pass_started', 'int')->setDefault(0);
            $table->addColumn('pass_token', 'int')->unsigned(false)->setDefault(0);
            $table->addColumn('pass_rate', 'decimal', '5,2')->unsigned(false)->setDefault('0.00');
            $table->addColumn('pass_decimals', 'tinyint')->setDefault(2);
            $table->addPrimaryKey('currency_id');
        });
    }

    /**
     * Daily faucet ledger: member mints per (member, currency, earning day).
     * Updated in the same transaction as the award, so the faucet never
     * depends on event rows (which admins can duplicate, disable, or delete
     * mid-day). Only member-facing mints write here; admin/integrator
     * adjust() calls, transfers, charges, and demurrage do not.
     */
    protected function createFaucetTable(): void
    {
        $this->createTable('xf_oc_faucet', function (Create $table) {
            $table->addColumn('user_id', 'int');
            $table->addColumn('currency_id', 'int')->setDefault(1);
            $table->addColumn('day_start', 'int')->setDefault(0);
            $table->addColumn('amount', 'decimal', '10,2')->unsigned(false)->setDefault('0.00');
            $table->addPrimaryKey(['user_id', 'currency_id', 'day_start']);
        });
    }

    /**
     * Daily-visit claim markers: one row per (member, day) when the daily
     * award is clamped to zero by the cap or faucet. Keeps the per-request
     * visitor_setup path from retrying a write transaction on every page
     * view once the member can no longer earn that day.
     */
    protected function createDailyClaimTable(): void
    {
        $this->createTable('xf_oc_daily_claim', function (Create $table) {
            $table->addColumn('user_id', 'int');
            $table->addColumn('day_start', 'int')->setDefault(0);
            $table->addPrimaryKey(['user_id', 'day_start']);
        });
    }

    public function upgrade600Step1()
    {
        // Debit rows (transfers, charges) need signed amounts
        $this->schemaManager()->alterTable('xf_oc_event', function (Alter $table) {
            $table->changeColumn('amount', 'decimal', '10,2')->unsigned(false)->setDefault('0.00');
        });
        $this->schemaManager()->alterTable('xf_oc_transaction', function (Alter $table) {
            $table->changeColumn('amount', 'decimal', '10,2')->unsigned(false);
        });
    }

    protected function seedDefaultEvents(): void
    {
        // Seed default MVP events (idempotent)
        $defaults = [
            ['trigger' => 'thread', 'amount' => '5.00'],
            ['trigger' => 'post', 'amount' => '1.00'],
            ['trigger' => 'reaction_received', 'amount' => '2.00'],
            ['trigger' => 'register', 'amount' => '10.00'],
            ['trigger' => 'daily_login', 'amount' => '5.00'],
        ];
        // Upgrade steps run in version order: upgrade200Step1 seeds before
        // upgrade923Step1 creates send_alert, so only write the key when the
        // column exists (ancient DBs upgrading through 200 would fatal).
        $hasSendAlert = $this->schemaManager()->columnExists('xf_oc_event', 'send_alert');
        foreach ($defaults as $row) {
            $exists = $this->db()->fetchOne(
                'SELECT event_id FROM xf_oc_event WHERE `trigger` = ? AND currency_id = 1',
                $row['trigger']
            );
            if (!$exists) {
                $insert = [
                    'currency_id' => 1,
                    'trigger' => $row['trigger'],
                    'amount' => $row['amount'],
                    'max_per_day' => 0,
                    'active' => 1,
                ];
                if ($hasSendAlert) {
                    $insert['send_alert'] = 1;
                }
                $this->db()->insert('xf_oc_event', $insert);
            }
        }
    }
}
