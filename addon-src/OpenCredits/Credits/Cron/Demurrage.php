<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Cron;

class Demurrage
{
	public static function run(): void
	{
		/** @var \OpenCredits\Credits\Service\Transact $svc */
		$svc = \XF::app()->service('OpenCredits\Credits:Transact');
		$svc->runDemurrage(500, false, 10);
	}
}
