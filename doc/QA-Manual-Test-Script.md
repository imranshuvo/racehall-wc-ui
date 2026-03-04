# Onsite Booking System - Manual QA Test Script (Deterministic)

## Scope
Validate the implemented booking flow end-to-end using test environment only:
- PDP availability and timeslots
- Proposal/book and cart transfer
- Upstream-driven add-ons (sell/remove)
- Checkout pay-now and pay-later
- Cancel/refund sync
- Memo sync
- Admin observability (Upstream Data + Diagnostics)

## Test Environment Guardrails
1. WP Admin -> Onsite Booking -> Settings:
   - Environment = test
   - Test Base URL = test gateway URL
   - Location Credential Map JSON uses sandbox client keys only
2. WooCommerce:
   - COD gateway enabled (required for pay-later path)
3. Product setup:
   - Main product has bmileisure_id and lokation
   - At least one add-on product mapped with bmileisure_id
4. Clear noise before run:
   - Onsite Booking -> Diagnostics -> Clear Logs

## Evidence Sources
- Onsite Booking -> Upstream Data
  - Upstream Products table
  - Booking Sync State table
- Onsite Booking -> Diagnostics
  - warning/error/sync events with context
- Woo Order details
  - order notes
  - item meta and order meta (upstream IDs, sync flags)

## Test Data Capture (fill during run)
- Location tested:
- Woo Product (main):
- Woo Product (add-on):
- Woo Order ID(s):
- Upstream orderId(s):
- Upstream orderItemId(s):

---

## Test Case 1 - Upstream Products Visibility
### Action
1. Open Onsite Booking -> Upstream Data
2. Select target location
3. Click Refresh Products

### Expected API behavior
- Auth token request succeeds
- GET /public-booking/{clientKey}/products returns array

### Expected wp-admin evidence
- Upstream Products table populated
- Last fetched timestamp updated
- No error notice on page
- Diagnostics contains no new error entries for products fetch

Pass/Fail: ____
Notes: ____

---

## Test Case 2 - PDP Availability + Timeslots
### Action
1. Open main product PDP
2. Verify calendar loads available days
3. Select date
4. Verify timeslots render

### Expected API behavior
- POST auth/{clientKey}/publicbooking succeeds
- GET /public-booking/{clientKey}/availability?productId&dateFrom&dateTill succeeds
- GET /public-booking/{clientKey}/page?date=... succeeds
- POST /public-booking/{clientKey}/availability?date=... with productId/pageId/quantity succeeds

### Expected wp-admin evidence
- Diagnostics: no errors for availability/page/timeslot calls
- Booking quantity in requests reflects adults+children selection

Pass/Fail: ____
Notes: ____

---

## Test Case 3 - Proposal Save + Book Before Add-to-Cart
### Action
1. On PDP, choose adults/children (set non-default, for example 2 adults + 1 child)
2. Pick date and timeslot
3. Add to cart

### Expected API behavior
- POST /public-booking/{clientKey}/booking/book succeeds from proposal flow
- Response includes orderId and orderItemId

### Expected wp-admin evidence
- Diagnostics has no booking/book missing-identifier errors
- Cart item has booking date/time/location and participant info
- Upstream Data -> Booking Sync State shows upstream order ID and item ID once order is created later

Pass/Fail: ____
Notes: ____

---

## Test Case 4 - Upstream Add-on Sell
### Action
1. On cart page, use Add-ons section (upstream supplements)
2. Click Tilføj add-on for a mapped item

### Expected API behavior
- POST /public-booking/{clientKey}/booking/sell succeeds
- Request includes orderId and parentOrderItemId
- Response includes orderItemId for the add-on line

### Expected wp-admin evidence
- No diagnostics error: booking/sell succeeded without orderItemId
- Cart contains add-on item
- Later order item meta includes bmi_order_item_id for add-on

Pass/Fail: ____
Notes: ____

---

## Test Case 5 - Remove Add-on (removeItem)
### Action
1. Remove add-on item from cart

### Expected API behavior
- POST /public-booking/{clientKey}/booking/removeItem succeeds
- Request includes upstream orderId and upstream orderItemId

### Expected wp-admin evidence
- No diagnostics error for booking/removeItem
- Add-on removed from cart

Pass/Fail: ____
Notes: ____

---

## Test Case 6 - Checkout Pay-Later (COD)
### Action
1. Proceed to checkout
2. Click Betal ved ankomst

### Expected API behavior
- Woo order created using COD
- No payment/confirm call should be made

### Expected wp-admin evidence
- Order created successfully
- Booking Sync State: payment sync flag remains not set
- No payment/confirm error in diagnostics for this order

Pass/Fail: ____
Notes: ____

---

## Test Case 7 - Checkout Pay-Now (payment confirm)
### Action
1. Place a new order with online payment method
2. Complete payment successfully

### Expected API behavior
- POST /public-booking/{clientKey}/payment/confirm succeeds
- Payload includes id, paymentTime, amount, orderId, extraData

### Expected wp-admin evidence
- Woo order note: payment/confirm synced
- Booking Sync State: Payment column shows synced
- No payment/confirm error in diagnostics

Pass/Fail: ____
Notes: ____

---

## Test Case 8 - Memo Sync
### Action
1. Place order with customer note

### Expected API behavior
- POST /public-booking/{clientKey}/booking/memo succeeds

### Expected wp-admin evidence
- Booking Sync State: Memo column shows synced
- No booking/memo error in diagnostics

Pass/Fail: ____
Notes: ____

---

## Test Case 9 - Cancel/Refund Sync
### Action
1. Use an order with upstream orderId
2. Set status to Cancelled (or perform refund)

### Expected API behavior
- DELETE /public-booking/{clientKey}/order/{orderId}/cancel attempted
- Fallback DELETE /public-booking/{clientKey}/bill/{orderId}/cancel attempted if needed
- One endpoint should return success

### Expected wp-admin evidence
- Woo order note: cancellation synced
- Booking Sync State: Cancel column shows synced
- If both fail: diagnostics error contains order ID context

Pass/Fail: ____
Notes: ____

---

## Negative / Edge Checks
1. Missing location mapping
   - Expected: booking/token calls fail safely, diagnostics logs location profile error, no silent wrong client key use
2. Add-on product without bmileisure_id mapping
   - Expected: Woo notice blocks add-on add, no unsynced line added
3. Missing upstream identifiers in booking responses
   - Expected: diagnostics logs explicit missing orderItemId errors; flow does not fake IDs

Pass/Fail: ____
Notes: ____

---

## Final Sign-off Criteria
Release candidate is ready for UAT when all are true:
- All Test Cases 1-9 are PASS
- No unresolved diagnostics errors for tested orders
- Upstream IDs present and consistent in Woo order/item meta
- Pay-later does not trigger payment/confirm
- Cancel/refund sync verified on at least one order

QA Sign-off: ____
Date: ____
