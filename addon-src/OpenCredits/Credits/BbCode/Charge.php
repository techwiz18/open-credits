<?php

declare(strict_types=1);

namespace OpenCredits\Credits\BbCode;

use XF\BbCode\Renderer\Html;
use XF\Mvc\Entity\Entity;

/**
 * [charge=AMOUNT]…[/charge] renders its contents only to members who have
 * paid, to the author, or to exempt members. Locked content is never rendered
 * into the page source; the box just offers the unlock form.
 *
 * v1 scope: forum posts, primary currency, one payment per
 * (post, member, amount). Paying a given price unlocks every charge block at
 * that price in the post.
 */
class Charge
{
    /** @var array<string, array<string, bool>> */
    protected static $paid = [];

    public static function render(array $children, $option, array $tag, array $options, Html $renderer)
    {
        $amount = (float)$option;
        if ($amount <= 0) {
            return self::renderChildren($children, $options, $renderer);
        }

        $entity = $options['entity'] ?? null;
        // Only forum posts have a stable content identity we can charge against.
        if (!$entity instanceof \XF\Entity\Post) {
            return self::renderChildren($children, $options, $renderer);
        }

        $authorId = self::authorId($options, $entity);
        if ($authorId <= 0) {
            return self::renderChildren($children, $options, $renderer);
        }

        $currency = self::primaryCurrency();
        if (!$currency) {
            return self::renderChildren($children, $options, $renderer);
        }

        $amount = round($amount, (int)$currency->decimals);
        if ($amount <= 0) {
            return self::renderChildren($children, $options, $renderer);
        }

        $visitor = \XF::visitor();

        // Author, staff, and explicitly-permitted members see it free.
        if ($visitor->user_id && (
            (int)$visitor->user_id === $authorId
            || $visitor->is_staff
            || $visitor->hasPermission('general', 'ocChargeFree')
        )) {
            return self::renderChildren($children, $options, $renderer);
        }

        $contentType = (string)$entity->getEntityContentType();
        $contentId = (int)$entity->getEntityId();
        $currencyId = (int)$currency->currency_id;

        if ($visitor->user_id
            && self::hasPaid((int)$visitor->user_id, $contentType, $contentId, $currencyId, $amount)) {
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
    }

    public static function clearPaidCache(): void
    {
        self::$paid = [];
    }

    protected static function renderChildren(array $children, array $options, Html $renderer): string
    {
        return $renderer->renderSubTree($children, $options);
    }

    protected static function authorId(array $options, Entity $entity): int
    {
        $user = $options['user'] ?? null;
        if ($user instanceof \XF\Entity\User) {
            return (int)$user->user_id;
        }
        return (int)($entity->user_id ?? 0);
    }

    protected static function hasPaid(int $userId, string $contentType, int $contentId, int $currencyId, float $amount): bool
    {
        $key = $userId . ':' . $contentType . ':' . $contentId . ':' . $currencyId;
        if (!isset(self::$paid[$key])) {
            self::$paid[$key] = self::transact()->paidAmountsFor($userId, $contentType, $contentId, $currencyId);
        }
        return isset(self::$paid[$key][sprintf('%.2f', $amount)]);
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
