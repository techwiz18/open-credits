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
            $table->addColumn('content_id', 'int')->setDefault(0);
            $table->addColumn('note', 'varchar', 255)->setDefault('');
            $table->addColumn('log_date', 'int');
            $table->addKey(['user_id', 'log_date']);
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
        ], false, 'currency_id = VALUES(currency_id)');
    }

    public function installStep6()
    {
        $this->seedDefaultEvents();
    }

    public function uninstallStep1()
    {
        $sm = $this->schemaManager();
        $sm->dropTable('xf_oc_currency');
        $sm->dropTable('xf_oc_event');
        $sm->dropTable('xf_oc_transaction');
    }

    public function uninstallStep2()
    {
        $this->schemaManager()->alterTable('xf_user', function (Alter $table) {
            $table->dropColumns('oc_credits');
        });
    }

    public function upgrade200Step1()
    {
        $this->seedDefaultEvents();
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
        foreach ($defaults as $row) {
            $exists = $this->db()->fetchOne(
                'SELECT event_id FROM xf_oc_event WHERE `trigger` = ? AND currency_id = 1',
                $row['trigger']
            );
            if (!$exists) {
                $this->db()->insert('xf_oc_event', [
                    'currency_id' => 1,
                    'trigger' => $row['trigger'],
                    'amount' => $row['amount'],
                    'max_per_day' => 0,
                    'active' => 1,
                ]);
            }
        }
    }
}
