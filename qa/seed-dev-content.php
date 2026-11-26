<?php

// Throwaway dev-forum seeder for content-dependent integration tests
// (backfill, charge-render). Creates three members, a visible thread, a reply,
// and an inactive 0-decimal currency for the invalid-price test, or removes
// them again. Ledger rows from the seeded awards stay as financial history by
// design; run `clean` to remove the content itself.
//
//   OC_XF_ROOT=/var/www/html php seed-dev-content.php seed
//   OC_XF_ROOT=/var/www/html php seed-dev-content.php clean
//
// Runs from the `.qatmp` copy (`scripts/dev.sh test seed`); never shipped.

const SEED_THREAD_TITLE = 'OC seed thread';
const SEED_CURRENCY_TITLE = 'OC seed zero-decimal';
// Author plus two plain members: the render/unlock tests need a non-author
// reader, and one of them needs a second reader for the paid-vs-free split.
const SEED_MEMBER_NAMES = ['octest_seed', 'octest_buyer', 'octest_buyer2'];

require __DIR__ . '/tests/integration-bootstrap.php';

if (!class_exists('XF', false) || !\XF::app()) {
    fwrite(STDERR, "Set OC_XF_ROOT to a licensed XenForo install.\n");
    exit(1);
}
\XF::$time = \XF::$time ?: time();

$action = $argv[1] ?? '';
if ($action === 'seed') {
    seed();
} elseif ($action === 'clean') {
    clean();
} else {
    fwrite(STDERR, "Usage: seed-dev-content.php seed|clean\n");
    exit(1);
}

function seed(): void
{
    // 0-decimal currency for
    // ChargeRenderTest::testInvalidPriceFailsClosedForNonPaid. Inactive so it
    // can never become primary by accident.
    $zeroCurrency = \XF::em()->findOne('OpenCredits\Credits:Currency', ['title' => SEED_CURRENCY_TITLE]);
    if (!$zeroCurrency) {
        $zeroCurrency = \XF::em()->create('OpenCredits\Credits:Currency');
        $zeroCurrency->bulkSet([
            'title' => SEED_CURRENCY_TITLE,
            'prefix' => '$',
            'decimals' => 0,
            'allow_negative' => 0,
            'active' => 0,
            'is_primary' => 0,
            'visible' => 0,
        ]);
        $zeroCurrency->save();
    }

    $users = [];
    foreach (SEED_MEMBER_NAMES AS $username) {
        $users[$username] = ensureMember($username);
    }
    $user = $users['octest_seed'];
    \XF::setVisitor($user);

    $thread = \XF::finder('XF:Thread')->where('title', SEED_THREAD_TITLE)->fetchOne();
    if ($thread) {
        // A previous run parked purchases on these posts. Clear them so the
        // charge tests get charge-free posts again without a full clean.
        \XF::db()->query(
            "DELETE c FROM xf_oc_charge AS c INNER JOIN xf_post AS p ON p.post_id = c.content_id"
            . " WHERE c.content_type = 'post' AND p.thread_id = ?",
            $thread->thread_id
        );
    }
    if (!$thread) {
        $forum = \XF::finder('XF:Forum')->order('node_id')->fetchOne();
        if (!$forum) {
            fwrite(STDERR, "No forum node to seed into.\n");
            exit(1);
        }

        $creator = \XF::app()->service(\XF\Service\Thread\CreatorService::class, $forum);
        $creator->setContent(SEED_THREAD_TITLE, 'Seed body for backfill verification.');
        if (!$creator->validate($errors)) {
            fwrite(STDERR, 'thread: ' . json_encode($errors) . "\n");
            exit(1);
        }
        $creator->save();
        $thread = $creator->getThread();

        // Three replies: each charge test parks its purchase on a different
        // post, and the tests run in random order.
        for ($i = 1; $i <= 3; $i++) {
            $replier = \XF::app()->service(\XF\Service\Thread\ReplierService::class, $thread);
            $replier->setMessage("Seed reply {$i} for backfill verification.");
            if (!$replier->validate($errors)) {
                fwrite(STDERR, 'post: ' . json_encode($errors) . "\n");
                exit(1);
            }
            $replier->save();
        }
    }

    echo 'seeded users=' . implode(',', array_map(
        static function ($seeded) {
            return $seeded->user_id;
        },
        $users
    )) . " thread={$thread->thread_id} zero_currency={$zeroCurrency->currency_id}\n";
}

function ensureMember(string $username)
{
    $user = \XF::em()->findOne('XF:User', ['username' => $username]);
    if ($user) {
        return $user;
    }
    $user = \XF::repository('XF:User')->setupBaseUser();
    $user->username = $username;
    $user->email = $username . '@example.invalid';
    $user->user_group_id = 2;
    $user->Auth->setPassword(bin2hex(random_bytes(8)));
    $user->save();
    return $user;
}

function clean(): void
{
    $app = \XF::app();
    foreach (\XF::finder('XF:Thread')->where('title', SEED_THREAD_TITLE)->fetch() as $thread) {
        $app->service(\XF\Service\Thread\DeleterService::class, $thread)->delete('hard', '');
        echo "thread {$thread->thread_id} deleted\n";
    }
    $currency = \XF::em()->findOne('OpenCredits\Credits:Currency', ['title' => SEED_CURRENCY_TITLE]);
    if ($currency) {
        $currencyId = (int)$currency->currency_id;
        \XF::db()->delete('xf_oc_balance', 'currency_id = ?', [$currencyId]);
        $currency->delete();
        echo "currency {$currencyId} deleted\n";
    }
    foreach (SEED_MEMBER_NAMES AS $username) {
        $user = \XF::em()->findOne('XF:User', ['username' => $username]);
        if ($user) {
            $app->service(\XF\Service\User\DeleteService::class, $user)->delete($errors);
            echo empty($errors) ? "{$username} deleted\n" : "{$username} errors: " . json_encode($errors) . "\n";
        }
    }
    \OpenCredits\Credits\Service\Transact::clearPrimaryCache();
    $app->service('OpenCredits\Credits:Transact')->rebuildAllBalancesFully();
    echo "rebuilt\n";
}
