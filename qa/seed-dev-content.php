<?php

// Throwaway dev-forum seeder for content-dependent integration tests
// (backfill, charge-render). Creates one member, one visible thread, and one
// reply, or removes them again. Ledger rows from the seeded awards stay as
// financial history by design; run `clean` to remove the content itself.
//
//   OC_XF_ROOT=/var/www/html php seed-dev-content.php seed
//   OC_XF_ROOT=/var/www/html php seed-dev-content.php clean
//
// Runs from the `.qatmp` copy (`qa/tests/README.md`); never shipped.

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
    $user = \XF::em()->findOne('XF:User', ['username' => 'octest_seed']);
    if (!$user) {
        $user = \XF::repository('XF:User')->setupBaseUser();
        $user->username = 'octest_seed';
        $user->email = 'octest-seed@example.invalid';
        $user->user_group_id = 2;
        $user->Auth->setPassword(bin2hex(random_bytes(8)));
        $user->save();
    }
    \XF::setVisitor($user);

    $forum = \XF::finder('XF:Forum')->order('node_id')->fetchOne();
    if (!$forum) {
        fwrite(STDERR, "No forum node to seed into.\n");
        exit(1);
    }

    $creator = \XF::app()->service(\XF\Service\Thread\CreatorService::class, $forum);
    $creator->setContent('OC seed thread', 'Seed body for backfill verification.');
    if (!$creator->validate($errors)) {
        fwrite(STDERR, 'thread: ' . json_encode($errors) . "\n");
        exit(1);
    }
    $creator->save();

    $replier = \XF::app()->service(\XF\Service\Thread\ReplierService::class, $creator->getThread());
    $replier->setMessage('Seed reply for backfill verification.');
    if (!$replier->validate($errors)) {
        fwrite(STDERR, 'post: ' . json_encode($errors) . "\n");
        exit(1);
    }
    $replier->save();

    echo "seeded user={$user->user_id} thread={$creator->getThread()->thread_id}\n";
}

function clean(): void
{
    $app = \XF::app();
    foreach (\XF::finder('XF:Thread')->where('title', 'OC seed thread')->fetch() as $thread) {
        $app->service(\XF\Service\Thread\DeleterService::class, $thread)->delete('hard', '');
        echo "thread {$thread->thread_id} deleted\n";
    }
    $user = \XF::em()->findOne('XF:User', ['username' => 'octest_seed']);
    if ($user) {
        $app->service(\XF\Service\User\DeleteService::class, $user)->delete($errors);
        echo empty($errors) ? "user deleted\n" : 'user errors: ' . json_encode($errors) . "\n";
    }
    $app->service('OpenCredits\Credits:Transact')->rebuildAllBalancesFully();
    echo "rebuilt\n";
}
