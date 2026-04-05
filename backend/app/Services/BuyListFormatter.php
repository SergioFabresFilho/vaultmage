<?php

namespace App\Services;

class BuyListFormatter
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function build(array $items, ?float $budget): array
    {
        $collection = collect($items)
            ->values()
            ->map(fn (array $item) => $this->normalizeItem($item))
            ->sortBy(fn (array $item) => [
                $item['is_commander'] ? 0 : ($item['is_sideboard'] ? 2 : 1),
                $item['line_total'] === null ? 1 : 0,
                $item['line_total'] ?? PHP_FLOAT_MAX,
                -1 * $this->quantityForCounts($item),
            ])
            ->values();

        $mustBuy = $collection->filter(fn (array $item) => $item['priority'] === 'must-buy')->values();
        $upgrade = $collection->filter(fn (array $item) => $item['priority'] === 'upgrade')->values();

        $recommended = collect();
        $remaining = $budget;

        foreach ($mustBuy as $item) {
            if ($remaining === null || $item['line_total'] === null) {
                $recommended->push($item);
                continue;
            }

            if ($item['line_total'] <= $remaining) {
                $recommended->push($item);
                $remaining = round($remaining - $item['line_total'], 2);
            }
        }

        foreach ($upgrade as $item) {
            if ($remaining === null) {
                break;
            }

            if ($item['line_total'] === null) {
                continue;
            }

            if ($item['line_total'] <= $remaining) {
                $recommended->push($item);
                $remaining = round($remaining - $item['line_total'], 2);
            }
        }

        $recommendedIds = $recommended->pluck('card_id')->all();

        $recommended = $recommended
            ->values()
            ->map(fn (array $item) => $this->applyBudgetStatus($item, true, $budget));

        $deferred = $collection
            ->filter(fn (array $item) => ! in_array($item['card_id'], $recommendedIds, true))
            ->values()
            ->map(fn (array $item) => $this->applyBudgetStatus($item, false, $budget));

        $pricedItems = $collection->filter(fn (array $item) => $item['line_total'] !== null);
        $recommendedPriced = $recommended->filter(fn (array $item) => $item['line_total'] !== null);
        $completionPriced = $mustBuy->filter(fn (array $item) => $item['line_total'] !== null);

        return [
            'items' => $recommended
                ->concat($deferred)
                ->sortBy(fn (array $item) => [
                    $item['is_commander'] ? 0 : ($item['is_sideboard'] ? 2 : 1),
                    $item['line_total'] === null ? 1 : 0,
                    $item['line_total'] ?? PHP_FLOAT_MAX,
                    -1 * $this->quantityForCounts($item),
                ])
                ->values()
                ->all(),
            'missing_cards_count' => $collection->sum(fn (array $item) => $this->quantityForCounts($item)),
            'estimated_total' => round((float) $pricedItems->sum('line_total'), 2),
            'priced_items_count' => $pricedItems->count(),
            'unpriced_items_count' => $collection->count() - $pricedItems->count(),
            'budget' => $budget,
            'budget_remaining' => $remaining,
            'recommended_total' => round((float) $recommendedPriced->sum('line_total'), 2),
            'cheapest_completion' => [
                'items' => $mustBuy->values()->all(),
                'missing_cards_count' => $mustBuy->sum(fn (array $item) => $this->quantityForCounts($item)),
                'estimated_total' => round((float) $completionPriced->sum('line_total'), 2),
                'priced_items_count' => $completionPriced->count(),
                'unpriced_items_count' => $mustBuy->count() - $completionPriced->count(),
            ],
            'groups' => [
                'must_buy' => $recommended->filter(fn (array $item) => $item['priority'] === 'must-buy')->values()->all(),
                'upgrade' => $recommended->filter(fn (array $item) => $item['priority'] === 'upgrade')->values()->all(),
                'optional' => $recommended->filter(fn (array $item) => $item['priority'] === 'upgrade')->values()->all(),
                'deferred' => $deferred->all(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item): array
    {
        $priority = $this->normalizePriority($item['priority'] ?? ($item['role'] ?? null));
        $category = $item['category'] ?? (($item['role'] ?? '') === 'commander' ? 'commander' : null);
        $isCommander = (bool) ($item['is_commander'] ?? $category === 'commander');
        $isSideboard = (bool) ($item['is_sideboard'] ?? $category === 'sideboard');
        $reasonType = $item['reason_type'] ?? $this->deriveReasonType($item, $priority, $category);
        $summary = $item['explanation_summary'] ?? $this->deriveExplanationSummary($item, $priority, $category, $reasonType);

        return [
            ...$item,
            'priority' => $priority,
            'category' => $category,
            'is_commander' => $isCommander,
            'is_sideboard' => $isSideboard,
            'reason_type' => $reasonType,
            'explanation_summary' => $summary,
            'budget_status' => $item['budget_status'] ?? 'not_applicable',
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function applyBudgetStatus(array $item, bool $included, ?float $budget): array
    {
        if ($item['line_total'] === null) {
            $item['budget_status'] = 'unpriced';
            return $item;
        }

        if ($included) {
            $item['budget_status'] = 'included';
            return $item;
        }

        $item['budget_status'] = $budget !== null ? 'deferred_for_budget' : 'not_applicable';

        if ($item['budget_status'] === 'deferred_for_budget') {
            $item['explanation_summary'] = $item['priority'] === 'must-buy'
                ? 'Required to finish this deck, but deferred because it exceeds the current budget.'
                : 'Recommended upgrade, but deferred because it does not fit within the current budget.';
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function quantityForCounts(array $item): int
    {
        return max(0, (int) ($item['missing_quantity'] ?? $item['quantity'] ?? 0));
    }

    private function normalizePriority(mixed $priority): string
    {
        return match ($priority) {
            'optional', 'upgrade', 'sideboard' => 'upgrade',
            default => 'must-buy',
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function deriveReasonType(array $item, string $priority, mixed $category): string
    {
        if (trim((string) ($item['reason'] ?? '')) !== '' && $category === 'upgrade') {
            return 'synergy';
        }

        if ($priority === 'must-buy') {
            return 'deck_completion';
        }

        if ($category === 'sideboard') {
            return 'sideboard_upgrade';
        }

        return 'upgrade';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function deriveExplanationSummary(array $item, string $priority, mixed $category, string $reasonType): string
    {
        $reason = trim((string) ($item['reason'] ?? ''));
        if ($reason !== '') {
            return $reason;
        }

        $owned = (int) ($item['owned_quantity'] ?? 0);
        $needed = $this->quantityForCounts($item);
        $required = (int) ($item['quantity_required'] ?? ($owned + $needed));

        if ($priority === 'must-buy' && $category === 'commander') {
            return 'Required to complete this deck because the commander is still missing.';
        }

        if ($priority === 'must-buy') {
            if ($owned > 0 && $required > 0 && $needed > 0) {
                return "Required to complete this deck. You own {$owned} of {$required} copies, so {$needed} still need to be purchased.";
            }

            return 'Required to complete the current deck list.';
        }

        if ($reasonType === 'sideboard_upgrade') {
            return 'Recommended sideboard upgrade. Useful for specific matchups, but not required for core deck completion.';
        }

        return 'Recommended upgrade that improves the deck, but is not required for completion.';
    }
}
