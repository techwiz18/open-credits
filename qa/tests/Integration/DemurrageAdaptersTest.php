<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Admin\Controller\Tools;
use OpenCredits\Credits\Cli\Command\Demurrage as DemurrageCommand;
use OpenCredits\Credits\Cron\Demurrage as DemurrageCron;
use OpenCredits\Credits\Service\DemurrageRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * #17: the three demurrage runners are thin adapters around the budgeted
 * module. These tests pin the only things each adapter owns: the budget and
 * interval flag it passes, the verdict mapping it applies, and the wording
 * it renders. A verdict or budget change must fail here instead of silently
 * regressing the cron, admin, or CLI outcome.
 */
class DemurrageAdaptersTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists('XF', false) || !\XF::app()) {
            $this->markTestSkipped('Set OC_XF_ROOT to a licensed XenForo install to run integration tests.');
        }
    }

    protected function tearDown(): void
    {
        // The spy is registered as a class extension only for these tests.
        if (class_exists('XF', false) && \XF::app()) {
            \XF::app()->extension()->removeClassExtensions(\XF::getClassForAlias(Tools::class));
        }
    }

    public function testCronPinsBudgetAndIntervalFlag(): void
    {
        $runner = new AdapterRunnerStub();
        CronAdapterSpy::$runner = $runner;

        CronAdapterSpy::run();

        $this->assertSame(
            [[500, false, 10]],
            $runner->calls,
            'Cron passes the 10s budget and enforces the configured interval.'
        );
    }

    public function testAdminPinsBudgetAndReportsBusyAsRan(): void
    {
        $runner = new AdapterRunnerStub();
        $runner->result = $this->verdict('busy', false);

        $reply = $this->adminController($runner)->actionDemurrageSave();
        $params = $reply->getParams();

        $this->assertSame(
            [[500, true, 25]],
            $runner->calls,
            'The admin action uses a 25s budget and forces the interval.'
        );
        $this->assertTrue($params['ran'], 'A busy first step is reported as ran.');
        $this->assertTrue($params['busy'], 'The busy flag drives the busy message.');
    }

    public function testAdminIntervalSkipStillShowsTheForm(): void
    {
        $runner = new AdapterRunnerStub();
        $runner->result = $this->verdict('done', false);

        $params = $this->adminController($runner)->actionDemurrageSave()->getParams();

        $this->assertFalse($params['ran'], 'An interval skip is not a run.');
        $this->assertFalse($params['busy'], 'An interval skip is not busy.');
        $this->assertTrue($params['result']['done']);
    }

    public function testAdminIncompletePassIsReportedAsRanAndNotBusy(): void
    {
        $runner = new AdapterRunnerStub();
        $runner->result = $this->verdict('resume', true, 3, 1.5, 2);

        $params = $this->adminController($runner)->actionDemurrageSave()->getParams();

        $this->assertTrue($params['ran'], 'A partial pass rendered progress.');
        $this->assertFalse($params['busy']);
        $this->assertFalse($params['result']['done'], 'The template reports the incomplete pass.');
        $this->assertSame(3, $params['result']['processed']);
    }

    public function testCliRunsTheFullPassWithNoBudget(): void
    {
        $runner = new AdapterRunnerStub();
        $runner->result = $this->verdict('done', true, 2, 1.5, 2);

        [$exit, $output] = $this->runCli($runner);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame(
            [[500, true, null]],
            $runner->calls,
            'The operator command runs the whole pass without a time budget.'
        );
        $this->assertStringContainsString('Demurrage processed 2 account(s); burned 1.50.', $output);
    }

    public function testCliReportsBusyWithRerunWording(): void
    {
        $runner = new AdapterRunnerStub();
        $runner->result = $this->verdict('busy', false);

        [$exit, $output] = $this->runCli($runner);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Another demurrage pass is already in progress', $output);
    }

    public function testCliReportsIncompleteAndTellsOperatorToRerun(): void
    {
        $runner = new AdapterRunnerStub();
        $runner->result = $this->verdict('resume', true, 2, 1.0, 1);

        [$exit, $output] = $this->runCli($runner);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Demurrage pass incomplete', $output);
        $this->assertStringContainsString('Re-run to resume.', $output);
    }

    public function testCliDoesNotReportAnIntervalSkipAsBusy(): void
    {
        $runner = new AdapterRunnerStub();
        $runner->result = $this->verdict('done', false);

        [$exit, $output] = $this->runCli($runner);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Nothing to do', $output);
        $this->assertStringNotContainsString('already in progress', $output);
    }

    /**
     * @return array{0: int, 1: string}
     */
    protected function runCli(AdapterRunnerStub $runner): array
    {
        $output = new BufferedOutput();
        $exit = (new CliAdapterSpy($runner))->run(new ArrayInput([]), $output);

        return [$exit, $output->fetch()];
    }

    protected function adminController(AdapterRunnerStub $runner): ToolsAdapterSpy
    {
        $request = new \XF\Http\Request(
            \XF::app()->inputFilterer(),
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'POST']
        );

        // The controller constructor resolves its root class through the
        // extension map, so register the spy before constructing it.
        \XF::app()->extension()->addClassExtension(Tools::class, ToolsAdapterSpy::class);

        return new ToolsAdapterSpy(\XF::app(), $request, $runner);
    }

    /** @return array<string, mixed> */
    protected function verdict(
        string $verdict,
        bool $ran,
        int $processed = 0,
        float $burned = 0.0,
        int $accounts = 0
    ): array {
        return [
            'verdict' => $verdict,
            'ran' => $ran,
            'busy' => $verdict === 'busy',
            'done' => $verdict === 'done',
            'processed' => $processed,
            'burned' => $burned,
            'accounts' => $accounts,
            'lastRun' => 0,
            'nextRun' => 0,
        ];
    }
}

/**
 * Records the adapter's call and returns a canned verdict. Never touches a DB.
 */
class AdapterRunnerStub extends DemurrageRunner
{
    /** @var array<int, array{0: int, 1: bool, 2: int|null}> */
    public array $calls = [];

    /** @var array<string, mixed> */
    public array $result = [
        'verdict' => 'done',
        'ran' => true,
        'busy' => false,
        'done' => true,
        'processed' => 0,
        'burned' => 0.0,
        'accounts' => 0,
        'lastRun' => 0,
        'nextRun' => 0,
    ];

    public function __construct()
    {
    }

    public function run(int $batchSize = 500, bool $ignoreInterval = true, ?int $timeBudget = null): array
    {
        $this->calls[] = [$batchSize, $ignoreInterval, $timeBudget];

        return $this->result;
    }

    public function preview(): array
    {
        return [
            'enabled' => true,
            'rate' => 10.0,
            'days' => 7,
            'currency' => ['title' => 'Credits', 'decimals' => 2],
            'lastRun' => 0,
            'nextRun' => 0,
            'inProgress' => false,
            'accounts' => 0,
            'total' => 0.0,
        ];
    }
}

/** Cron adapter bound to a stub runner instead of the service container. */
class CronAdapterSpy extends DemurrageCron
{
    public static ?DemurrageRunner $runner = null;

    protected static function runner(): DemurrageRunner
    {
        return self::$runner;
    }
}

/** CLI adapter bound to a stub runner. */
class CliAdapterSpy extends DemurrageCommand
{
    protected DemurrageRunner $stub;

    public function __construct(DemurrageRunner $stub)
    {
        $this->stub = $stub;
        parent::__construct();
    }

    protected function runner(): DemurrageRunner
    {
        return $this->stub;
    }
}

/** Admin Tools controller bound to a stub runner. */
class ToolsAdapterSpy extends Tools
{
    protected DemurrageRunner $stub;

    public function __construct(\XF\App $app, \XF\Http\Request $request, DemurrageRunner $stub)
    {
        $this->stub = $stub;
        parent::__construct($app, $request);
    }

    protected function demurrageRunner(): DemurrageRunner
    {
        return $this->stub;
    }
}
