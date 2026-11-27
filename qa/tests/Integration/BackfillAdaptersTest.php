<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Admin\Controller\Tools;
use OpenCredits\Credits\Service\BackfillRunner;
use PHPUnit\Framework\TestCase;

/**
 * #24: the admin backfill action is a thin adapter around BackfillRunner.
 * These tests pin the only things the adapter owns: the parsed input and
 * batch budget it passes, and how each runner verdict maps to a reply or a
 * policy error. A verdict or budget change must fail here instead of
 * silently dropping selected events or hiding a pause.
 */
class BackfillAdaptersTest extends TestCase
{
    /** Whether this test registered the Tools class extension. */
    protected bool $toolsExtensionAdded = false;

    protected function setUp(): void
    {
        if (!class_exists('XF', false) || !\XF::app()) {
            $this->markTestSkipped('Set OC_XF_ROOT to a licensed XenForo install to run integration tests.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->toolsExtensionAdded && class_exists('XF', false) && \XF::app()) {
            \XF::app()->extension()->removeClassExtensions(\XF::getClassForAlias(Tools::class));
        }
        $this->toolsExtensionAdded = false;
    }

    public function testAdminPassesParsedInputAndTheBatchBudget(): void
    {
        $runner = new BackfillRunnerStub();
        $runner->result = ['verdict' => 'done', 'awarded' => 3, 'skipped' => [9]];

        $params = $this->adminController($runner, [
            'event_ids' => [4, '7'],
            'username' => '',
            'force' => 0,
        ])->actionBackfillSave()->getParams();

        $this->assertSame(
            [[[4, 7], 0, false, 5000]],
            $runner->calls,
            'The action passes uint event ids, an unscoped run, no force, and the 5000-row batch budget.'
        );
        $this->assertTrue($params['ran'], 'A done verdict renders the result block.');
        $this->assertSame(3, $params['awarded'], 'The awarded count comes from the runner.');
        $this->assertSame([9], $params['skipped'], 'The skipped ids come from the runner.');

        $output = $this->renderBackfill($params);
        $this->assertStringContainsString('3', $output, 'The template renders the awarded count.');
        $this->assertStringContainsString(
            $this->renderedPhrase('ocadm_back_to_tools'),
            $output,
            'A finished run replaces the form with the back link.'
        );
    }

    public function testAdminResolvesTheMemberScope(): void
    {
        $user = \XF::finder('XF:User')->order('user_id')->fetchOne();
        if (!$user) {
            $this->markTestSkipped('No member to scope a backfill to.');
        }

        $runner = new BackfillRunnerStub();

        $this->adminController($runner, [
            'event_ids' => [2],
            'username' => $user->username,
            'force' => 1,
        ])->actionBackfillSave();

        $this->assertSame(
            [[[2], (int)$user->user_id, true, 5000]],
            $runner->calls,
            'A username resolves to a member scope and the force box is passed through.'
        );
    }

    public function testAdminMapsEveryVerdict(): void
    {
        $cases = [
            'no_events' => 'Select at least one event to backfill.',
            'no_eligible_events' => 'No eligible events selected. Only active post/thread events can be backfilled.',
            'inactive_currencies' => 'No eligible events selected. The chosen events use inactive currencies.',
            'incomplete' => 'Backfill paused part-way. Completed batches are kept — re-run to resume.',
            'rebuild_failed' => 'Backfill rows were written, but the balance rebuild failed.',
        ];

        foreach ($cases as $verdict => $message) {
            $runner = new BackfillRunnerStub();
            $runner->result = ['verdict' => $verdict, 'awarded' => 0, 'skipped' => []];

            $reply = $this->adminController($runner, ['event_ids' => [2]])->actionBackfillSave();

            $this->assertInstanceOf(
                \XF\Mvc\Reply\Error::class,
                $reply,
                "The $verdict verdict is reported as an error."
            );
            $this->assertStringContainsString(
                $message,
                (string)$reply->getErrors()[0],
                "The $verdict verdict keeps its operator-facing message."
            );
        }
    }

    public function testKnownMemberIsRequired(): void
    {
        $runner = new BackfillRunnerStub();

        $reply = $this->adminController($runner, [
            'event_ids' => [2],
            'username' => 'no_such_member_here',
        ])->actionBackfillSave();

        $this->assertInstanceOf(\XF\Mvc\Reply\Error::class, $reply);
        $this->assertStringContainsString('User not found', (string)$reply->getErrors()[0]);
        $this->assertSame([], $runner->calls, 'An unresolvable member never reaches the runner.');
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function adminController(BackfillRunnerStub $runner, array $input = []): BackfillToolsAdapterSpy
    {
        $request = new \XF\Http\Request(
            \XF::app()->inputFilterer(),
            $input,
            [],
            [],
            ['REQUEST_METHOD' => 'POST']
        );

        // The controller constructor resolves its root class through the
        // extension map, so register the spy before constructing it.
        if (!$this->toolsExtensionAdded) {
            \XF::app()->extension()->addClassExtension(Tools::class, BackfillToolsAdapterSpy::class);
            $this->toolsExtensionAdded = true;
        }

        return new BackfillToolsAdapterSpy(\XF::app(), $request, $runner);
    }

    /**
     * Renders the tracked admin template source directly, so a stale
     * compiled template cannot hide a template regression.
     *
     * @param array<string, mixed> $params
     */
    protected function renderBackfill(array $params): string
    {
        $addonRoot = getenv('OC_ADDON_SRC') ?: __DIR__ . '/../../../addon-src/OpenCredits/Credits';
        $template = $addonRoot . '/_output/templates/admin/oc_tools_backfill.html';
        if (!is_file($template)) {
            $template = \XF::getAddOnDirectory() . '/OpenCredits/Credits/_output/templates/admin/oc_tools_backfill.html';
        }

        $source = file_get_contents($template);
        if ($source === false) {
            $this->fail('Could not read the tracked backfill template source.');
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
class BackfillRunnerStub extends BackfillRunner
{
    /** @var array<int, array{0: int[], 1: int, 2: bool, 3: int}> */
    public array $calls = [];

    /** @var array{verdict: string, awarded: int, skipped: int[]} */
    public array $result = ['verdict' => 'done', 'awarded' => 0, 'skipped' => []];

    public function __construct()
    {
    }

    public function run(array $eventIds, int $scopeUserId, bool $force, int $batchSize = 5000): array
    {
        $this->calls[] = [$eventIds, $scopeUserId, $force, $batchSize];

        return $this->result;
    }

    public function preview(): array
    {
        return [];
    }
}

/** Admin Tools controller bound to a stub runner. */
class BackfillToolsAdapterSpy extends Tools
{
    protected BackfillRunner $stub;

    public function __construct(\XF\App $app, \XF\Http\Request $request, BackfillRunner $stub)
    {
        $this->stub = $stub;
        parent::__construct($app, $request);
    }

    protected function backfillRunner(): BackfillRunner
    {
        return $this->stub;
    }
}
