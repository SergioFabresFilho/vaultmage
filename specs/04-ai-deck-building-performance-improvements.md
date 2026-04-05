# AI Deck Building Performance Improvements

## What The Debug Logs Show

- The main latency issue is iterative tool thrashing, not one slow request.
- Conversation `24` spent multiple rounds retrying `propose_deck` after validation failures:
  - wrong card count
  - singleton violations
  - additional retries after corrections
- Conversation `26` reached `round 30` and was still failing on:
  - wrong card count
  - too many lands
  - duplicate cards
  - invalid card names
- There are also many exploratory searches before a final proposal:
  - repeated `search_cards`
  - repeated `search_scryfall`
- `search_cards` returns large payloads. In `backend/app/Services/AiChatService.php`, it currently returns up to 30 cards per call, and the logs show result sizes in the 8k range.
- Validation failures send long correction messages back to the model, including the full resolved card summary. That inflates prompt size and slows every retry.
- The service currently allows up to 50 tool rounds, which is too high for this workflow.

## Root Cause

- Deck improvement is being treated like full deck generation.
- That forces the model to regenerate and validate complete 60/100-card lists even when the user only wants upgrade advice.
- The result is repeated propose/fail/retry loops.

## Highest-Impact Fixes

### 1. Add a `propose_changes` tool for deck improvement

Instead of forcing the model to regenerate a full deck, add a dedicated tool for upgrade flows that returns only:

- cards to cut
- cards to add
- optional buy recommendations

This should become the default path for:

- "what can I change?"
- "how do I improve this deck?"
- "what should I buy?"

The model should only use `propose_deck` when the user explicitly wants a full rebuilt list.

### 2. Lower the maximum tool rounds

Current cap: 50 rounds.

Recommended caps:

- build from scratch: 8 to 10 rounds
- improve existing deck: 4 to 6 rounds

If the model cannot finish within that window, fail fast and return a concise explanation instead of looping.

### 3. Shrink tool payloads

- Reduce `search_cards` result count from 30 to around 8 to 10.
- Trim returned fields further where possible.
- Avoid large redundant payloads in repeated tool responses.

### 4. Make validation feedback compact and structured

Instead of returning long natural-language rejection messages plus the full resolved card summary, return concise machine-readable errors such as:

- `missing_cards: 3`
- `duplicate_cards: ["Snapcaster Mage"]`
- `too_many_lands: 17`
- `singleton_violations: ["Command Tower", "Fabled Passage"]`
- `unresolved_names: ["Taylor Swift (Magic Fandom)"]`

This reduces prompt bloat and makes retries more surgical.

### 5. Strengthen the system prompt

Add explicit rules such as:

- for improvement requests, do not rebuild the entire deck unless the user explicitly asks for it
- do not call `propose_deck` repeatedly after failed validation without first correcting the specific issue
- avoid repeated broad `search_cards` / `search_scryfall` searches

### 6. Add latency logging

Current logs show tool rounds and tool names, but not clear elapsed time per OpenAI call or per tool execution.

Add timing logs for:

- OpenAI request duration
- each tool execution duration
- total request duration per conversation message

This will make it easier to separate:

- model latency
- Scryfall latency
- database/query latency
- retry-loop latency

## Recommended Execution Order

1. Add `propose_changes` and route deck improvement requests to it.
2. Reduce payload sizes and shorten validation errors.
3. Lower the tool round cap.
4. Add timing instrumentation.

## Expected Result

These changes should reduce:

- unnecessary full-deck rebuilds
- retry loops
- prompt size inflation
- end-to-end latency for both deck building and deck improvement flows

The largest improvement should come from separating "improve this deck" from "build me a full deck".
