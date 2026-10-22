<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use XF\Cli\Command\AbstractCommand;

class Demurrage extends AbstractCommand
{
	protected function configure(): void
	{
		$this
			->setName('oc-credits:demurrage')
			->setDescription('Apply inflation-control demurrage now')
			->addOption(
				'preview',
				null,
				InputOption::VALUE_NONE,
				'Show what a pass would burn without changing anything'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		/** @var \OpenCredits\Credits\Service\Transact $svc */
		$svc = \XF::app()->service('OpenCredits\Credits:Transact');

		if ($input->getOption('preview')) {
			$preview = $svc->demurragePreview();
			if (!$preview['enabled'] || $preview['rate'] <= 0 || !$preview['currency']) {
				$io->warning('Inflation control is disabled, the demurrage rate is 0, or the primary currency is unavailable.');
				return Command::SUCCESS;
			}
			$decimals = (int)($preview['currency']['decimals'] ?? 2);
			$io->table(['Rate', 'Period', 'Accounts', 'Total burn'], [[
				$preview['rate'] . '%',
				$preview['days'] . ' day(s)',
				(string)$preview['accounts'],
				number_format((float)$preview['total'], $decimals),
			]]);
			return Command::SUCCESS;
		}

		$result = $svc->runDemurrage(500, true);
		if (!$result['ran']) {
			$io->warning('Nothing to do: inflation control is disabled, the demurrage rate is 0, or the primary currency is unavailable.');
			return Command::SUCCESS;
		}

		$io->success(sprintf(
			'Demurrage processed %d account(s); burned %s.',
			$result['accounts'],
			number_format((float)$result['burned'], 2)
		));

		return Command::SUCCESS;
	}
}
