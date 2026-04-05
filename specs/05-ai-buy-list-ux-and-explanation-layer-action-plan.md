# AI Buy List UX And Explanation Layer Action Plan

## Objective

Make VaultMage's buy-list experience explain itself clearly.

Users should not only see what to buy, but also:

- why each card is recommended
- why it falls into a given priority tier
- how the budget changed the recommendation
- what is necessary to finish a deck versus what is an upgrade

This work should strengthen VaultMage's core promise:

`Use the cards I own first. Then tell me exactly what to buy.`

## Why This Exists

Current repo context shows a strong foundation already exists:

- owned vs missing card resolution is implemented
- deck buy lists are first-class in backend and mobile
- AI improvement flows already carry per-card `reason` text
- budget-aware grouping already exists in backend logic

But there is still a clear product gap:

- explanation text for why a card is recommended or prioritized is still open in [`02-commercialization-implementation-todo.md`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/specs/02-commercialization-implementation-todo.md)
- current deck buy-list UI in [`mobile/app/deck/[id].tsx`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/mobile/app/deck/[id].tsx) shows pricing and quantities, but not recommendation rationale
- current canonical deck buy-list payload in [`backend/app/Http/Controllers/Api/DeckController.php`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/backend/app/Http/Controllers/Api/DeckController.php) only exposes coarse `must-buy` vs `optional`
- AI-originated buy-list items in [`backend/app/Services/AiChatService.php`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/backend/app/Services/AiChatService.php) can already carry `reason`, but that explanation model is not elevated into a consistent product contract

## Product Outcome

After this slice:

- every buy-list item has a clear explanation contract
- deck-generated and AI-generated buy lists use the same explanation semantics
- users can distinguish:
  - deck completion purchases
  - strong upgrades
  - lower-priority or luxury upgrades
- budgeted recommendations clearly explain why some cards were deferred
- the deck screen and assistant screen present concise explanations without turning the UI into a wall of text

## Scope

In scope:

- define canonical buy-list priority tiers
- define canonical buy-list explanation fields
- extend backend payloads for deck and AI buy lists
- update mobile buy-list rendering in deck and assistant surfaces
- add tests for explanation and grouping behavior
- add manual QA scenarios for readability and trust

Out of scope for this slice:

- deep-link purchase integrations
- saved buy-list persistence
- affiliate tracking
- subscription gating
- store preference optimization
- multi-store cart building

## Current State

### Strategy And Planning

- [`01-commercialization-strategy.md`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/specs/01-commercialization-strategy.md) frames buy lists as a core output and calls for upgrade tiers and cheapest-completion behavior
- [`02-commercialization-implementation-todo.md`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/specs/02-commercialization-implementation-todo.md) still lists explanation text and richer buy-list tiering as unfinished work
- [`03-commercialization-owned-missing-action-plan.md`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/specs/03-commercialization-owned-missing-action-plan.md) established the canonical ownership foundation this work should build on

### Backend

- deck buy lists currently derive priority mainly from deck structure:
  - mainboard and commander => `must-buy`
  - sideboard => `optional`
- budget logic already produces `must_buy`, `optional`, and `deferred` groups
- AI improvement entries can include `reason`, `priority`, `role`, and `category`
- canonical buy-list shaping does not yet normalize a richer explanation schema across deck and AI flows

### Mobile

- deck buy-list modal shows sections, quantities, and pricing
- assistant results show buy-list sections, but explanation display is minimal and inherited from generic proposal rows
- there is no dedicated explanation treatment such as:
  - "Why buy this"
  - "Why now"
  - "Deferred because budget is exhausted"

## Key Decisions To Make First

### 1. Canonical Priority Model

We need one vocabulary across product, backend, and UI.

Recommended model:

- `must-buy`
  Required to complete the selected deck as currently defined.
- `upgrade`
  Strong recommendation that improves the deck but is not required for legal completion.
- `luxury`
  Nice-to-have or lower-efficiency upgrade that should not compete with core completion.
- `deferred`
  Derived UI state for items excluded by budget or recommendation rules, not a source priority.

Reasoning:

- current `optional` is too vague
- planning docs already point toward richer upgrade tiers
- `deferred` should remain a computed recommendation outcome, not the base semantic type

### 2. Canonical Explanation Contract

Recommended fields for every buy-list item:

- `priority`
- `explanation_summary`
- `explanation_detail`
- `reason_type`
- `budget_status`
- `comparison_note`

Recommended meanings:

- `explanation_summary`
  One-line user-facing explanation for list rows
- `explanation_detail`
  Slightly richer text for expanded views or detail modals
- `reason_type`
  Structured label such as `deck_completion`, `mana_base`, `rate_upgrade`, `synergy`, `budget_value`, `luxury`
- `budget_status`
  `included`, `deferred_for_budget`, `unpriced`, or `not_applicable`
- `comparison_note`
  Optional text like "better than current 4-drop removal slot" or "finishes missing playset"

### 3. Explanation Source Rules

Not every explanation should come from the model.

Recommended rule:

- deterministic deck-completion buy lists use templated backend explanations
- AI-generated upgrade buy lists may supply AI-authored rationale, but backend should still normalize it into the canonical fields
- budget deferment explanations should always be deterministic

This avoids paying model cost for facts the backend already knows.

## Implementation Plan

### 1. Define the canonical buy-list explanation contract

- create a single contract for deck and AI buy-list items
- decide final priority labels:
  - recommended: `must-buy`, `upgrade`, `luxury`
- decide whether legacy `optional` should map to `upgrade` for backward compatibility
- document how `deferred` is derived from priority plus budget outcome
- document when explanation text is deterministic versus AI-authored

Suggested output:

- new markdown contract or inline code comments near the canonical shaping logic

### 2. Refactor backend buy-list shaping into a reusable formatter

- extract buy-list normalization from [`backend/app/Http/Controllers/Api/DeckController.php`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/backend/app/Http/Controllers/Api/DeckController.php)
- align it with the canonical builder already used in [`backend/app/Services/AiChatService.php`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/backend/app/Services/AiChatService.php)
- avoid two drifting contracts for deck and AI buy-list payloads
- ensure both paths emit the same fields and grouping semantics

Recommended result:

- a dedicated formatter/service for canonical buy-list items and grouped responses

### 3. Add deterministic explanation generation for deck buy lists

- for missing mainboard cards, generate direct completion explanations such as:
  - "Required to complete the current deck list"
  - "You own 2 of 4 copies, so 2 still need to be purchased"
- for commander cards, explain that the deck is incomplete without them
- for sideboard or non-core items, explain that they improve matchups but are not required for core completion
- for unpriced items, explain that recommendation confidence is reduced because price data is missing

The goal is trustworthy explanations without model dependence.

### 4. Normalize AI buy-list explanations into the same contract

- keep using AI-authored `reason` where it adds value
- map `reason` into `explanation_summary` and optionally `explanation_detail`
- require structured `reason_type` when tools produce change proposals
- preserve a safe fallback when the model returns weak or missing rationale

Examples:

- "Improves early ramp density"
- "Cheap upgrade with better removal rate"
- "Strong synergy with your commander"

### 5. Upgrade grouping and budget semantics

- replace or alias `optional` with a more specific tier such as `upgrade`
- support `luxury` as a separate low-priority tier when the source flow can distinguish it
- keep `deferred` as the budget-constrained remainder
- add explanation text for why an item moved into `deferred`
- support cheapest-completion framing for deck-finishing flows

Recommended grouping shape:

- `must_buy`
- `upgrade`
- `luxury`
- `deferred`

If we want lower-risk rollout, ship in two stages:

- stage 1: `must_buy`, `upgrade`, `deferred`
- stage 2: add `luxury`

### 6. Improve the deck buy-list UX

- update [`mobile/app/deck/[id].tsx`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/mobile/app/deck/[id].tsx) to render explanation summaries on each buy-list row
- add a compact badge or helper text for:
  - required to finish deck
  - recommended upgrade
  - deferred by budget
  - missing price data
- add a row expansion or detail sheet for longer explanation text if needed
- improve section copy so users understand the difference between required and upgrade purchases
- clarify budget behavior in the summary card:
  - total cost to finish
  - currently recommended under budget
  - what remains deferred

### 7. Improve the assistant buy-list UX

- update [`mobile/app/(tabs)/assistant.tsx`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/mobile/app/(tabs)/assistant.tsx) so buy-list items show explanation context instead of only generic card rows
- visually separate:
  - cards to add now
  - cards worth buying later
  - cards deferred by budget
- ensure AI reasoning is concise and skimmable
- avoid duplicating the same sentence in both proposal and buy-list sections

### 8. Add trust and edge-case messaging

- handle missing price cases explicitly
- handle ambiguous card resolution with a confidence note
- explain when a card is recommended only because the current deck list is incomplete
- explain when a recommendation is not included because budget ran out
- ensure copy does not imply certainty when price or card matching data is weak

### 9. Test the contract and UX behavior

Backend tests:

- deck buy-list response includes explanation fields
- AI buy-list response includes explanation fields or safe fallbacks
- priority tiers map correctly
- deferred items get deterministic deferment messaging
- legacy `optional` inputs normalize predictably if backward compatibility is kept

Mobile tests or QA scenarios:

- user can distinguish required purchases from upgrades at a glance
- budgeted view clearly explains why some items moved to deferred
- explanation text remains readable on small screens
- unpriced items do not produce misleading totals
- assistant and deck screens feel consistent

## Suggested Delivery Order

1. Decide the canonical tier vocabulary and explanation contract.
2. Refactor backend canonical buy-list shaping into one reusable path.
3. Add deterministic explanation generation for deck buy lists.
4. Normalize AI buy-list explanations into the same shape.
5. Update deck buy-list UI to display the new explanation layer.
6. Update assistant buy-list UI to match.
7. Add backend tests and run manual mobile QA.

## Acceptance Criteria

- buy-list items include a consistent explanation contract in both deck and AI flows
- users can clearly distinguish required completion purchases from optional upgrades
- budget-constrained recommendations explicitly explain deferred items
- deck and assistant buy-list UIs use the same conceptual model
- explanation text is concise enough to scan and informative enough to build trust
- the implementation does not depend on AI-generated rationale for deterministic deck-completion cases

## Risks

- tier naming drift between docs, backend, and mobile
- shipping too much explanation text and making the UI noisy
- letting AI-authored rationale become inconsistent or low-quality
- breaking existing mobile assumptions if `optional` is renamed without compatibility handling
- duplicating buy-list normalization in multiple backend paths

## Recommended MVP Slice

If we want the fastest high-value version first:

- keep tiers to `must-buy`, `upgrade`, and `deferred`
- add `explanation_summary`, `reason_type`, and `budget_status`
- generate explanations deterministically for deck buy lists
- render one-line explanations in deck and assistant buy-list sections
- defer richer expanded explanations and `luxury` tier until the core contract is stable

## Follow-On Work

- cheapest-completion mode for `Finish This Deck`
- store-specific explanation such as cheapest source or preferred vendor mismatch
- saved buy-list plans with explanation history
- affiliate/deep-link purchase flows
- analytics on which explanation styles improve buy-list conversion and trust
