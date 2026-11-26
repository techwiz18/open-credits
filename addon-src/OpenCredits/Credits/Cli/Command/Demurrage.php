<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Cli\Command;

use OpenCredits\Credits\Service\DemurrageRunner;
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

		$runner = $this->runner();

		if ($input->getOption('preview')) {
			$preview = $runner->preview();
			if (!$preview['active']) {
				$io->warning('Inflation control is disabled, the demurrage rate is 0, or the primary currency is unavailable.');
				return Command::SUCCESS;
			}
			$io->table(['Rate', 'Period', 'Accounts', 'Total burn'], [[
				$preview['rate'] . '%',
				$preview['days'] . ' day(s)',
				(string)$preview['accounts'],
				number_format((float)$preview['total'], $preview['decimals']),
			]]);
			return Command::SUCCESS;
		}

		// null budget: an operator-run command completes the pass (Ctrl-C stops it).
		$result = $runner->run(500, true, null);
		if ($result['verdict'] === 'busy') {
			$io->warning('Another demurrage pass is already in progress. Re-run once it finishes.');
			return Command::SUCCESS;
		}
		if ($result['verdict'] === 'done' && !$result['ran']) {
			$io->warning('Nothing to do: inflation control is disabled, the demurrage rate is 0, or the primary currency is unavailable.');
			return Command::SUCCESS;
		}

		$decimals = (int)$result['decimals'];
		if ($result['verdict'] === 'resume') {
			$io->warning(sprintf(
				'Demurrage pass incomplete: processed %d account(s), burned %s. Re-run to resume.',
				$result['accounts'],
				number_format((float)$result['burned'], $decimals)
			));
			return Command::SUCCESS;
		}

		$io->success(sprintf(
			'Demurrage processed %d account(s); burned %s.',
			$result['accounts'],
			number_format((float)$result['burned'], $decimals)
		));

		return Command::SUCCESS;
	}

	protected function runner(): DemurrageRunner
	{
		/** @var \OpenCredits\Credits\Service\Transact $svc */
		$svc = \XF::app()->service('OpenCredits\Credits:Transact');

		return $svc->demurrageRunner();
	}
}
