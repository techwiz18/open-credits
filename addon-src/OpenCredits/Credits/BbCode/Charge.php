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

    /**
     * The highest valid charge price in a message. Because one purchase
     * unlocks the whole post, the post's price is the most expensive block —
     * otherwise a reader could pay the cheapest block and read the rest.
     */
    public static function maxAmount(string $message, int $decimals): ?float
    {
        $max = null;
        foreach (self::amountsInMessage($message) AS $raw) {
            $candidate = (float)$raw;
            if ($candidate <= 0 || abs($candidate - round($candidate, $decimals)) > 0.00001) {
                continue;
            }
            $candidate = round($candidate, $decimals);
            if ($max === null || $candidate > $max) {
                $max = $candidate;
            }
        }
        return $max;
    }

    public static function render(array $children, $option, array $tag, array $options, Html $renderer)
    {
        $visitor = \XF::visitor();
        $canViewAddOn = !$visitor->user_id || $visitor->hasPermission('general', 'ocView');

        $entity = $options['entity'] ?? null;
        // Only forum posts carry the content identity this feature charges for.
        if (!$entity instanceof \XF\Entity\Post) {
            return $canViewAddOn ? self::renderChildren($children, $options, $renderer) : '';
        }

        $authorId = self::authorId($options, $entity);
        if ($authorId <= 0) {
            return $canViewAddOn ? self::renderChildren($children, $options, $renderer) : '';
        }

        $isAuthor = $visitor->user_id && (int)$visitor->user_id === $authorId;
        $isExempt = $isAuthor
            || $visitor->is_staff
            || $visitor->is_admin
            || $visitor->is_moderator
            || $visitor->hasPermission('general', 'ocChargeFree');

        // Exempt users see the post even if they lack ocView; everyone else
        // without the permission gets no premium UI (and no body leak).
        if (!$canViewAddOn && !$isExempt) {
            return '';
        }

        $contentType = (string)$entity->getEntityContentType();
        $contentId = (int)$entity->getEntityId();

        try {
            // Existing buyers keep access even if the primary currency is
            // deactivated or the block's price later becomes invalid.
            if ($visitor->user_id
                && self::hasPaid((int)$visitor->user_id, $contentType, $contentId, 0)) {
                return self::renderChildren($children, $options, $renderer);
            }

            $currency = self::primaryCurrency();
            if (!$currency) {
                return $isExempt
                    ? self::renderChildren($children, $options, $renderer)
                    : self::unavailable('oc_charge_unavailable');
            }

            $currencyId = (int)$currency->currency_id;
            $decimals = (int)$currency->decimals;

            $maxKey = $contentType . ':' . $contentId . ':' . $decimals;
            if (!array_key_exists($maxKey, self::$maxAmounts)) {
                self::$maxAmounts[$maxKey] = self::maxAmount((string)$entity->message, $decimals);
            }
            $amount = self::$maxAmounts[$maxKey];

            // Previews/quotes can render a message different from the stored
            // post; fall back to the block's own price when the entity message
            // has no valid charge blocks.
            if ($amount === null) {
                $rawAmount = (float)$option;
                $candidate = round($rawAmount, $decimals);
                if ($rawAmount > 0 && $candidate > 0 && abs($rawAmount - $candidate) < 0.00001) {
                    $amount = $candidate;
                }
            }

            if ($amount === null || $amount <= 0) {
                return $isExempt
                    ? self::renderChildren($children, $options, $renderer)
                    : self::unavailable('oc_charge_invalid_price');
            }

            if ($isExempt) {
                $html = self::renderChildren($children, $options, $renderer);
                if ($isAuthor) {
                    $html .= self::authorStats($contentType, $contentId, $currencyId, $amount, $currency);
                }
                return $html;
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
        self::$maxAmounts = [];
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
            if ($tag === 'plain') {
                continue;
            }
            if ($tag === 'code' && strtolower((string)($node['option'] ?? '')) !== 'rich') {
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
