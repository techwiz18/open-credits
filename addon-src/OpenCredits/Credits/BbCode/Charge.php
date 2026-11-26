<?php

declare(strict_types=1);

namespace OpenCredits\Credits\BbCode;

use OpenCredits\Credits\Service\ChargeAccess;
use OpenCredits\Credits\Service\ContentCharge;
use XF\BbCode\Renderer\Html;

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
    /** @var array<string, int> */
    protected static $unlockCounts = [];

    /** @var array<string, float|null> */
    protected static $maxAmounts = [];

    /**
     * Charge prices found in a message, using XenForo's own parser/AST so the
     * result matches what actually renders: `[code]`/`[plain]` contents are
     * skipped and malformed tags (e.g. spaces around `=`) are ignored.
     *
     * @return string[]
     */
    public static function amountsInMessage(string $message): array
    {
        return ContentCharge::amountsInMessage($message);
    }

    /**
     * The highest valid charge price in a message. Because one purchase
     * unlocks the whole post, the post's price is the most expensive block —
     * otherwise a reader could pay the cheapest block and read the rest.
     */
    public static function maxAmount(string $message, int $decimals): ?float
    {
        return ContentCharge::priceForMessage($message, $decimals);
    }

    /**
     * Drop the price and unlock-count caches. Neither is member-scoped, and
     * paid state is never cached, so a purchase needs no clear.
     */
    public static function clearRenderCaches(): void
    {
        self::$maxAmounts = [];
        self::$unlockCounts = [];
    }

    public static function render(array $children, $option, array $tag, array $options, Html $renderer)
    {
        $visitor = \XF::visitor();
        $entity = $options['entity'] ?? null;
        // Only forum posts carry the content identity this feature charges for.
        if (!$entity instanceof \XF\Entity\Post) {
            return (!$visitor->user_id || $visitor->hasPermission('general', 'ocView'))
                ? self::renderChildren($children, $options, $renderer)
                : '';
        }

        try {
            $verdict = self::charge()->access($entity, $visitor);
            if ($verdict === ChargeAccess::DENIED) {
                return '';
            }

            $allowed = ChargeAccess::allows($verdict);
            $currency = null;
            $amount = null;
            if (!$allowed || ChargeAccess::isAuthor($verdict)) {
                $currency = self::primaryCurrency();
                if ($currency) {
                    $amount = self::priceFor($entity, (string)$option, (int)$currency->decimals);
                }
            }

            if (!$allowed) {
                if (!$currency) {
                    return self::unavailable('oc_charge_unavailable');
                }
                if ($amount === null) {
                    return self::unavailable('oc_charge_invalid_price');
                }
                return self::lockedBox($entity, $visitor, $currency, $amount);
            }

            $html = self::renderChildren($children, $options, $renderer);
            if (ChargeAccess::isAuthor($verdict) && $currency && $amount !== null) {
                $html .= self::authorStats(
                    (string)$entity->getEntityContentType(),
                    (int)$entity->getEntityId(),
                    (int)$currency->currency_id,
                    $amount,
                    $currency
                );
            }
            return $html;
        } catch (\Throwable $e) {
            \XF::logException($e, false, 'OpenCredits charge render failed: ');
            return self::unavailable('oc_charge_unavailable');
        }
    }

    protected static function lockedBox(\XF\Entity\Post $entity, $visitor, $currency, float $amount): string
    {
        $currencyId = (int)$currency->currency_id;
        $balance = $visitor->user_id ? self::transact()->getBalance((int)$visitor->user_id, $currencyId) : 0.0;

        return \XF::app()->templater()->renderTemplate('public:oc_charge_locked', [
            'price' => $currency->format($amount),
            'currency' => $currency,
            'contentType' => (string)$entity->getEntityContentType(),
            'contentId' => (int)$entity->getEntityId(),
            'currencyId' => $currencyId,
            'isGuest' => !$visitor->user_id,
            'canAfford' => $balance >= $amount,
            'balance' => $balance,
            'balanceFormatted' => $currency->format($balance),
        ]);
    }

    /**
     * The stored post's highest valid price, cached per message at the
     * currency's precision. Previews and quotes render a message different
     * from the stored post, so they fall back to the block's own option.
     */
    protected static function priceFor(\XF\Entity\Post $entity, string $option, int $decimals): ?float
    {
        $key = $entity->getEntityContentType() . ':' . $entity->getEntityId() . ':' . $decimals
            . ':' . md5((string)$entity->message);
        if (!array_key_exists($key, self::$maxAmounts)) {
            self::$maxAmounts[$key] = ContentCharge::priceForMessage(
                (string)$entity->message,
                $decimals
            );
        }
        $amount = self::$maxAmounts[$key];
        if ($amount === null) {
            $amount = ContentCharge::priceOf([$option], $decimals);
        }
        return $amount;
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
            self::$unlockCounts[$key] = self::charge()->unlockCount(
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
            'total' => $currency->format($count * $amount),
        ]);
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

    protected static function charge(): ContentCharge
    {
        return self::transact()->contentCharge();
    }
}
