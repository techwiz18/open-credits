<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Pub\Controller\Credits;
use OpenCredits\Credits\Service\WalletView;
use PHPUnit\Framework\TestCase;

/**
 * The two credits surfaces: the wallet page (/credits) owns the balance,
 * actions and full paged history; the profile Credits tab is per-member
 * (balances + a send form for other members, "Open wallet" for self).
 *
 * Runs against the licensed dev forum and cleans its own seeded rows.
 */
class MemberCreditsTest extends TestCase
{
    protected const SEED_TRIGGER = 'octest_wallet';

    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    protected int $memberId = 0;
    protected int $otherId = 0;
    protected int $currencyId = 0;

    protected function setUp(): void
    {
        if (!class_exists('XF', false) || !\XF::app()) {
            $this->markTestSkipped('Set OC_XF_ROOT to a licensed XenForo install to run integration tests.');
        }
        if (!\XF::$time) {
            \XF::$time = time();
        }
        $this->db = \XF::db();
        $this->currencyId = \XF::app()->service('OpenCredits\Credits:Transact')->primaryCurrencyId();

        $ids = $this->db->fetchAllColumn(
            "SELECT user_id FROM xf_user WHERE user_state = 'valid' AND is_banned = 0 ORDER BY user_id LIMIT 2"
        );
        if (count($ids) < 2) {
            $this->markTestSkipped('Need at least two valid members.');
        }
        $this->memberId = (int)$ids[0];
        $this->otherId = (int)$ids[1];

        $this->cleanup();
        \XF::app()->session()->remove('ocWalletPerPage');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        \XF::setVisitor(null);
    }

    public function testWalletPaginatesAtTenByDefault(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));
        $this->seedWalletRows(25);

        $page1 = $this->callIndex(1)->getParams();
        $this->assertTrue($this->walletView()->showFlags(false)['showBalance']);
        $this->assertCount(10, $page1['entries']);
        $this->assertGreaterThanOrEqual(25, (int)$page1['total']);

        $page2 = $this->callIndex(2)->getParams();
        $this->assertCount(10, $page2['entries']);

        $larger = $this->callIndex(1, 0, 50)->getParams();
        $this->assertGreaterThanOrEqual(25, count($larger['entries']));
    }

    public function testWalletPerPageSelectionPersists(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));
        $this->seedWalletRows(25);

        $first = $this->callIndex(1, 0, 20)->getParams();
        $this->assertCount(20, $first['entries']);

        // No per_page param: the session value (20) is reused.
        $second = $this->callIndex(1)->getParams();
        $this->assertCount(20, $second['entries']);
    }

    public function testWalletViewSelectsTheCurrency(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));

        $view = $this->walletView();
        $this->assertSame(
            $this->currencyId,
            (int)$view->selected($this->currencyId)->currency_id
        );
        // Unknown ids fall back to the primary, or fail with fallback off.
        $this->assertSame(
            $this->currencyId,
            (int)$view->selected(999999)->currency_id
        );
        $this->assertNull($view->selected(999999, false));
        $this->assertNotEmpty($view->viewable());
        $selected = $view->selected();
        $this->assertNotNull($selected);
        $this->assertNotNull($view->currency((int)$selected->currency_id));

        // The widget option resolves an explicit id or the primary, never a
        // silent fallback to another currency.
        $this->assertSame($this->currencyId, (int)$view->configuredCurrency(0)->currency_id);
        $this->assertSame(
            $this->currencyId,
            (int)$view->configuredCurrency($this->currencyId)->currency_id
        );
        $this->assertNull($view->configuredCurrency(999999));
    }

    public function testWalletViewOwnsBalanceAndAmountStep(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));
        $view = $this->walletView();
        // The suite runs against seeded members that may already hold credits,
        // so assert the delta instead of an absolute balance.
        $before = $view->balanceFor($this->memberId, $this->currencyId);

        $svc = \XF::app()->service('OpenCredits\Credits:Transact');
        $svc->adjust($this->memberId, $this->currencyId, 10.00, self::SEED_TRIGGER, 0, 'test');

        $decimals = (int)\XF::em()->find('OpenCredits\Credits:Currency', $this->currencyId)->decimals;

        $this->assertSame($before + 10.0, $view->balanceFor($this->memberId, $this->currencyId));
        $this->assertSame(0.0, $view->balanceFor($this->memberId, 999999));
        $this->assertSame(WalletView::amountStep($decimals), $view->step($decimals));
    }

    public function testWalletViewOwnsPagerUrls(): void
    {
        $view = $this->walletView();

        $standalone = $view->pager(false, $this->memberId, $this->currencyId, 20);
        $this->assertSame('credits', $standalone['pagerLink']);
        $this->assertSame(
            ['currency_id' => $this->currencyId, 'per_page' => 20],
            $standalone['pagerParams']
        );
        $this->assertStringContainsString('per_page=20', $standalone['perPageUrls'][20]);
        $this->assertStringContainsString('currency_id=' . $this->currencyId, $standalone['perPageUrls'][20]);

        $embedded = $view->pager(true, $this->memberId, $this->currencyId, 10);
        $this->assertSame('credits/member', $embedded['pagerLink']);
        $this->assertSame(
            ['user_id' => $this->memberId, 'currency_id' => $this->currencyId, 'per_page' => 10],
            $embedded['pagerParams']
        );
        $this->assertStringContainsString('user_id=' . $this->memberId, $embedded['perPageUrls'][50]);
        $this->assertStringContainsString('per_page=50', $embedded['perPageUrls'][50]);
    }

    public function testWalletViewOwnsTabMode(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));

        $options = \XF::app()->options();
        $originalMode = $options->ocProfileTabMode;
        $restore = $this->ensureTwoVisibleCurrencies();
        try {
            $this->assertSame('member', $this->walletView()->tabMode(false));

            $options->ocProfileTabMode = 'auto';
            $this->assertSame('member', $this->walletView()->tabMode(true));

            $options->ocProfileTabMode = 'wallet';
            $this->assertSame('wallet', $this->walletView()->tabMode(true));

            $options->ocProfileTabMode = 'member';
            $this->assertSame('member', $this->walletView()->tabMode(true));
        } finally {
            $options->ocProfileTabMode = $originalMode;
            $this->restoreCurrency($restore);
        }
    }

    public function testWalletPageRendersFromTheView(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));
        $this->seedWalletRows(25);

        $reply = $this->callIndex(1, $this->currencyId);
        $this->assertInstanceOf(\XF\Mvc\Reply\View::class, $reply);
        $this->assertSame('credits_index', $reply->getTemplateName());

        $html = $this->renderTrackedTemplate('credits_index', $reply->getParams());

        $this->assertStringContainsString('oc-credits-per-page', $html);
        $this->assertStringContainsString('per_page=50', $html);
        $this->assertStringContainsString('currency_id=' . $this->currencyId, $html);
        // The standalone wallet sends no profile-tab fields.
        $this->assertStringNotContainsString('oc_in_tab', $html);
        $this->assertStringNotContainsString('oc_return_user_id', $html);
    }

    public function testProfileTabWalletRendersTabFieldsFromTheView(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));

        $options = \XF::app()->options();
        $originalRedeem = $options->ocShowWalletRedeem;
        $originalTransfer = $options->ocShowTransfer;
        $originalTab = $options->ocShowProfileTab;
        $originalMode = $options->ocProfileTabMode;
        // Pin the dev-forum defaults the render path depends on: the tab must
        // be enabled and self must get the wallet, whatever the currency count.
        $options->ocShowProfileTab = true;
        $options->ocProfileTabMode = 'wallet';
        $options->ocShowWalletRedeem = true;
        $options->ocShowTransfer = true;

        try {
            $reply = $this->callMember($this->memberId);
            $this->assertInstanceOf(\XF\Mvc\Reply\View::class, $reply);
            $this->assertSame('credits_index', $reply->getTemplateName());

            $html = $this->renderTrackedTemplate('credits_index', $reply->getParams());

            $this->assertStringContainsString('name="oc_in_tab" value="1"', $html);
            $this->assertStringContainsString(
                'name="oc_return_user_id" value="' . $this->memberId . '"',
                $html
            );
            // Embedded pager links stay on the member route.
            $this->assertStringContainsString('credits/member', $html);
            $this->assertStringContainsString('user_id=' . $this->memberId, $html);
        } finally {
            $options->ocShowWalletRedeem = $originalRedeem;
            $options->ocShowTransfer = $originalTransfer;
            $options->ocShowProfileTab = $originalTab;
            $options->ocProfileTabMode = $originalMode;
        }
    }

    public function testRetiredTransferPageRedirectsToWallet(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));

        $request = new \XF\Http\Request(
            \XF::app()->inputFilterer(),
            ['currency_id' => $this->currencyId],
            [],
            [],
            ['REQUEST_METHOD' => 'GET']
        );
        $controller = \XF::app()->controller(Credits::class, $request);
        $reply = $controller->actionTransfer(new \XF\Mvc\ParameterBag([]));

        $this->assertInstanceOf(\XF\Mvc\Reply\Redirect::class, $reply);
        $this->assertStringContainsString('credits', $reply->getUrl());
        $this->assertStringNotContainsString('transfer', $reply->getUrl());
    }

    public function testRetiredRedeemPageRedirectsToWallet(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));

        $request = new \XF\Http\Request(
            \XF::app()->inputFilterer(),
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'GET']
        );
        $controller = \XF::app()->controller(Credits::class, $request);
        $reply = $controller->actionRedeem(new \XF\Mvc\ParameterBag([]));

        $this->assertInstanceOf(\XF\Mvc\Reply\Redirect::class, $reply);
        $this->assertStringContainsString('credits', $reply->getUrl());
    }

    public function testOwnTabIsPerMember(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));

        $restore = $this->ensureTwoVisibleCurrencies();
        try {
            $this->assertSame('member', $this->walletView()->tabMode(true));

            $params = $this->callMember($this->memberId)->getParams();
            $this->assertTrue($params['isSelf']);
            $this->assertSame('', $params['lockedTo']);
            $this->assertArrayNotHasKey('entries', $params);
            $this->assertArrayNotHasKey('page', $params);
        } finally {
            $this->restoreCurrency($restore);
        }
    }

    protected function restoreCurrency(array $restore): void
    {
        if (!$restore) {
            return;
        }
        $this->db->update(
            'xf_oc_currency',
            ['active' => (int)$restore['active'], 'visible' => (int)$restore['visible']],
            'currency_id = ?',
            [(int)$restore['currency_id']]
        );
        \XF::em()->clearEntityCache();
    }

    protected function ensureTwoVisibleCurrencies(): array
    {
        $count = (int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM xf_oc_currency WHERE active = 1 AND visible = 1'
        );
        if ($count > 1) {
            return [];
        }

        $row = $this->db->fetchRow(
            'SELECT currency_id, active, visible FROM xf_oc_currency WHERE currency_id <> ? ORDER BY currency_id LIMIT 1',
            [$this->currencyId]
        );
        if (!$row) {
            $this->markTestSkipped('Need a second currency to test the per-member tab.');
        }

        $this->db->update(
            'xf_oc_currency',
            ['active' => 1, 'visible' => 1],
            'currency_id = ?',
            [(int)$row['currency_id']]
        );
        \XF::em()->clearEntityCache();

        return $row;
    }

    public function testOtherMemberTabLocksSendForm(): void
    {
        \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));

        $other = \XF::em()->find('XF:User', $this->otherId);
        $params = $this->callMember($this->otherId)->getParams();
        $this->assertFalse($params['isSelf']);
        $this->assertSame($other->username, $params['lockedTo']);
        $this->assertSame($this->otherId, (int)$params['user']->user_id);
    }

    public function testTransferSaveIsRateLimited(): void
    {
        $sender = \XF::em()->find('XF:User', $this->otherId);
        $recipient = \XF::em()->find('XF:User', $this->memberId);
        $this->assertNotNull($sender);
        $this->assertNotNull($recipient);
        \XF::setVisitor($sender);

        // Pin the window so the test does not depend on the dev forum's
        // configured flood interval.
        $options = \XF::app()->options();
        $originalFlood = $options->floodCheckLength ?? 30;
        $options->floodCheckLength = 3600;

        $before = (int)$this->db->fetchOne(
            'SELECT COALESCE(MAX(transaction_id), 0) FROM xf_oc_transaction'
        );
        $svc = \XF::app()->service('OpenCredits\Credits:Transact');
        $svc->adjust($this->otherId, $this->currencyId, 10.00, 'octest_wallet_seed', 0, 'test');

        $makeRequest = function () use ($recipient) {
            return new \XF\Http\Request(
                \XF::app()->inputFilterer(),
                [
                    'to' => $recipient->username,
                    'amount' => 1,
                    'currency_id' => $this->currencyId,
                ],
                [],
                [],
                ['REQUEST_METHOD' => 'POST']
            );
        };

        try {
            $controller = \XF::app()->controller(Credits::class, $makeRequest());
            $reply = $controller->actionTransferSave(new \XF\Mvc\ParameterBag([]));
            $this->assertInstanceOf(\XF\Mvc\Reply\Redirect::class, $reply);
            $afterFirst = (int)$this->db->fetchOne('SELECT COALESCE(MAX(transaction_id), 0) FROM xf_oc_transaction');

            $controller = \XF::app()->controller(Credits::class, $makeRequest());
            try {
                $controller->actionTransferSave(new \XF\Mvc\ParameterBag([]));
                $this->fail('Second rapid transfer was not rate limited.');
            } catch (\XF\Mvc\Reply\Exception $e) {
                $this->assertInstanceOf(\XF\Mvc\Reply\Error::class, $e->getReply());
            }
            // Blocked attempt must not move money.
            $this->assertSame(
                $afterFirst,
                (int)$this->db->fetchOne('SELECT COALESCE(MAX(transaction_id), 0) FROM xf_oc_transaction')
            );
        } finally {
            $options->floodCheckLength = $originalFlood;
            $this->db->delete('xf_oc_transaction', 'transaction_id > ?', [$before]);
            $this->db->delete('xf_flood_check', 'user_id = ?', [$this->otherId]);
            $svc->rebuildAllBalancesFully();
        }
    }

    public function testTabSendFormRedirectsToRecipientTab(): void
    {
        $sender = \XF::em()->find('XF:User', $this->otherId);
        $recipient = \XF::em()->find('XF:User', $this->memberId);
        $this->assertNotNull($sender);
        $this->assertNotNull($recipient);
        \XF::setVisitor($sender);

        $before = (int)$this->db->fetchOne(
            'SELECT COALESCE(MAX(transaction_id), 0) FROM xf_oc_transaction'
        );
        $svc = \XF::app()->service('OpenCredits\Credits:Transact');
        $svc->adjust($this->otherId, $this->currencyId, 10.00, 'octest_wallet_seed', 0, 'test');

        try {
            $request = new \XF\Http\Request(
                \XF::app()->inputFilterer(),
                [
                    'to' => $recipient->username,
                    'amount' => 1,
                    'currency_id' => $this->currencyId,
                    'oc_in_tab' => 1,
                ],
                [],
                [],
                ['REQUEST_METHOD' => 'POST']
            );
            $controller = \XF::app()->controller(Credits::class, $request);
            $reply = $controller->actionTransferSave(new \XF\Mvc\ParameterBag([]));

            $this->assertInstanceOf(\XF\Mvc\Reply\Redirect::class, $reply);
            $this->assertStringContainsString($recipient->username, $reply->getUrl());
            $this->assertStringContainsString('#credits', $reply->getUrl());
        } finally {
            $this->db->delete('xf_oc_transaction', 'transaction_id > ?', [$before]);
            $svc->rebuildAllBalancesFully();
        }
    }

    public function testWalletTransferInProfileTabReturnsToSender(): void
    {
        $sender = \XF::em()->find('XF:User', $this->otherId);
        $recipient = \XF::em()->find('XF:User', $this->memberId);
        $this->assertNotNull($sender);
        $this->assertNotNull($recipient);
        \XF::setVisitor($sender);

        $before = (int)$this->db->fetchOne(
            'SELECT COALESCE(MAX(transaction_id), 0) FROM xf_oc_transaction'
        );
        $svc = \XF::app()->service('OpenCredits\Credits:Transact');
        $svc->adjust($this->otherId, $this->currencyId, 10.00, 'octest_wallet_seed', 0, 'test');

        try {
            $request = new \XF\Http\Request(
                \XF::app()->inputFilterer(),
                [
                    'to' => $recipient->username,
                    'amount' => 1,
                    'currency_id' => $this->currencyId,
                    'oc_in_tab' => 1,
                    'oc_return_user_id' => $this->otherId,
                ],
                [],
                [],
                ['REQUEST_METHOD' => 'POST']
            );
            $controller = \XF::app()->controller(Credits::class, $request);
            $reply = $controller->actionTransferSave(new \XF\Mvc\ParameterBag([]));

            $this->assertInstanceOf(\XF\Mvc\Reply\Redirect::class, $reply);
            $this->assertStringContainsString(strtolower($sender->username), strtolower($reply->getUrl()));
            $this->assertStringContainsString('#credits', $reply->getUrl());
            $this->assertStringNotContainsString(strtolower($recipient->username), strtolower($reply->getUrl()));
        } finally {
            $this->db->delete('xf_oc_transaction', 'transaction_id > ?', [$before]);
            $svc->rebuildAllBalancesFully();
        }
    }

    public function testSelfTabUsesWalletWithOneCurrency(): void
    {
        $others = $this->useSingleCurrency();

        try {
            \XF::setVisitor(\XF::em()->find('XF:User', $this->memberId));

            $view = $this->walletView();
            $this->assertSame('wallet', $view->tabMode(true));
            $this->assertFalse($view->showFlags(true)['showBalance']);

            $self = $this->callMember($this->memberId);
            $this->assertInstanceOf(\XF\Mvc\Reply\View::class, $self);
            $this->assertSame('credits_index', $self->getTemplateName());

            // Other members keep the per-member card.
            $this->assertSame('member', $view->tabMode(false));
            $other = $this->callMember($this->otherId);
            $this->assertSame('credits_member', $other->getTemplateName());
        } finally {
            $this->restoreCurrencies($others);
        }
    }

    /**
     * Deactivate every currency but the test's primary one.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function useSingleCurrency(): array
    {
        $others = $this->db->fetchAll(
            'SELECT currency_id, active, visible FROM xf_oc_currency WHERE currency_id <> ?',
            [$this->currencyId]
        );
        $this->db->update('xf_oc_currency', ['active' => 0], 'currency_id <> ?', [$this->currencyId]);
        \XF::em()->clearEntityCache();

        return $others;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function restoreCurrencies(array $rows): void
    {
        foreach ($rows AS $row) {
            $this->db->update(
                'xf_oc_currency',
                ['active' => (int)$row['active'], 'visible' => (int)$row['visible']],
                'currency_id = ?',
                [(int)$row['currency_id']]
            );
        }
        \XF::em()->clearEntityCache();
    }

    protected function walletView(): WalletView
    {
        return new WalletView(\XF::app());
    }

    /**
     * Renders the tracked `_output` template source, so a stale compiled copy
     * cannot hide a template regression.
     *
     * @param array<string, mixed> $params
     */
    protected function renderTrackedTemplate(string $title, array $params): string
    {
        $addonRoot = getenv('OC_ADDON_SRC') ?: __DIR__ . '/../../../addon-src/OpenCredits/Credits';
        $template = $addonRoot . '/_output/templates/public/' . $title . '.html';
        if (!is_file($template)) {
            $template = \XF::getAddOnDirectory() . '/OpenCredits/Credits/_output/templates/public/' . $title . '.html';
        }

        $source = file_get_contents($template);
        if ($source === false) {
            $this->fail('Could not read the tracked ' . $title . ' template source.');
        }

        $compiled = \XF::app()->templateCompiler()->compile($source, \XF::app()->language());
        /** @var array{code: callable} $data */
        $data = eval($compiled);

        // The compiled standalone source has no current template context, so
        // nested renderers (pagenav) would emit a nameless template. Give the
        // templater the same type:name it would have during a real render.
        $templater = \XF::app()->templater();
        $postRender = $templater->setupRenderTemplateElement('public', $title);
        try {
            return $data['code']($templater, $params);
        } finally {
            $postRender();
        }
    }

    protected function callIndex(int $page, int $currencyId = 0, ?int $perPage = null): \XF\Mvc\Reply\AbstractReply
    {
        $input = ['page' => $page, 'currency_id' => $currencyId];
        if ($perPage !== null) {
            $input['per_page'] = $perPage;
        }

        $request = new \XF\Http\Request(
            \XF::app()->inputFilterer(),
            $input,
            [],
            [],
            ['REQUEST_METHOD' => 'GET']
        );
        $controller = \XF::app()->controller(Credits::class, $request);
        return $controller->actionIndex(new \XF\Mvc\ParameterBag([]));
    }

    protected function callMember(int $userId): \XF\Mvc\Reply\AbstractReply
    {
        $request = new \XF\Http\Request(
            \XF::app()->inputFilterer(),
            ['user_id' => $userId],
            [],
            [],
            ['REQUEST_METHOD' => 'GET']
        );
        $controller = \XF::app()->controller(Credits::class, $request);
        return $controller->actionMember(new \XF\Mvc\ParameterBag([]));
    }

    protected function seedWalletRows(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->db->insert('xf_oc_transaction', [
                'user_id' => $this->memberId,
                'currency_id' => $this->currencyId,
                'amount' => 1,
                'trigger' => self::SEED_TRIGGER,
                'content_type' => '',
                'content_id' => 0,
                'event_id' => 0,
                'note' => 'test',
                'log_date' => \XF::$time + $i,
            ]);
        }
    }

    protected function cleanup(): void
    {
        if ($this->memberId) {
            $this->db->delete(
                'xf_oc_transaction',
                'user_id = ? AND `trigger` = ?',
                [$this->memberId, self::SEED_TRIGGER]
            );
            // Rate limiting writes xf_flood_check rows on every save action;
            // clear them so back-to-back tests do not throttle each other.
            $this->db->delete(
                'xf_flood_check',
                'user_id IN (?, ?)',
                [$this->memberId, $this->otherId]
            );
            \XF::app()->service('OpenCredits\Credits:Transact')->rebuildAllBalancesFully();
        }
    }
}
