# Owned vs Missing Foundation Action Plan

## Completion

Status: Completed

Delivered:

- canonical owned/missing deck payload fields
- deck detail owned/missing UI
- deck list completion status UI
- first-class deck buy-list flow
- budget-aware buy-list prioritization
- text share, clipboard copy, and CSV export
- backend test coverage for ownership, buy-list, and budget behavior

## Status

- [x] Canonical owned/missing fields added to deck payloads
- [x] Deck detail UI updated to show owned/missing state
- [x] Deck list UI updated to show completion status
- [x] Deck buy-list flow added for missing cards
- [x] Budget-aware buy-list prioritization added
- [x] Copy/share/CSV export added for the buy list
- [x] Backend tests added for ownership and buy-list behavior
- [ ] Manual UX validation pass completed

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

- deck list responses compute aggregate `missing_price` and deck completion counts
- AI deck improvement tools compute owned and missing quantities internally
- deck detail responses expose owned and missing quantities per card
- deck detail UI shows which cards are already owned versus missing
- deck buy lists exist as a first-class backend response and UI flow
- buy lists support grouped `must buy`, `optional`, and `deferred` sections under a budget cap

## Target Outcome

After this slice:

- every deck card returned by the backend includes `owned_quantity` and `missing_quantity`
- the deck detail screen clearly shows which cards are owned and which are still needed
- the deck detail screen can act as the canonical review surface before later buy-list work
- AI and non-AI deck flows share the same ownership semantics

## Implementation Plan

### 1. Define the canonical deck ownership contract

- [x] update the deck show response so each card includes:
  - `quantity_required`
  - `owned_quantity`
  - `missing_quantity`
- [x] keep existing pivot quantity data for compatibility unless it creates confusion
- [x] document the intended meaning:
  - `quantity_required` = copies needed by the deck
  - `owned_quantity` = copies in the user's collection
  - `missing_quantity` = `max(0, quantity_required - owned_quantity)`

### 2. Update backend deck retrieval

- [x] modify [`backend/app/Http/Controllers/Api/DeckController.php`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/backend/app/Http/Controllers/Api/DeckController.php) so `show()` joins or maps collection quantities for the authenticated user
- [x] ensure the response includes ownership fields for all deck cards
- [x] preserve existing authorization and deck-loading behavior
- [ ] avoid duplicating logic later by keeping the ownership computation isolated and reusable

### 3. Align deck-level pricing semantics

- [x] verify that deck totals remain correct
- [x] keep total deck price as full deck completion cost
- [x] keep missing price as only the cost of cards not owned
- [x] confirm the per-card payload is sufficient for a future buy-list calculation without re-deriving ownership elsewhere

### 4. Update deck detail UI

- [x] modify [`mobile/app/deck/[id].tsx`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/mobile/app/deck/[id].tsx) to render owned/missing status per card
- [x] show clear, compact status indicators such as:
  - `Own 1 / Need 1`
  - `Missing 2`
  - `Complete`
- [x] surface missing-state information in both:
  - card rows
  - card detail modal
- [x] extend the deck browsing flow into a first-class buy-list experience

### 5. Add tests

- [x] extend backend feature coverage for deck responses
- [x] add tests that verify:
  - owned quantity is returned when a user has the card
  - missing quantity is zero when owned copies cover deck demand
  - missing quantity is positive when deck demand exceeds collection quantity
  - missing quantity is correct when the user owns none of the card
- [x] add regression coverage for aggregate price semantics if needed

### 6. Validate UX behavior

- [ ] manually verify a deck with all cards owned
- [ ] manually verify a deck with partially owned playsets
- [ ] manually verify a deck with fully missing cards
- [ ] confirm the screen remains readable on mobile with mixed ownership states

## Suggested Task Order

1. Update backend deck payload shape.
2. Add backend tests for ownership quantities.
3. Update deck detail types and rendering.
4. Add owned/missing status to the card detail modal.
5. Run targeted tests and manual verification.

## Acceptance Criteria

- [x] opening a deck shows ownership status for every card
- [x] the backend deck response includes per-card ownership fields
- [x] missing quantities are accurate for zero-owned, partial-owned, and fully-owned cases
- [x] total deck price and missing price semantics remain intact
- [x] the implementation creates a clean foundation for future buy-list generation

## Risks

- duplicated ownership logic between AI services and deck controllers
- payload shape drift between mobile assumptions and backend responses
- confusing UI labels if `pivot.quantity` and `quantity_required` are both displayed without care

## Follow-On Work

- manual UX validation across owned, partial-owned, and fully missing deck scenarios
- refactor shared ownership and buy-list logic into reusable backend helpers/services
- continue commercialization work from [`02-commercialization-implementation-todo.md`](/Users/sergiosanchesfabresfilho/ssff/ssff/vaultmage/specs/02-commercialization-implementation-todo.md)
