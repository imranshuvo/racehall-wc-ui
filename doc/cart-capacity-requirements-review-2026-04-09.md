# Cart Capacity Requirements Review

Date: 2026-04-09

This note captures the client email requirements, the current state of the Racehall WooCommerce booking flow, and the safest implementation direction.

## Client requirements

### 1. Total maximum kart capacity per location

The total number of karts sold online for a single race must never exceed:

- Aarhus: 34
- Copenhagen: 38
- Stockholm: 34

### 2. Kart type limits per location

Adult karts:

- Aarhus: 34
- Copenhagen: 38
- Stockholm: 34

Kids karts:

- Aarhus: 8
- Copenhagen: 14
- Stockholm: 14

Twin karts:

- Aarhus: 2
- Copenhagen: 2
- Stockholm: 2

### 3. Special business rules

- Total combined participants per race must never exceed the location total capacity.
- Family races have a stricter total limit of 25 participants, regardless of location.
- Twin karts may only be selectable on family races and closed races.
- Twin karts must not be selectable on other race types.
- The booking flow must prevent overbooking both per type and in total.
- The rules must be enforced dynamically, not only after checkout submission.

## Current implementation summary

The current plugin already has the right general architecture for participant-aware booking:

- The product page collects adults, children, and twin counts separately.
- Timeslot requests already send both the total quantity and BMI `dynamicLines` based on the participant split.
- Add-to-cart validation already checks participant totals and BMI-derived quantity rules.
- Checkout validation re-runs quantity validation before the BMI hold step.
- Direct-booking links use the same participant model.
- The cart is effectively single-booking-location for the main booking product, which simplifies location-based validation.

Key enforcement touchpoints today:

- `templates/hooks.php`
  - `wk_rh_ajax_get_timeslots()`
  - `wk_rh_extract_quantity_rules()`
  - `wk_rh_validate_main_booking_quantity_rules()`
- `assets/js/single-product.js`
  - `extractRulesFromSources()`
  - `enforceCountsByRules()`
  - `fetchAndRenderTimeslots()`
- `includes/direct-booking-link.php`
  - `wk_rh_get_direct_booking_participants()`
  - `wk_rh_handle_direct_booking_request()`
- `racehall-wc-ui.php`
  - `wk_rh_validate_checkout_booking_quantity()`
  - `wk_rh_sync_checkout_booking_before_order()`
  - `wk_rh_ensure_main_cart_booking_hold()`

## Confirmed gaps in the current implementation

### 1. No central business-rule layer exists yet

Today the code validates only:

- upstream page/product minimum and maximum amounts
- upstream `dynamicGroups` when present
- the sum of adults + children + twin matching the booking quantity

What is missing is a single source of truth for Racehall-specific business rules such as:

- per-location total capacity
- per-location per-kart-type caps
- family-specific total cap
- twin availability by race type

If these rules are added ad hoc in multiple places, the system will drift and break.

### 2. Race type is not modeled explicitly

The client rules require different behavior for at least:

- family races
- closed races
- all other races

The current code does not have a durable first-class race-type field. In practice it relies on combinations of:

- BMI product ID
- product name
- page metadata
- `dynamicGroups`

That is not stable enough for business-critical gating on its own.

### 3. UI behavior does not currently consume proposal `capacity` or `freeSpots`

Local captures show that BMI timeslot proposals include per-slot capacity information such as:

- family product examples with `capacity: 25`
- normal race examples with `capacity: 34`

However, the current UI does not use those values directly to constrain counters or explain why a slot is unavailable. It mostly relies on whether a proposal exists and whether BMI later accepts `booking/book`.

This means the customer experience can still reach a failure later than necessary.

### 4. Family/grouped products have a known compatibility risk in the new hold flow

Saved investigation notes under `.local/api-captures/30372696/analysis.md` and related files show that at least these grouped family combo products previously failed at `booking/book` even when proposal lookup succeeded:

- `30372696` (`Familie Race. Mødetid 30 min før Familie Race`)
- `80345763` (`Family Race & Burger`)

That is a separate issue from the new cart-capacity rules, but it matters because implementing the family limits alone does not guarantee the new flow will complete successfully for those products.

### 5. Group identity exists, but numeric group bounds are not always available upstream

For grouped family products, the live catalog data shows `Adults`, `Children`, and `Twin`, but not always numeric per-group min/max bounds.

That means:

- group mapping is feasible
- strict numeric group caps still need a local business-rule layer

## Can we implement the client limits properly?

Yes, the codebase can support this properly.

But it should only be done with a shared validation model and an explicit race-type strategy. The current structure is strong enough to support the feature because it already has:

- participant-aware UI state
- server-side add-to-cart validation
- checkout revalidation before hold creation
- a direct-booking entry path using the same participant model

What should not be done:

- hardcode checks only in JavaScript
- infer race type only from product names
- scatter separate rule logic into timeslots, add-to-cart, checkout, and direct-booking independently

## Safest implementation shape

### 1. Introduce a shared booking rules provider

Create one server-side rules layer that returns normalized constraints based on:

- canonical location
- race type
- upstream proposal/page context

Suggested rule model:

```php
[
    'total' => [ 'min' => 1, 'max' => 34, 'step' => 1 ],
    'adults' => [ 'min' => 0, 'max' => 34, 'step' => 1 ],
    'kids' => [ 'min' => 0, 'max' => 8, 'step' => 1 ],
    'twin' => [ 'min' => 0, 'max' => 2, 'step' => 1 ],
    'twinAllowed' => true,
    'raceType' => 'family',
    'location' => 'aarhus',
]
```

This local rule model should then be merged with upstream limits, not replace them blindly.

Effective max should be the minimum of:

- Racehall business rule cap
- upstream page/proposal total max when present
- proposal/block capacity or free spots when that is the stronger limit

### 2. Add an explicit race-type source

The safest option is explicit metadata on the WooCommerce product, for example a product field or mapping that resolves to:

- `family`
- `closed`
- `open`
- `other`

If product metadata is not available yet, use a temporary config mapping by BMI product ID. That is still safer than string-matching product names.

### 3. Reuse one validator across every booking entry point

The same rule checker should run in all of these places:

- timeslot AJAX preparation
- add-to-cart validation
- direct-booking-link handling
- checkout validation before `booking/book`

That avoids rule drift.

### 4. Keep JavaScript as UX enforcement, not the authority

The product page should apply the merged rules so users cannot select impossible combinations, but the server must stay authoritative.

Client-side updates should:

- disable twin on non-family and non-closed race types
- clamp counts by location/type caps
- clamp total by the strictest available total cap
- refresh timeslots after count changes

### 5. Add a capability gate for products that are not safe in the new flow

The current rollout should not assume that `bmileisure_id` alone means a product is compatible with the new hold flow.

For grouped family products in particular, a compatibility gate is advisable until `booking/book` behavior is re-verified in the current environment.

## Practical hook points

### Server-side

- `templates/hooks.php::wk_rh_ajax_get_timeslots()`
  - merge business rules into the metadata returned to JS
- `templates/hooks.php::wk_rh_validate_main_booking_quantity_rules()`
  - authoritative add-to-cart validation
- `includes/direct-booking-link.php::wk_rh_get_direct_booking_participants()`
  - reject invalid deep-link combinations early
- `racehall-wc-ui.php::wk_rh_validate_checkout_booking_quantity()`
  - final server-side validation before checkout hold

### Client-side

- `assets/js/single-product.js::extractRulesFromSources()`
  - merge local business rules with upstream rules
- `assets/js/single-product.js::enforceCountsByRules()`
  - keep counters inside valid ranges
- `assets/js/single-product.js::fetchAndRenderTimeslots()`
  - optionally surface slot capacity/free-spots information for UX clarity

## Main challenges

### 1. Stable race classification

Without an explicit race-type field, family-only and closed-only rules will be brittle.

### 2. Upstream and local constraints may disagree

BMI appears to expose some real slot capacity in proposal blocks, but not always full business-rule detail. The implementation must reconcile the two rather than trusting only one source.

### 3. Direct booking links must follow the same rules

The direct-link handler can bypass normal page interaction, so it must reuse the same server-side validator.

### 4. Location normalization must be consistent

The plugin already stores location-specific credentials and gateway behavior. Any new rules layer should normalize location values consistently so that `Copenhagen`, `Kobenhavn`, and other accepted variants do not fragment the logic.

### 5. Family-product compatibility needs re-verification

If grouped family products still fail at `booking/book`, the correct rollout is:

- add the rules layer
- gate unsupported products out of the new flow
- verify upstream compatibility before enabling those products

## Opportunities

- The cart is effectively single-location for the main booking item, which makes location-level validation much easier.
- Adults, children, and twin are already first-class values in the booking flow.
- The existing checkout hold step gives a clean final enforcement point.
- Existing local captures already suggest family slots naturally expose `capacity: 25`, which lines up with the client rule and can improve UX.

## Recommended rollout

1. Introduce a shared rule provider and race-type resolver.
2. Merge local business rules with upstream limits.
3. Reuse the same validator in direct booking, add to cart, checkout, and timeslot metadata responses.
4. Add a capability gate for unsupported grouped products.
5. QA each race type by location, especially family and twin scenarios.

## Preliminary conclusion

This can be implemented properly without breaking the rest of the booking flow, but only if it is done as a shared rules system rather than isolated patches.

The main technical blocker is not the limit logic itself. The real risk is product classification and upstream compatibility for grouped family products. If those are handled explicitly, the existing architecture is good enough to support the client request cleanly.