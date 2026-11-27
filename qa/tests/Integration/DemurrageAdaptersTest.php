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
    /** Whether this test registered the Tools class extension. */
    protected bool $toolsExtensionAdded = false;

    protected function setUp(): void
    {
        // No static runner may leak in from another test, whatever the order.
        CronAdapterSpy::$runner = null;

        if (!class_exists('XF', false) || !\XF::app()) {
            $this->markTestSkipped('Set OC_XF_ROOT to a licensed XenForo install to run integration tests.');
        }
    }

    protected function tearDown(): void
    {
        // Only remove what this test added: cron/CLI tests must not strip an
        // extension they did not register.
        if ($this->toolsExtensionAdded && class_exists('XF', false) && \XF::app()) {
            \XF::app()->extension()->removeClassExtensions(\XF::getClassForAlias(Tools::class));
        }
        $this->toolsExtensionAdded = false;
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
        $runner->nextResult = $runner->verdict('busy', false);

        $reply = $this->adminController($runner)->actionDemurrageSave();
        $params = $reply->getParams();

        $this->assertSame(
            [[500, true, 25]],
            $runner->calls,
            'The admin action uses a 25s budget and forces the interval.'
        );
        $this->assertTrue($params['ran'], 'A busy first step is reported as ran.');
        $this->assertTrue($params['busy'], 'The busy flag drives the busy message.');

        $output = $this->renderDemurrage($params);
        $this->assertStringContainsString(
            $this->renderedPhrase('ocadm_demurrage_busy'),
            $output,
            'Busy renders the busy message.'
        );
        $this->assertStringNotContainsString(
            $this->renderedPhrase('ocadm_demurrage_run'),
            $output,
            'Busy does not show the run form.'
        );
    }

    public function testAdminIntervalSkipStillShowsTheForm(): void
    {
        $runner = new AdapterRunnerStub();
        $runner->nextResult = $runner->verdict('done', false);

        $params = $this->adminController($runner)->actionDemurrageSave()->getParams();

        $this->assertFalse($params['ran'], 'An interval skip is not a run.');
        $this->assertFalse($params['busy'], 'An interval skip is not busy.');
        $this->assertTrue($params['result']['done']);

        $output = $this->renderDemurrage($params);
        $this->assertStringContainsString(
            $this->renderedPhrase('ocadm_demurrage_run'),
            $output,
            'An interval skip still renders the run form.'
        );
        $this->assertStringNotContainsString(
            $this->renderedPhrase('ocadm_demurrage_busy'),
            $output,
            'An interval skip is not rendered as busy.'
        );
    }

    public function testAdaptersPrintTheRunnersActiveVerdict(): void
    {
        $runner = new AdapterRunnerStub();
        // Settings look runnable; only the runner's verdict says otherwise.
        $runner->preview = [
            'enabled' => true,
            'active' => false,
            'rate' => 10.0,
            'days' => 7,
            'currency' => ['title' => 'Credits', 'decimals' => 2],
            'decimals' => 2,
            'lastRun' => 0,
            'nextRun' => 0,
            'inProgress' => false,
            'accounts' => 0,
            'total' => 0.0,
        ];

        $params = $this->adminController($runner)->actionDemurrage()->getParams();
        $output = $this->renderDemurrage($params);
        $this->assertStringContainsString($this->renderedPhrase('ocadm_demurrage_disabled'), $output);
        $this->assertStringNotContainsString($this->renderedPhrase('ocadm_demurrage_run'), $output);

        $cli = new BufferedOutput();
        $exit = (new CliAdapterSpy($runner))->run(new ArrayInput(['--preview' => true]), $cli);
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Inflation control is disabled', $cli->fetch());
    }

    public function testAdminIncompletePassIsReportedAsRanAndNotBusy(): void
    {
        $runner = new AdapterRunnerStub();
        $runner->nextResult = $runner->verdict('resume', true, 3, 1.5, 2);

        $params = $this->adminController($runner)->actionDemurrageSave()->getParams();

        $this->assertTrue($params['ran'], 'A partial pass rendered progress.');
        $this->assertFalse($params['busy']);
        $this->assertFalse($params['result']['done'], 'The template reports the incomplete pass.');
        $this->assertSame(3, $params['result']['processed']);

        $output = $this->renderDemurrage($params);
        // Parameterised phrase: assert its fixed wording around the placeholders.
        $this->assertStringContainsString(
            'Demurrage pass incomplete',
            $output,
            'The incomplete pass renders the incomplete guidance.'
        );
        $this->assertStringContainsString('Re-run to resume.', $output);
        $this->assertStringNotContainsString(
            $this->renderedPhrase('ocadm_demurrage_busy'),
            $output,
            'An incomplete pass is not rendered as busy.'
        );
    }

    public function testCliRunsTheFullPassWithNoBudget(): void
    {
        $runner = new AdapterRunnerStub();
        $runner->nextResult = $runner->verdict('done', true, 2, 1.5, 2);

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
        $runner->nextResult = $runner->verdict('busy', false);

        [$exit, $output] = $this->runCli($runner);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Another demurrage pass is already in progress', $output);
    }

    public function testCliReportsIncompleteAndTellsOperatorToRerun(): void
    {
        $runner = new AdapterRunnerStub();
        $runner->nextResult = $runner->verdict('resume', true, 2, 1.0, 1);

        [$exit, $output] = $this->runCli($runner);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Demurrage pass incomplete', $output);
        $this->assertStringContainsString('Re-run to resume.', $output);
    }

    public function testCliDoesNotReportAnIntervalSkipAsBusy(): void
    {
        $runner = new AdapterRunnerStub();
        $runner->nextResult = $runner->verdict('done', false);

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
        if (!$this->toolsExtensionAdded) {
            \XF::app()->extension()->addClassExtension(Tools::class, ToolsAdapterSpy::class);
            $this->toolsExtensionAdded = true;
        }

        return new ToolsAdapterSpy(\XF::app(), $request, $runner);
    }

    /**
     * Renders the tracked admin template source directly. Compiling the
     * `_output` source (rather than the DB-compiled copy) means a stale
     * compiled template cannot hide a template regression.
     *
     * @param array<string, mixed> $params
     */
    protected function renderDemurrage(array $params): string
    {
        $addonRoot = getenv('OC_ADDON_SRC') ?: __DIR__ . '/../../../addon-src/OpenCredits/Credits';
        $template = $addonRoot . '/_output/templates/admin/oc_tools_demurrage.html';
        if (!is_file($template)) {
            // Container runs from a synced copy: the bind-mounted addon dir
            // still holds the tracked source.
            $template = \XF::getAddOnDirectory() . '/OpenCredits/Credits/_output/templates/admin/oc_tools_demurrage.html';
        }

        $source = file_get_contents($template);
        if ($source === false) {
            $this->fail('Could not read the tracked demurrage template source.');
        }

        $compiled = \XF::app()->templateCompiler()->compile($source, \XF::app()->language());
        /** @var array{code: callable} $data */
        $data = eval($compiled);

        return $data['code'](\XF::app()->templater(), $params);
    }

    /** Renders a phrase, failing if it is missing (XF would render its name). */
    protected function renderedPhrase(string $name): string
    {
        $phrase = (string)\XF::phrase($name);

        $this->assertNotSame(
            $name,
            $phrase,
            "The $name phrase is missing, so the template branch is not really rendered."
        );

        return $phrase;
    }
}

/**
 * Records the adapter's call and returns a canned verdict. Never touches a DB.
 */
class AdapterRunnerStub extends DemurrageRunner
{
    /** @var array<int, array{0: int, 1: bool, 2: int|null}> */
    public array $calls = [];

    /** @var array<string, mixed>|null Canned run() result; null uses a default pass. */
    public ?array $nextResult = null;

    /** @var array<string, mixed>|null Overrides preview() for verdict tests. */
    public ?array $preview = null;

    public function __construct()
    {
    }

    /**
     * Build a canned pass result through the runner's own factory so a new
     * result field cannot be forgotten here.
     *
     * @return array<string, mixed>
     */
    public function verdict(
        string $verdict,
        bool $ran,
        int $processed = 0,
        float $burned = 0.0,
        int $accounts = 0
    ): array {
        return $this->result($verdict, $ran, $processed, $burned, $accounts, 0, 0, 2);
    }

    public function run(int $batchSize = 500, bool $ignoreInterval = true, ?int $timeBudget = null): array
    {
        $this->calls[] = [$batchSize, $ignoreInterval, $timeBudget];

        return $this->nextResult ?? $this->verdict('done', true);
    }

    public function preview(): array
    {
        return $this->preview ?? [
            'enabled' => true,
            'active' => true,
            'rate' => 10.0,
            'days' => 7,
            'currency' => ['title' => 'Credits', 'decimals' => 2],
            'decimals' => 2,
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
