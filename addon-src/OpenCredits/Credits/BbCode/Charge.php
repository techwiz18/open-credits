<?php

declare(strict_types=1);

namespace OpenCredits\Credits\BbCode;

use XF\BbCode\Renderer\Html;
use XF\Mvc\Entity\Entity;

/**
 * [charge=AMOUNT]…[/charge] shows its contents to members who have paid, the
 * author, staff, and exempt members, and shows a locked box to everyone else.
 *
 * This is a credits-gated display ("premium content"), not content protection:
 * the text remains in the post message and can appear in raw post output,
 * snippets, quotes, and the API. Do not market it as a hard paywall.
 *
 * v1 scope: forum posts, primary currency, one purchase per (post, member).
 */
class Charge
{
    /** @var array<string, bool> */
    protected static $paid = [];

    /** @var array<string, int> */
    protected static $unlockCounts = [];

    /**
     * Charge prices found in a message, using XenForo's own parser/AST so the
     * result matches what actually renders: `[code]`/`[plain]` contents are
     * skipped and malformed tags (e.g. spaces around `=`) are ignored.
     *
     * @return string[]
     */
    public static function amountsInMessage(string $message): array
    {
        try {
            $app = \XF::app();
            $parser = $app->bbCode()->parser();
            $rules = $app->bbCode()->rules('post:post');
            $ast = $parser->parse($message, $rules);
        } catch (\Throwable $e) {
            return [];
        }

        $amounts = [];
        self::collectAmounts($ast, $amounts);
        return array_values(array_unique($amounts));
    }

    public static function render(array $children, $option, array $tag, array $options, Html $renderer)
    {
        $visitor = \XF::visitor();

        // Members without the Credits view permission should not see the
        // premium-content UI at all. Guests still get the "log in" box.
        if ($visitor->user_id && !$visitor->hasPermission('general', 'ocView')) {
            return '';
        }

        $entity = $options['entity'] ?? null;
        // Only forum posts carry the content identity this feature charges for.
        if (!$entity instanceof \XF\Entity\Post) {
            return self::renderChildren($children, $options, $renderer);
        }

        $authorId = self::authorId($options, $entity);
        if ($authorId <= 0) {
            return self::renderChildren($children, $options, $renderer);
        }

        $isAuthor = $visitor->user_id && (int)$visitor->user_id === $authorId;
        $isExempt = $isAuthor
            || $visitor->is_staff
            || $visitor->is_admin
            || $visitor->is_moderator
            || $visitor->hasPermission('general', 'ocChargeFree');

        try {
            $currency = self::primaryCurrency();
            if (!$currency) {
                return $isExempt
                    ? self::renderChildren($children, $options, $renderer)
                    : self::unavailable('oc_charge_unavailable');
            }

            $rawAmount = (float)$option;
            $amount = round($rawAmount, (int)$currency->decimals);
            $validPrice = $rawAmount > 0
                && $amount > 0
                && abs($rawAmount - $amount) < 0.00001;
            if (!$validPrice) {
                return $isExempt
                    ? self::renderChildren($children, $options, $renderer)
                    : self::unavailable('oc_charge_invalid_price');
            }

            $contentType = (string)$entity->getEntityContentType();
            $contentId = (int)$entity->getEntityId();
            $currencyId = (int)$currency->currency_id;

            if ($isExempt) {
                $html = self::renderChildren($children, $options, $renderer);
                if ($isAuthor) {
                    $html .= self::authorStats($contentType, $contentId, $currencyId, $amount, $currency);
                }
                return $html;
            }

            if ($visitor->user_id
                && self::hasPaid((int)$visitor->user_id, $contentType, $contentId, $currencyId)) {
                return self::renderChildren($children, $options, $renderer);
            }

            $balance = $visitor->user_id ? self::transact()->getBalance((int)$visitor->user_id, $currencyId) : 0.0;

            return \XF::app()->templater()->renderTemplate('public:oc_charge_locked', [
                'amount' => $amount,
                'price' => $currency->format($amount),
                'currency' => $currency,
                'contentType' => $contentType,
                'contentId' => $contentId,
                'currencyId' => $currencyId,
                'isGuest' => !$visitor->user_id,
                'canAfford' => $balance >= $amount,
                'balance' => $balance,
                'balanceFormatted' => $currency->format($balance),
            ]);
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits charge render failed: ');
            return self::unavailable('oc_charge_unavailable');
        }
    }

    public static function clearPaidCache(): void
    {
        self::$paid = [];
        self::$unlockCounts = [];
    }

    protected static function collectAmounts(array $tree, array &$out): void
    {
        foreach ($tree AS $node) {
            if (!is_array($node)) {
                continue;
            }
            $tag = (string)($node['tag'] ?? '');
            if ($tag === 'charge') {
                $out[] = (string)($node['option'] ?? '');
                continue;
            }
            if ($tag === 'code' || $tag === 'plain') {
                continue;
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                self::collectAmounts($node['children'], $out);
            }
        }
    }

    protected static function renderChildren(array $children, array $options, Html $renderer): string
    {
        return $renderer->renderSubTree($children, $options);
    }

    protected static function unavailable(string $phrase): string
    {
        try {
            return \XF::app()->templater()->renderTemplate('public:oc_charge_unavailable', [
                'message' => \XF::phrase($phrase)->render(),
            ]);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Author-only line showing how many members paid and how much that earned.
     * Rendered per charge block (i.e. per price).
     */
    protected static function authorStats(
        string $contentType,
        int $contentId,
        int $currencyId,
        float $amount,
        $currency
    ): string {
        $key = $contentType . ':' . $contentId . ':' . $currencyId . ':' . sprintf('%.2f', $amount);
        if (!isset(self::$unlockCounts[$key])) {
            self::$unlockCounts[$key] = self::transact()->unlockCountFor(
                $contentType,
                $contentId,
                $currencyId,
                $amount
            );
        }
        $count = self::$unlockCounts[$key];
        if ($count <= 0) {
            return '';
        }
        return \XF::app()->templater()->renderTemplate('public:oc_charge_author_stats', [
            'count' => $count,
            'amount' => $amount,
            'total' => $currency->format($count * $amount),
        ]);
    }

    protected static function authorId(array $options, Entity $entity): int
    {
        $user = $options['user'] ?? null;
        if ($user instanceof \XF\Entity\User) {
            return (int)$user->user_id;
        }
        return (int)($entity->user_id ?? 0);
    }

    protected static function hasPaid(int $userId, string $contentType, int $contentId, int $currencyId): bool
    {
        $key = $userId . ':' . $contentType . ':' . $contentId . ':' . $currencyId;
        if (!isset(self::$paid[$key])) {
            self::$paid[$key] = self::transact()->hasPaidForContent(
                $userId,
                $contentType,
                $contentId,
                $currencyId
            );
        }
        return self::$paid[$key];
    }

    protected static function primaryCurrency()
    {
        $currencyId = self::transact()->primaryCurrencyId();
        $currency = \XF::app()->em()->find('OpenCredits\Credits:Currency', $currencyId);
        if (!$currency || !$currency->active) {
            return null;
        }
        return $currency;
    }

    protected static function transact()
    {
        return \XF::app()->service('OpenCredits\Credits:Transact');
    }
}
