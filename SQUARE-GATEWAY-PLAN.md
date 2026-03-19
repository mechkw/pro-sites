# Square Gateway – Architecture Plan

## 1. How Gateways Plug Into Pro Sites

### Registration (class_loader / autoload)
- File: `pro-sites.php` lines 239–264
- Gateways are registered in `$class_overrides` array
- Pattern: `'ProSites_Gateway_<Name>' => 'gateways/gateway-<name>.php'`
- **We must add:** `'ProSites_Gateway_Square' => 'gateways/gateway-square.php'`

### Gateway Discovery
- File: `pro-sites-files/lib/ProSites/Helper/Gateway.php`
- `ProSites_Helper_Gateway::get_gateways()` reads `gateways_enabled` setting
- Calls `::get_name()` static method on each class to build the gateway map
- Key returned from `get_name()` becomes the slug stored in DB

### Checkout Dispatch
- File: `pro-sites-files/lib/ProSites/View/Front/Gateway.php`
- Calls these static methods on the active gateway class:
  - `::process_on_render()` — decides whether to process this gateway on page load
  - `::process_checkout_form($render_data, $blog_id, $domain)` — handle form submission
  - `::render_gateway($render_data, $args, $blog_id, $domain)` — output checkout form HTML
  - `::get_existing_user_information($blog_id, $domain)` — load saved card/customer for upgrade flow

### Admin
- Hook: `psts_gateway_settings` → render settings tab
- Hook: `psts_settings_process` → save settings
- Settings stored via `$psts->get_setting('square_*')` → `psts_settings` site option

### Subscription Extension
- Core function: `$psts->extend($blog_id, $period, $gateway, $level, $amount, $expires, $is_recurring)`
- `$period` is the billing term (1, 3, or 12 months)
- `$gateway` is the slug string (e.g. `'square'`)
- `$is_recurring = true` for subscriptions

### Webhook Entry Point
- Pattern: `wp_ajax_nopriv_psts_square_webhook` → `$this->webhook_handler()`

---

## 2. DB Table Design

### Pro Sites Core Table (already exists)
`wp_pro_sites`: blog_id, expire, level, term, gateway, amount, is_recurring

### Square Customers Table (new, mirrors Stripe pattern)
```sql
CREATE TABLE wp_pro_sites_square_customers (
  blog_id       bigint(20)   NOT NULL,
  customer_id   varchar(64)  NOT NULL,
  subscription_id varchar(64) NULL,
  card_id       varchar(64)  NULL,
  PRIMARY KEY (blog_id),
  UNIQUE KEY ix_subscription_id (subscription_id)
) DEFAULT CHARSET=utf8;
```
- `customer_id` = Square Customer ID (`CUST_xxxxx`)
- `subscription_id` = Square Subscription ID (`sub_xxxxx`)
- `card_id` = Square Card on File ID (`ccof_xxxxx`) — default saved card

---

## 3. Square API Mapping to Pro Sites Concepts

### Concept Map

| Pro Sites Concept | Square API Object |
|---|---|
| Customer | Square Customer (`POST /v2/customers`) |
| Saved card | Square Card on File (`POST /v2/cards`) — stored per customer |
| Plan (level + period) | Square Subscription Plan Variation |
| Recurring subscription | Square Subscription (`POST /v2/subscriptions`) |
| Cancel subscription | `POST /v2/subscriptions/{id}/cancel` |
| Webhook events | Square `subscription.*` + `invoice.*` events |
| Checkout tokenization | Square Web Payments SDK (`SqPaymentForm`) |

### Key Differences from Stripe
1. Square uses **Web Payments SDK** (JS tokenizer) not Stripe Elements
2. Cards on File created via `POST /v2/cards` with a payment token — separate step from customer creation
3. Subscriptions require a **Catalog** with Plan + Plan Variation objects
4. Square subscriptions bill against a saved Card on File, not a one-time token
5. Webhook signature verification via `x-square-hmacsha256-signature` header

---

## 4. File/Folder Structure to Create

```
pro-sites-files/
  gateways/
    gateway-square.php                     ← main gateway class
    gateway-square-files/
      square-customer.php                  ← ProSites_Square_Customer
      square-subscription.php              ← ProSites_Square_Subscription
      square-card.php                      ← ProSites_Square_Card
      square-plan.php                      ← ProSites_Square_Plan
      data/
        square-data.php                    ← currencies, countries, static data
      lib/
        vendor/                            ← Square PHP SDK (via composer or bundled)
      views/
        admin/
          settings.php
          subscriber-info.php
          subscription-info.php
        frontend/
          checkout.php
          card-update.php
```

---

## 5. Main Gateway Class Structure

```php
class ProSites_Gateway_Square {

  private static $id = 'square';
  public static $table;
  private static $access_token;
  private static $location_id;
  private static $app_id;            // for Web Payments SDK
  public static $square_customer;    // ProSites_Square_Customer
  public static $square_subscription; // ProSites_Square_Subscription
  public static $square_card;        // ProSites_Square_Card
  public static $square_plan;        // ProSites_Square_Plan

  // State
  public static $blog_id = 0;
  public static $level   = 0;
  public static $period  = 0;
  private static $email;
  public static $existing  = false;
  public static $upgrading = false;

  // Required static methods (gateway contract)
  public static function get_slug()   // returns 'square'
  public static function get_name()   // returns ['square' => 'Square']
  public static function get_supported_currencies()
  public static function get_merchant_countries()
  public static function render_gateway($render_data, $args, $blog_id, $domain)
  public static function process_checkout_form($data, $blog_id, $domain)
  public static function process_on_render()
  public static function get_existing_user_information($blog_id, $domain)
  public static function cancel_subscription($blog_id, $display_message)

  // Instance methods (hooks)
  public function __construct()          // register hooks
  public function settings()             // render admin settings tab
  public function settings_process()     // save admin settings
  public function install()              // create DB table
  public function webhook_handler()      // handle Square webhooks
  public function update_plans()         // sync Square Catalog on level change
  public function subscription_info()    // admin site detail panel
  public function subscriber_info()
  public function next_payment_date()
  public function render_update_form()   // card update form in My Account
  public function modification_form()
  public function process_modification()
  public function delete_blog()
  public function change_gateway()

  // Private helpers
  private static function process_payment($data, $customer, $plan_id, $card_id)
  private static function process_recurring($data, $plan_id, $customer_id, $card_id)
  private static function extend_blog($subscription, $data, $amount)
  private static function maybe_extend($amount, $period_end, $is_payment, $is_recurring)
  private static function set_session_data($data)
  private static function get_email($data)
  private function create_tables()
  private function init_sdk()
}
```

---

## 6. Checkout Flow

### New customer (no saved card)
1. Page renders Square Web Payments SDK form
2. JS tokenizes card → `nonce` posted with form
3. `process_checkout_form()` receives nonce
4. Create Square Customer (or fetch existing by email)
5. Create Card on File from nonce → get `card_id`
6. Create or retrieve Square Subscription Plan Variation for level+period
7. Create Square Subscription: `customer_id` + `card_id` + `plan_variation_id`
8. Store in `wp_pro_sites_square_customers`: `blog_id`, `customer_id`, `subscription_id`, `card_id`
9. Call `$psts->extend()` for immediate activation
10. Pro Sites sends welcome email

### Existing customer (saved card)
1. `render_gateway()` detects saved card via `get_db_customer(blog_id)` + fetches Square card details
2. Show "use saved card" option + card last 4/brand
3. On submit: no nonce needed, use stored `card_id`
4. Update or re-create subscription with same card
5. Continue as above from step 6

### Card update
1. Separate form renders new payment SDK tokenizer
2. On submit: create new Card on File → update `card_id` in DB
3. Update Square subscription to use new card ID
4. Confirm and redirect

---

## 7. Webhook Events to Handle

| Square Event | Pro Sites Action |
|---|---|
| `subscription.created` | log, email success notification |
| `subscription.updated` | check cancel_at_period_end, log |
| `subscription.deactivated` | set canceled flag, call cancel logic |
| `invoice.payment_made` | `$psts->extend()`, record transaction, clear failed flag |
| `invoice.payment_failed` | set failed flag, `$psts->email_notification('failed')` |
| `dispute.state_changed` (WON → LOST) | `$psts->withdraw()` on chargeback |

Webhook signature verification:
```php
$sig = $_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'];
// verify with HMAC-SHA256 of request body + webhook signature key
```

---

## 8. Admin Settings Needed

```
Square Access Token          (text/password)  ← server-side API calls
Square Application ID        (text)           ← Web Payments SDK init
Square Location ID           (text)           ← required for payments
Square Webhook Signature Key (text/password)  ← webhook verification
Sandbox Mode                 (checkbox)       ← toggle sandbox vs production
```

---

## 9. Square SDK Integration

Two options:
- **Option A (preferred):** Bundled Square PHP SDK via Composer, committed to repo
  - `composer require square/square`
  - Check into `gateway-square-files/lib/vendor/`
- **Option B:** Manual HTTP wrapper using `wp_remote_post()` / `wp_remote_get()`
  - More work but zero dependency risk, more portable

Recommendation: **Option B first** for MVP (no composer dependency chain), then Option A if scope grows.

---

## 10. Risks and Gotchas

1. **Square Catalog is required for subscriptions** — unlike Stripe where plans sync to API, Square requires creating Catalog Items + Plan + Plan Variations. These must be created and stored alongside Pro Sites levels.

2. **No anonymous subscriptions** — Square requires a Customer ID before creating a subscription. Customer must be created first.

3. **Card on File is separate from Customer creation** — cannot create customer + card in one call like Stripe's `source` parameter.

4. **Subscription billing is on Square's schedule** — Square controls the next billing date. Pro Sites expiry must be updated via webhook, not just on checkout.

5. **Sandbox vs Production endpoints** — must use different base URLs and credentials; a sandbox checkbox + conditional init is required.

6. **idempotency keys** — Square requires them on create calls. Use `uniqid('psts_', true)` or a hash of `blog_id + level + period + timestamp`.

7. **Currency** — Square only supports a subset of currencies; US locations: USD only. Must restrict currency settings accordingly.

8. **Plan Variation ID sync** — Pro Sites levels can be added/changed; must hook `update_site_option_psts_levels`, `psts_delete_level`, `psts_add_level` to sync Square Catalog.

---

## 11. Phased Implementation Order

### Phase 1 — Scaffold + Admin Settings
- `gateway-square.php` class shell
- Register in `pro-sites.php` class_overrides
- Admin settings form (access token, app ID, location ID, webhook key, sandbox toggle)
- `install()` creates DB table
- `init_sdk()` configures Square PHP client

### Phase 2 — Checkout (New Customer, New Card)
- Web Payments SDK JS integration (checkout form view)
- `process_checkout_form()`: create customer → create card on file → create subscription
- `render_gateway()`: output form with SDK init
- `$psts->extend()` on successful subscription
- DB record insert

### Phase 3 — Saved Card Flow
- `get_existing_user_information()` loads saved card from DB + Square
- `render_gateway()` shows card-on-file option
- `process_checkout_form()` skips tokenization when using saved card
- Card update form (`card-update.php` view + `process_card_update()`)

### Phase 4 — Webhooks
- `webhook_handler()` action registered
- Handle `invoice.payment_made` → extend blog
- Handle `invoice.payment_failed` → flag + email
- Handle `subscription.deactivated` → cancel
- Handle `dispute.state_changed` → withdraw
- Signature verification

### Phase 5 — Plan Sync + Admin UX
- `update_plans()` syncs Square Catalog on level changes
- Admin subscription info panels
- Next payment date
- Modification form (plan upgrade/downgrade)
- Process transfer

### Phase 6 — Hardening
- Error logging
- Idempotency keys on all create calls
- Sandbox/prod split testing
- Edge cases: trial-to-paid, gateway change, blog deletion cleanup
