<?php

declare(strict_types=1);

namespace OpenCredits\Credits\XF\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\View;

class ThreadController extends XFCP_ThreadController
{
    public function actionIndex(ParameterBag $params): AbstractReply
    {
        $reply = parent::actionIndex($params);
        if (!$reply instanceof View) {
            return $reply;
        }
        // Same gates as the postbit template mod: skip the preload query
        // entirely when the balances would not render.
        $visitor = \XF::visitor();
        if (!$visitor->user_id
            || !$visitor->hasPermission('general', 'ocView')
            || empty($this->options()->ocShowPostbit)
        ) {
            return $reply;
        }
        try {
            $viewParams = $reply->getParams();
            $userIds = [];
            foreach (['posts', 'highlightedPosts'] as $key) {
                $posts = $viewParams[$key] ?? null;
                if ($posts) {
                    foreach ($posts as $post) {
                        $userIds[] = (int)$post->user_id;
                    }
                }
            }
            foreach (['firstPost', 'pinnedPost'] as $key) {
                $post = $viewParams[$key] ?? null;
                if ($post) {
                    $userIds[] = (int)$post->user_id;
                }
            }
            if ($userIds) {
                /** @var \OpenCredits\Credits\Service\Transact $svc */
                $svc = $this->service('OpenCredits\Credits:Transact');
                $svc->preloadBalances($userIds, true);
            }
        } catch (\Throwable $e) {
            // Preloading is an optimization only: the per-author getter
            // still works uncached, so never break thread view over it.
            \XF::logException($e, false, 'OpenCredits balance preload failed: ');
        }
        return $reply;
    }
}
