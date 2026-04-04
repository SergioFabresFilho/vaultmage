# Owned vs Missing Foundation Action Plan

## Objective

Make owned-vs-missing card status a first-class product capability in VaultMage so deck views, AI improvements, and future buy-list generation all rely on the same backend truth.

This is the first commercialization implementation step because VaultMage's core promise is:

`Use the cards I own first. Then tell me exactly what to buy.`

## Scope

In scope for this action plan:

- add canonical owned/missing fields to deck card payloads
- expose those fields in the deck detail UI
- align deck-level pricing with owned/missing status
- add tests for the new backend behavior

Out of scope for this slice:

- buy-list persistence
- CSV export
- store integrations
- subscription gating
- analytics instrumentation
- budget-plan persistence

## Current State

- deck list responses already compute aggregate `missing_price`
- AI deck improvement tools already compute owned and missing quantities internally
- deck detail responses do not expose owned or missing quantities per card
- deck detail UI does not show which cards are already owned versus missing
- buy recommendations exist only as AI proposal output, not as a first-class model

## Target Outcome

After this slice:

- every deck card returned by the backend includes `owned_quantity` and `missing_quantity`
- the deck detail screen clearly shows which cards are owned and which are still needed
- the deck detail screen can act as the canonical review surface before later buy-list work
- AI and non-AI deck flows share the same ownership semantics

## Implementation Plan

### 1. Define the canonical deck ownership contract

- update the deck show response so each card includes:
  - `quantity_required`
  - `owned_quantity`
  - `missing_quantity`
- keep existing pivot quantity data for compatibility unless it creates confusion
- document the intended meaning:
  - `quantity_required` = copies needed by the deck
  - `owned_quantity` = copies in the user's collection
  - `missing_quantity` = `max(0, quantity_required - owned_quantity)`

### 2. Update backend deck retrieval

- modify [`backend/app/Http/Controllers/Api/DeckController.php`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/backend/app/Http/Controllers/Api/DeckController.php) so `show()` joins or maps collection quantities for the authenticated user
- ensure the response includes ownership fields for all deck cards
- preserve existing authorization and deck-loading behavior
- avoid duplicating logic later by keeping the ownership computation isolated and reusable

### 3. Align deck-level pricing semantics

- verify that deck totals remain correct
- keep total deck price as full deck completion cost
- keep missing price as only the cost of cards not owned
- confirm the per-card payload is sufficient for a future buy-list calculation without re-deriving ownership elsewhere

### 4. Update deck detail UI

- modify [`mobile/app/deck/[id].tsx`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/mobile/app/deck/[id].tsx) to render owned/missing status per card
- show clear, compact status indicators such as:
  - `Own 1 / Need 1`
  - `Missing 2`
  - `Complete`
- surface missing-state information in both:
  - card rows
  - card detail modal
- preserve the current deck browsing flow and avoid turning this screen into a full buy-list UI yet

### 5. Add tests

- extend backend feature coverage for deck responses
- add tests that verify:
  - owned quantity is returned when a user has the card
  - missing quantity is zero when owned copies cover deck demand
  - missing quantity is positive when deck demand exceeds collection quantity
  - missing quantity is correct when the user owns none of the card
- add regression coverage for aggregate price semantics if needed

### 6. Validate UX behavior

- manually verify a deck with all cards owned
- manually verify a deck with partially owned playsets
- manually verify a deck with fully missing cards
- confirm the screen remains readable on mobile with mixed ownership states

## Suggested Task Order

1. Update backend deck payload shape.
2. Add backend tests for ownership quantities.
3. Update deck detail types and rendering.
4. Add owned/missing status to the card detail modal.
5. Run targeted tests and manual verification.

## Acceptance Criteria

- opening a deck shows ownership status for every card
- the backend deck response includes per-card ownership fields
- missing quantities are accurate for zero-owned, partial-owned, and fully-owned cases
- total deck price and missing price semantics remain intact
- the implementation creates a clean foundation for future buy-list generation

## Risks

- duplicated ownership logic between AI services and deck controllers
- payload shape drift between mobile assumptions and backend responses
- confusing UI labels if `pivot.quantity` and `quantity_required` are both displayed without care

## Follow-On Work After This Slice

- introduce a canonical buy-list model
- generate buy lists from deck state and AI proposals
- split buy lists into must-buy, optional, and luxury upgrades
- add budget-aware prioritization
- add export and external purchase flows
