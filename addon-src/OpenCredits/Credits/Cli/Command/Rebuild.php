<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use XF\Cli\Command\AbstractCommand;

class Rebuild extends AbstractCommand
{
	protected function configure(): void
	{
		$this
			->setName('oc-credits:rebuild')
			->setDescription('Rebuild all credit balances from the transaction log');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		/** @var \OpenCredits\Credits\Service\Transact $svc */
		$svc = \XF::app()->service('OpenCredits\Credits:Transact');
		$rows = $svc->rebuildAllBalances();

		$io->success("Rebuilt {$rows} credit balance(s) from the transaction log.");

		return Command::SUCCESS;
	}
}
