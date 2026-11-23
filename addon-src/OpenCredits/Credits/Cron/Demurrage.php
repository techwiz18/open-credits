<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Cron;

use OpenCredits\Credits\Service\DemurrageRunner;

class Demurrage
{
	public static function run(): void
	{
		static::runner()->run(500, false, 10);
	}

	protected static function runner(): DemurrageRunner
	{
		/** @var \OpenCredits\Credits\Service\Transact $svc */
		$svc = \XF::app()->service('OpenCredits\Credits:Transact');

		return $svc->demurrageRunner();
	}
}
