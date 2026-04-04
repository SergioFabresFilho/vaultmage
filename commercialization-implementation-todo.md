# VaultMage Commercialization Implementation Todo

## Goal

Implement the product and monetization work needed to support VaultMage's commercial positioning:

`Build and improve decks from the cards users actually own, then generate the exact cards they need to buy.`

## Phase 1: Product Foundation

- [ ] Audit current collection, deck, and AI flows against the commercialization strategy
- [ ] Define the exact commercial MVP scope and what is explicitly out of scope
- [ ] Add a single source of truth for feature flags and subscription gating
- [ ] Define plan names, limits, and entitlement rules for `Free`, `Pro`, and `AI Plus`
- [ ] Add analytics events for scan, deck build, deck improvement, buy-list creation, and conversion to purchase flow

## Phase 2: Collection-Aware Deck Logic

- [ ] Improve owned-vs-missing card resolution for generated and imported deck lists
- [ ] Ensure deck proposals always mark owned quantity, missing quantity, and replacement options
- [ ] Add support for prioritizing owned cards during deck generation
- [ ] Add support for prioritizing owned cards during deck improvement flows
- [ ] Add confidence and validation checks for unresolved or ambiguous card matches

## Phase 3: Buy List Generation

- [ ] Define a canonical `buy list` data model in the backend
- [ ] Generate buy lists from AI deck proposals
- [ ] Generate buy lists from manual or imported deck lists
- [ ] Split buy-list output into `must buy`, `optional upgrades`, and `luxury upgrades`
- [ ] Add total estimated cost calculation for buy lists
- [ ] Add cheapest-completion calculation for finishing a deck
- [ ] Add budget-aware prioritization so users can ask for best upgrades under `$X`

## Phase 4: Store And Pricing Preferences

- [ ] Add store preference support for `TCGplayer`, `Card Kingdom`, and `Cardmarket`
- [ ] Add card-version preference support for cheapest printing, matching printing, foil, and non-foil
- [ ] Normalize pricing inputs so deck cost and buy-list cost use the same rules
- [ ] Add fallback behavior when a preferred store has missing prices
- [ ] Surface estimated cost ranges when pricing data is incomplete

## Phase 5: Purchase Flow

- [ ] Design the end-to-end `Buy Missing Cards` user flow
- [ ] Add buy-list review UI with owned, missing, and optional sections
- [ ] Add export options for CSV and copyable text list
- [ ] Add deep-link or external purchase flow integration where supported
- [ ] Preserve a saved purchase plan so users can come back later
- [ ] Add affiliate tracking hooks for outbound purchase links

## Phase 6: AI Productization

- [ ] Add prompt and tool rules specifically for `build from my collection`
- [ ] Add prompt and tool rules specifically for `improve this deck under budget`
- [ ] Add prompt and tool rules specifically for `finish this deck as cheaply as possible`
- [ ] Ensure AI outputs consistently include cuts, adds, owned cards, missing cards, and buy recommendations
- [ ] Add AI usage metering for subscription enforcement
- [ ] Add graceful fallback messaging when AI generation fails or reaches usage limits

## Phase 7: Monetization And Paywall

- [ ] Implement subscription plans in the app and backend
- [ ] Gate scans, AI generations, exports, and premium analytics by entitlement
- [ ] Add paywall screens for `Pro` and `AI Plus`
- [ ] Add upgrade prompts at high-intent moments:
- [ ] scan quota reached
- [ ] AI generation limit reached
- [ ] buy-list generation requested
- [ ] export requested
- [ ] Add a free-tier allowance for limited AI generations
- [ ] Add a free-tier allowance for limited scanning if needed
- [ ] Add plan management and restore-purchase flows

## Phase 8: UX And Messaging

- [ ] Update in-app copy to emphasize collection-aware deck building and purchase planning
- [ ] Add onboarding that explains scan -> build/improve -> buy flow
- [ ] Add results screens that clearly show owned vs missing cards
- [ ] Add budget controls to deck build and deck improvement flows
- [ ] Add explanation text for why a card is recommended or prioritized for purchase

## Phase 9: Data, Quality, And Trust

- [ ] Add tests for owned-vs-missing card resolution
- [ ] Add tests for buy-list generation
- [ ] Add tests for budget-constrained upgrade planning
- [ ] Add tests for subscription entitlements and plan gating
- [ ] Add telemetry for AI success rate, buy-list generation rate, and purchase-click conversion
- [ ] Add manual QA scenarios for the full commercial flow

## Phase 10: Go-To-Market Readiness

- [ ] Define the launch offer and introductory pricing
- [ ] Write landing-page copy around the commercial positioning
- [ ] Define affiliate and store partnership targets
- [ ] Create a basic pricing page and feature comparison table
- [ ] Prepare launch metrics dashboard:
- [ ] activation
- [ ] scan-to-deck conversion
- [ ] deck-to-buy-list conversion
- [ ] buy-link click-through rate
- [ ] free-to-paid conversion

## Suggested MVP Slice

If we want the smallest commercially meaningful version first, build this subset before broader expansion:

- [ ] Owned vs missing card resolution is reliable
- [ ] AI can improve a deck using owned cards first
- [ ] App can generate a ranked buy list for missing cards
- [ ] User can set a budget cap
- [ ] User can export or open the buy list externally
- [ ] Subscription gating exists for AI and premium flows

## Nice-To-Have After MVP

- [ ] Price-drop alerts tied to saved buy lists
- [ ] Best-upgrade-per-dollar recommender
- [ ] Multi-store optimization
- [ ] Shared deck plans and shared purchase lists
- [ ] Rebuy prevention and duplicate-purchase warnings
- [ ] Commander-specific upgrade templates and presets
