<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\Listener;
use PHPUnit\Framework\TestCase;

class ListenerTest extends TestCase
{
    public function testUserDeleteCleanupCoversChargeRows(): void
    {
        $deletes = [];
        Listener::userDeleteCleanInit(null, $deletes);

        $this->assertSame(
            ['user_id = ?', 'author_user_id = ?'],
            $deletes['xf_oc_charge'] ?? null
        );
    }
}
