<?php

namespace App\Services\Contracts;

final class BuyListContract
{
    public const PRIORITY_MUST_BUY = 'must-buy';
    public const PRIORITY_UPGRADE = 'upgrade';

    public const BUDGET_STATUS_INCLUDED = 'included';
    public const BUDGET_STATUS_DEFERRED = 'deferred_for_budget';
    public const BUDGET_STATUS_UNPRICED = 'unpriced';
    public const BUDGET_STATUS_NOT_APPLICABLE = 'not_applicable';

    /**
     * Canonical buy-list item fields for deck and AI flows:
     *
     * - priority: `must-buy` or `upgrade`
     * - explanation_summary: concise user-facing rationale
     * - reason_type: structured explanation tag
     * - budget_status: included/deferred_for_budget/unpriced/not_applicable
     *
     * Compatibility rules:
     *
     * - source `optional` priorities normalize to `upgrade`
     * - `groups.optional` remains as an alias of `groups.upgrade` until clients migrate
     */
    private function __construct()
    {
    }
}
