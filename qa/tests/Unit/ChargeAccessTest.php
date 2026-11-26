<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\Service\ChargeAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The [charge] access table pinned in full: every exemption, the denied
 * non-viewer, the paid member and the ownerless content case. A changed
 * priority turns this red, which is the point — the table is the only copy.
 */
class ChargeAccessTest extends TestCase
{
    #[DataProvider('matrix')]
    public function testVerdicts(
        bool $canView,
        bool $hasAuthor,
        bool $isAuthor,
        bool $isStaff,
        bool $isBypass,
        bool $hasPaid,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            ChargeAccess::resolve($canView, $hasAuthor, $isAuthor, $isStaff, $isBypass, $hasPaid)
        );
    }

    public static function matrix(): array
    {
        return [
            'viewable member with no exemption' => [true, true, false, false, false, false, 'locked'],
            'author without view permission' => [false, true, true, false, false, false, 'author'],
            'author beats staff' => [true, true, true, true, false, true, 'author'],
            'author beats paid' => [true, true, true, false, false, true, 'author'],
            'staff without view permission' => [false, true, false, true, false, false, 'staff'],
            'staff beats bypass and paid' => [true, true, false, true, true, true, 'staff'],
            'bypass without view permission' => [false, true, false, false, true, false, 'bypass'],
            'bypass beats paid' => [true, true, false, false, true, true, 'bypass'],
            'paid member' => [true, true, false, false, false, true, 'paid'],
            'non-exempt member without view permission' => [false, true, false, false, false, false, 'denied'],
            'denied beats paid' => [false, true, false, false, false, true, 'denied'],
            'ownerless post shows the body' => [true, false, false, false, false, false, 'bypass'],
            'ownerless post ignores paid' => [true, false, false, false, false, true, 'bypass'],
            'ownerless post stays hidden from a non-viewer' => [false, false, false, false, false, false, 'denied'],
        ];
    }

    #[DataProvider('verdicts')]
    public function testAllowsOnlyBodyVerdicts(string $verdict, bool $allows, bool $isAuthor): void
    {
        $this->assertSame($allows, ChargeAccess::allows($verdict));
        $this->assertSame($isAuthor, ChargeAccess::isAuthor($verdict));
    }

    public static function verdicts(): array
    {
        return [
            'author' => ['author', true, true],
            'staff' => ['staff', true, false],
            'bypass' => ['bypass', true, false],
            'paid' => ['paid', true, false],
            'locked' => ['locked', false, false],
            'denied' => ['denied', false, false],
        ];
    }
}
