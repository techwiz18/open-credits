<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\BbCode\Charge;
use OpenCredits\Credits\Service\Transact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Renderer checks for the [charge] soft gate: guest/member/paid/author/admin
 * gates plus the fail-closed states (inactive currency, invalid price).
 *
 * Uses one existing charge-free post and real members, seeds only its own
 * xf_oc_charge rows, and restores currency flags in tearDown(). Dev-only;
 * skips without OC_XF_ROOT.
 */
class ChargeRenderTest extends TestCase
{
    protected const MESSAGE = '[charge=5]RENDER_SECRET[/charge]';

    /** @var \XF\Db\AbstractAdapter */
    protected $db;

    protected int $postId = 0;
    protected int $authorId = 0;
    protected int $memberId = 0;
    protected int $member2Id = 0;
    protected int $adminId = 0;
    protected int $currencyId = 0;

    /** @var array<int, array{is_primary: int, active: int}> */
    protected array $originalCurrencies = [];

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

        $rows = $this->db->fetchAll('SELECT currency_id, is_primary, active FROM xf_oc_currency');
        foreach ($rows AS $row) {
            $this->originalCurrencies[(int)$row['currency_id']] = [
                'is_primary' => (int)$row['is_primary'],
                'active' => (int)$row['active'],
            ];
        }

        $this->postId = (int)$this->db->fetchOne(
            "SELECT p.post_id FROM xf_post AS p"
            . " LEFT JOIN xf_oc_charge AS c ON c.content_type = 'post' AND c.content_id = p.post_id"
            . " WHERE p.message_state = 'visible'"
            . " GROUP BY p.post_id HAVING COUNT(c.charge_id) = 0"
            . " ORDER BY p.post_id LIMIT 1"
        );
        if ($this->postId <= 0) {
            $this->markTestSkipped('No charge-free visible post available for the render test.');
        }
        $this->authorId = (int)$this->db->fetchOne(
            'SELECT user_id FROM xf_post WHERE post_id = ?',
            [$this->postId]
        );

        $this->memberId = $this->findUser(
            'user_id <> ? AND is_staff = 0 AND is_admin = 0 AND is_moderator = 0',
            [$this->authorId]
        );
        $this->member2Id = $this->findUser(
            'user_id <> ? AND user_id <> ? AND is_staff = 0 AND is_admin = 0 AND is_moderator = 0',
            [$this->authorId, $this->memberId]
        );
        $this->adminId = $this->findUser('user_id <> ? AND (is_admin = 1 OR is_staff = 1)', [$this->authorId]);

        if ($this->authorId <= 0 || $this->memberId <= 0 || $this->adminId <= 0) {
            $this->markTestSkipped('Need an author, a regular member, and an admin/staff user.');
        }

        $this->db->delete(
            'xf_oc_charge',
            "content_type = 'post' AND content_id = ?",
            [$this->postId]
        );
    }

    protected function tearDown(): void
    {
        if ($this->postId) {
            $this->db->delete(
                'xf_oc_charge',
                "content_type = 'post' AND content_id = ?",
                [$this->postId]
            );
        }
        foreach ($this->originalCurrencies AS $currencyId => $flags) {
            $this->db->update(
                'xf_oc_currency',
                ['is_primary' => $flags['is_primary'], 'active' => $flags['active']],
                'currency_id = ?',
                [$currencyId]
            );
        }
        Transact::clearPrimaryCache();
        \XF::em()->clearEntityCache();
        \XF::setVisitor(null);
        Charge::clearPaidCache();
    }

    public function testGuestSeesLockedBoxWithoutBody(): void
    {
        $html = $this->render(null);
        $this->assertStringNotContainsString('RENDER_SECRET', $html);
        $this->assertTrue(
            strpos($html, 'Log in') !== false || strpos($html, 'credits/unlock') !== false,
            'Guest should see a login/unlock prompt'
        );
    }

    public function testNonPaidMemberSeesLockedBoxWithoutBody(): void
    {
        $html = $this->render($this->memberId);
        $this->assertStringNotContainsString('RENDER_SECRET', $html);
        $this->assertStringContainsString('Premium content', $html);
    }

    public function testPaidMemberSeesBody(): void
    {
        $this->seedCharge($this->memberId, 5.00);
        $html = $this->render($this->memberId);
        $this->assertStringContainsString('RENDER_SECRET', $html);
    }

    public function testAuthorSeesBodyAndStats(): void
    {
        $this->seedCharge($this->memberId, 5.00);
        $html = $this->render($this->authorId);
        $this->assertStringContainsString('RENDER_SECRET', $html);
        $this->assertStringContainsString('member unlocked this content', $html);
    }

    public function testAdminBypassesWithoutPaying(): void
    {
        $html = $this->render($this->adminId);
        $this->assertStringContainsString('RENDER_SECRET', $html);
    }

    public function testInactiveCurrencyFailsClosedButPaidKeepAccess(): void
    {
        if ($this->member2Id <= 0) {
            $this->markTestSkipped('Need a second regular member.');
        }
        $this->seedCharge($this->memberId, 5.00);
        $this->db->update('xf_oc_currency', ['active' => 0], 'currency_id = ?', [$this->currencyId]);
        \XF::em()->clearEntityCache();

        $paidHtml = $this->render($this->memberId);
        $this->assertStringContainsString('RENDER_SECRET', $paidHtml, 'Paid buyers keep access');

        $freeHtml = $this->render($this->member2Id);
        $this->assertStringNotContainsString('RENDER_SECRET', $freeHtml);
        $this->assertStringContainsString('temporarily unavailable', $freeHtml);
    }

    public function testInvalidPriceFailsClosedForNonPaid(): void
    {
        $zeroDecimal = (int)$this->db->fetchOne(
            'SELECT currency_id FROM xf_oc_currency WHERE decimals = 0 AND active = 1 AND currency_id <> ? LIMIT 1',
            [$this->currencyId]
        );
        if ($zeroDecimal <= 0) {
            $this->markTestSkipped('No 0-decimal currency available.');
        }

        $this->db->update('xf_oc_currency', ['is_primary' => 0], 'currency_id = ?', [$this->currencyId]);
        $this->db->update('xf_oc_currency', ['is_primary' => 1], 'currency_id = ?', [$zeroDecimal]);
        Transact::clearPrimaryCache();
        \XF::em()->clearEntityCache();

        $message = '[charge=1.5]RENDER_SECRET[/charge]';
        $memberHtml = $this->render($this->memberId, $message);
        $this->assertStringNotContainsString('RENDER_SECRET', $memberHtml);
        $this->assertStringContainsString('invalid price', $memberHtml);

        $authorHtml = $this->render($this->authorId, $message);
        $this->assertStringContainsString('RENDER_SECRET', $authorHtml);
    }

    #[DataProvider('blockedPayerStates')]
    public function testBlockedMemberCannotUnlock(bool $banned, string $state): void
    {
        $user = \XF::em()->find('XF:User', $this->memberId);
        $this->assertNotNull($user);
        $user->is_banned = $banned;
        $user->user_state = $state;
        \XF::setVisitor($user);

        $request = new \XF\Http\Request(
            \XF::app()->inputFilterer(),
            ['content_type' => 'post', 'content_id' => $this->postId, 'amount' => 5],
            [],
            [],
            ['REQUEST_METHOD' => 'POST']
        );
        $controller = \XF::app()->controller(\OpenCredits\Credits\Pub\Controller\Credits::class, $request);
        $reply = $controller->actionUnlock(new \XF\Mvc\ParameterBag([]));

        $this->assertInstanceOf(\XF\Mvc\Reply\Error::class, $reply);
        $this->assertSame(403, $reply->getResponseCode());
    }

    public static function blockedPayerStates(): array
    {
        return [
            [true, 'valid'],
            [false, 'email_confirm'],
        ];
    }

    protected function render(?int $userId, string $message = self::MESSAGE): string
    {
        \XF::setVisitor($userId ? \XF::em()->find('XF:User', $userId) : null);
        Charge::clearPaidCache();

        $post = \XF::em()->find('XF:Post', $this->postId);
        return (string)\XF::app()->bbCode()->render($message, 'html', 'post:post', $post);
    }

    protected function seedCharge(int $userId, float $amount): void
    {
        $this->db->insert('xf_oc_charge', [
            'content_type' => 'post',
            'content_id' => $this->postId,
            'user_id' => $userId,
            'author_user_id' => $this->authorId,
            'currency_id' => $this->currencyId,
            'amount' => number_format($amount, 2, '.', ''),
            'charge_date' => \XF::$time,
        ]);
    }

    protected function findUser(string $where, array $params): int
    {
        return (int)$this->db->fetchOne(
            "SELECT user_id FROM xf_user WHERE user_state = 'valid' AND is_banned = 0 AND {$where}"
            . ' ORDER BY user_id LIMIT 1',
            $params
        );
    }
}
