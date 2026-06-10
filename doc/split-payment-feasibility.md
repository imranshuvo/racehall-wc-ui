# Split Payment — Feasibility & Plan

_Last updated: 2026-06-10. Internal planning doc (excluded from release zips)._

## Background

Meeting with AC on 24 April covered **driver restrictions** and **split payment**.

- **Driver restrictions:** blocked — BMI will not expose the required API endpoints in the upcoming sprint. Parked.
- **Split payment:** the focus, _assuming_ BMI is not again the limiting factor.

BMI (Roberta) has not rejected split payment technically — they describe it as a use case they haven't worked on before, and said it "shouldn't be a big blocker from the technical perspective." Dialogue is constructive; keep building on it.

Why it matters: high value for **company events, friend groups, and larger team bookings** where one person coordinates but participants pay individually.

## The two payment scenarios

### Current flow — one person pays for all (BUILT)
One person books and pays the full amount, regardless of whether it's for themselves, a small group, or a whole team. This is what the on-site checkout does today (online via Frisbii/Nexi, or pay-on-site via `payOnSite`).

### New flow — split payment (PROPOSED)
- One person is the **booker** but pays **only for themselves** (if racing).
- Each remaining driver gets an **individual payment request** (via Frisbii) to their email and/or mobile.
- Each driver has **X hours** to pay; if they don't, **their** reservation expires — **the booker is not responsible** for unpaid drivers.
- Booker enters the other drivers' emails/mobiles; the system creates one Frisbii payment request per participant.
- Later version: driver self-registration as part of payment (MobilePay Express = pay + register in one swipe).

If split payment turns out to need BMI endpoints they won't provide, we're back to "only what we've already built," and the only larger path forward would be a full booking solution (several months).

## Technical findings (BMI public booking API)

### CONFIRMED ✅ — multiple partial payments against one reservation
BMI confirmed that the website can trigger **multiple `POST /payment/confirm` calls against the same `OrderId`, each with a distinct `Id` and a partial `Amount`**. Their example (test gateway `api-test.bmileisure.com`):

```bash
# Payment 1 — Amount 10
curl https://api-test.bmileisure.com/public-booking/.../payment/confirm \
  --request POST \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header 'Authorization: Bearer ...' \
  --header 'Bmi-Subscription-Key: ...' \
  --data '{
    "Id": "ref11",
    "PaymentProviderKind": -11042,
    "PaymentTime": "2026-06-01T10:00:00Z",
    "Amount": 10,
    "OrderId": ...,
    "PaymentMode": 0,
    "ExtraData": { "additionalProperty": "Ref1" }
  }'

# Payment 2 — Amount 20 (same OrderId)
curl https://api-test.bmileisure.com/public-booking/.../payment/confirm \
  --request POST \
  ... \
  --data '{
    "Id": "ref12",
    "PaymentProviderKind": -11042,
    "PaymentTime": "2026-06-01T10:00:00Z",
    "Amount": 20,
    "OrderId": ...,
    "PaymentMode": 0,
    "ExtraData": { "additionalProperty": "Ref2" }
  }'
```

Supporting evidence in the contract: `GET /order/{orderId}/overview` already returns `TotalPaid` and `TotalToDeposit`; `PaymentStatus` includes `Pending (5)` and `DepositsNotProcessed (8)`.

**Important distinction:** this proves *"two payments toward one bill"* — it does **not** prove *"two independent participants, each holding a seat that expires if unpaid."* The order still has its full quantity reserved regardless of who paid. Payment-splitting is confirmed; the **reservation lifecycle split is not.**

### OPEN ❓ — the reservation lifecycle (the hard part)
1. **Order state while partially paid** — after payment 1 (e.g. 10 of 30), does `overview.TotalPaid` show 10, and is the booking confirmed only when `TotalPaid == Total`?
2. **Per-participant pending state** — can individual seats stay "pending/unpaid" while others are paid? Today drivers are a quantity / age-group breakdown (`dynamicLines`) on a single order item, not separate per-driver lines. Is per-seat payment status possible, or must each participant be a separate order item / order?
3. **Auto-expiry of unpaid slots** — can a single unpaid seat expire after X hours and release just that seat back to capacity, while paid seats stay? And how long does BMI hold an *unpaid* reservation before server-side expiry? (We need hours, not the current ~15-min hold.)
4. **Existing API vs new endpoints** — is all of the above possible with the current API, or are new endpoints needed?

### New payload fields to clarify
Their example adds fields our current single-payment flow does not send (`wk_rh_confirm_payment_for_order` sends only `Id`, `PaymentTime`, `Amount`, `OrderId`, `ExtraData`):
- **`PaymentProviderKind`** (`-11042` in the example) — what's the enum, and which value represents **Frisbii/Reepay** (and pay-on-site)?
- **`PaymentMode`** (`0`) — what modes exist, which to use.
- **`ExtraData.additionalProperty`** — confirm free-form + stored; we'd use it to tag **which participant** a payment is for (reconciliation).
- Confirm the existing single full-payment flow stays valid without the two new fields.

## Architecture options

### Model A — one shared reservation + partial payments
Booker creates one BMI order for the whole group; each participant's Frisbii payment → one `payment/confirm` with their `Amount` + unique `Id` + `ExtraData` participant ref; reconcile via `TotalPaid`.
- **Pros:** matches "one booking"; simple pricing/capacity; clean reconciliation; payment mechanism is **confirmed**.
- **Cons:** **per-seat expiry is the problem.** Drivers are a quantity on one order item; there's no documented way to drop/expire one seat. `removeItem` removes a whole item. Depends entirely on OPEN Q2/Q3.

### Model B — one reservation per participant
Each participant is a separate BMI order (quantity 1) into the same session/resource, up to `FreeSpots`.
- **Pros:** each reservation is **independent** → per-participant expiry is trivial (each has its own hold/cancel, which we already built); each Frisbii request maps 1:1 to an order; booker pays only their own order. **Does not even need the partial-payment feature** (each order gets one full `payment/confirm`). Matches "booker not responsible" exactly.
- **Cons:** N orders into one session (needs BMI to allow multiple same-session bookings up to capacity — the `Capacity`/`FreeSpots` model suggests yes); more orders to track; pricing per order.

**Recommendation:** lean **Model B** unless BMI confirms per-seat pending/expiry on a shared order (Q2/Q3). B is the most faithful to the requirements and least dependent on unverified behavior.

**The fork:** Q2/Q3 decides it. If BMI can expire a single seat on a shared order → Model A works end-to-end. If not → Model B.

## Frisbii side (not blocked by BMI)
- Per-participant payment **links/requests by email or mobile**, MobilePay, webhooks back to us — standard Frisbii/Reepay capability.
- On each participant's payment webhook → call `payment/confirm` (their order/share).
- MobilePay Express "pay + self-register in one swipe" = later, Frisbii-native add-on.

## Plugin-side work (either model)
- Booker enters participant emails/phones at checkout.
- Generate N Frisbii payment requests.
- Track per-participant payment state + X-hour timers.
- Reconcile payments → BMI (`payment/confirm`, tagging participant via `ExtraData`).
- Expire unpaid participants (per-seat in B; TBD in A).
- Notifications (email/SMS).
- Booker-facing status view.
- Bounded but real — a proper feature, not a tweak.

## Open questions for AC (product, no BMI dependency)
- On non-payment, do we **drop the individual seat** (→ Model B) or **hold/cancel the whole remainder** (→ Model A)? This single answer picks the architecture.

## Draft reply to Roberta (BMI)
> This is exactly the payment flow we want for splitting — thank you, that confirms multiple partial payments against one `OrderId` works. Two follow-ups to make it production-ready for our use case:
>
> 1. **Order state while partially paid:** after payment 1 (Amount 10 of, say, 30), what state is the reservation in? Does `GET /order/{orderId}/overview` show `TotalPaid: 10`, and is the booking only treated as confirmed once `TotalPaid` reaches `Total`?
> 2. **Unpaid remainder over time — the core of our use case:** if payment 2 never arrives, what happens to the reservation? Can an individual unpaid participant slot expire automatically after X hours and release just that seat back to capacity, while paid participants keep their reservation — or does the whole order stay held / get cancelled? If per-seat expiry isn't possible on a shared order, would you instead recommend one reservation per participant (quantity 1 each into the same session) so each has independent payment and expiry?
> 3. **Field clarifications:** the correct `PaymentProviderKind` value for Frisbii, what `PaymentMode` options exist, and confirmation that `ExtraData` is free-form (we'd use it to tag each participant).

## Next steps
1. Send the follow-up to Roberta (above) — focus on Q2/Q3, the only real blockers.
2. **Spike on the test gateway** (de-risk independently of BMI): run the two example curls → verify `overview.TotalPaid` accumulates (Q1), and test multiple same-session bookings up to `FreeSpots` (Q5/Model B). Needs test creds + a test `OrderId`.
3. Get AC's product decision on non-payment handling (drop seat vs cancel remainder).
4. Once Q2/Q3 answered → pick Model A or B → design the plugin data model.
