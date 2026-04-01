# STR Direct Booking — WordPress Plugin

A production-grade WordPress plugin for short-term rental hosts to accept direct bookings on their own websites. Handles the full guest lifecycle: availability checking, pricing calculation, payment collection, calendar sync, and automated notifications — without paying platform commission.

---

## Overview

This plugin provides everything a short-term rental operator needs to run direct bookings independently of Airbnb/VRBO:

- **Booking widget** — React-powered frontend form embedded via shortcode
- **Payment processing** — Stripe (primary) and Square, with installment plans
- **Co-host splits** — Automatic revenue sharing via Stripe Connect
- **Calendar sync** — iCal import from Airbnb/VRBO/Booking.com, export for external platforms
- **Pricing engine** — Nightly rates, seasonal overrides, length-of-stay discounts, fees, taxes
- **Guest notifications** — Email + SMS (Twilio) throughout the stay lifecycle
- **Multi-property support** — Manage multiple listings from one admin

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.0+, WordPress 6.0+ |
| Frontend | React, `@wordpress/element`, `@wordpress/api-fetch` |
| Build | Webpack via `@wordpress/scripts` |
| Payments | Stripe SDK, Square Payments API |
| Calendar | `Kigkonsult\Icalcreator` (iCalendar) |
| SMS | Twilio REST API |
| Scheduling | WP-Cron |

---

## File Structure

```
STR-Wordpress-Plugin/
├── str-direct-booking.php              # Plugin entry point, constants, init
├── composer.json                       # PHP deps (Stripe SDK, iCal library)
├── package.json                        # JS deps (React, Stripe, WP components)
├── webpack.config.js

├── includes/
│   ├── class-str-booking.php           # Singleton orchestrator — loads all managers
│   ├── class-booking-manager.php       # Booking CRUD, availability queries
│   ├── class-payment-handler.php       # Stripe PaymentIntents, webhooks, Connect transfers
│   ├── class-payment-plan-manager.php  # Installment scheduling, off-session charging
│   ├── class-pricing-engine.php        # Nightly rates, seasonal pricing, discounts, fees
│   ├── class-calendar-sync.php         # iCal import/export, external feed subscriptions
│   ├── class-cohost-manager.php        # Co-host Stripe Connect CRUD
│   ├── class-notification-manager.php  # Email + SMS dispatch
│   ├── class-square-handler.php        # Square Payments API
│   ├── class-plugin-updater.php        # GitHub-based auto-updates
│   │
│   ├── admin/
│   │   ├── class-admin-dashboard.php           # Admin menu, React dashboard
│   │   ├── class-property-manager.php          # CPT registration, meta boxes
│   │   ├── class-settings.php                  # WordPress Settings API
│   │   ├── class-calendar-sync-settings.php    # iCal feed management UI
│   │   └── class-notification-settings.php     # Notification template editor
│   │
│   ├── frontend/
│   │   ├── class-booking-widget.php    # [str_booking_form] shortcode
│   │   ├── class-calendar-widget.php  # [str_availability_calendar] shortcode
│   │   └── class-public-api.php       # REST API endpoint registration
│   │
│   └── database/
│       ├── class-database-manager.php  # Table creation on activation
│       └── migrations/

├── src/                                # React source
│   ├── booking-widget/                 # Booking form UI + payment flow
│   ├── calendar-widget/                # Availability calendar display
│   └── admin-dashboard/                # Admin metrics panel

├── assets/
│   ├── js/                             # Compiled JS bundles + .asset.php manifests
│   └── css/
│       ├── frontend.css                # Widget styles (responsive, mobile-first)
│       └── admin.css                   # Admin dashboard styles

└── templates/
    ├── booking-confirmation.php
    ├── booking-management.php          # Guest self-service portal
    └── email-templates/                # Customizable email notification templates
```

---

## Database

Three custom tables created on plugin activation:

### `wp_str_availability`
Per-day availability and pricing for each property.

| Column | Type | Description |
|---|---|---|
| `property_id` | int | Links to `str_property` CPT |
| `date` | date | Calendar date |
| `status` | varchar | `available` or `blocked` |
| `price_override` | decimal | Optional daily rate override |
| `booking_id` | int | Set when date is confirmed booked |
| `block_reason` | varchar | Reason for manual blocks |

Unique index on `(property_id, date)`.

### `wp_str_cohosts`
Co-host Stripe Connect relationships and revenue split configuration.

| Column | Type | Description |
|---|---|---|
| `property_id` | int | Property the cohost is attached to |
| `user_id` | int | Optional WP user |
| `stripe_account_id` | varchar | Stripe Connect account ID |
| `split_type` | enum | `percentage` or `fixed` |
| `split_value` | decimal | 0–1 for percent, dollar amount for fixed |
| `is_active` | tinyint | Soft delete flag |

### `wp_str_calendar_imports`
iCal feed subscriptions for importing external platform bookings.

| Column | Type | Description |
|---|---|---|
| `property_id` | int | Target property |
| `feed_url` | text | iCal URL (Airbnb/VRBO/etc.) |
| `platform` | varchar | Detected platform name |
| `last_synced` | datetime | Last successful sync |
| `sync_status` | varchar | `success`, `error`, `pending` |

### Custom Post Types
- **`str_property`** — Property listings. All rates, fees, rules, and settings stored as post meta.
- **`str_booking`** — Bookings stored as WP posts. Guest info, amounts, payment details, and status all in post meta.

---

## REST API Endpoints

Base: `/wp-json/str-booking/v1/`

| Method | Path | Description |
|---|---|---|
| `POST` | `/availability` | Check date range availability for a property |
| `POST` | `/pricing` | Calculate full price breakdown for dates + guest count |
| `POST` | `/booking` | Create booking and initiate payment |
| `GET` | `/booking/{id}` | Get booking details (authenticated via guest token) |
| `POST` | `/booking/{id}/finalize` | Confirm booking after client-side payment |
| `POST` | `/stripe-webhook` | Stripe event receiver (signature verified) |
| `GET` | `/calendar/{property_id}` | Public iCal feed for a property |
| `GET` | `/admin/metrics` | Dashboard metrics (admin only) |
| `GET` | `/admin/availability/{property_id}` | Admin calendar view |

---

## Shortcodes

### `[str_booking_form]`
Embeds the full booking widget — date selection, pricing, guest info, and payment — on any page or post.

```
[str_booking_form property_id="123"]
```

`property_id` is optional and defaults to the property linked to the current post.

### `[str_availability_calendar]`
Embeds a read-only availability calendar.

```
[str_availability_calendar property_id="123"]
```

---

## Booking Flow

1. **Date selection** — Guest picks check-in/check-out; frontend calls `/availability` to validate.
2. **Pricing** — Frontend calls `/pricing`; PricingEngine returns itemized breakdown (nightly, cleaning fee, taxes, discounts, security deposit).
3. **Guest info** — Guest enters name, email, phone, requests, and selects a payment plan (pay-in-full, 2-payment, or 4-payment).
4. **Booking creation** — `POST /booking` re-checks availability, creates a `str_booking` post (status: `pending`), then:
   - **Stripe**: Creates a PaymentIntent, returns client secret to the frontend.
   - **Square**: Charges the nonce directly, confirms immediately.
5. **Payment confirmation**:
   - **Stripe**: Frontend calls `stripe.confirmPayment()`; Stripe webhook fires → `PaymentHandler` confirms booking and reserves dates.
   - **Square**: Booking confirmed synchronously in the API response.
6. **Post-payment**: Availability rows marked booked, co-host transfers scheduled via WP-Cron, confirmation email + SMS sent to guest. For multi-payment plans, installment cron jobs scheduled.
7. **Installments**: On due date, `PaymentPlanManager` charges the saved Stripe Customer/PaymentMethod off-session. No raw card data is ever stored.
8. **Lifecycle notifications**: Pre-arrival, check-in instructions, check-out reminder, review request — all fired by WP-Cron at configurable intervals.

---

## Payment Processing

### Stripe
- **PaymentIntents** — used for all Stripe bookings
- **Stripe Connect** — hosts connect co-host accounts via OAuth; plugin uses the Separate Charges + Transfers pattern to split revenue
- **Customer vault** — for multi-payment plans, a Stripe Customer and PaymentMethod are saved (no card data stored server-side); future installments charged off-session
- **Webhooks** — `payment_intent.succeeded` and `payment_intent.payment_failed` handled at `/stripe-webhook`

### Square
- **Web Payments SDK** — loaded from CDN on the frontend; generates a nonce
- **REST API** — `SquareHandler` charges the nonce server-side using WordPress HTTP API
- **Idempotency keys** — prevent duplicate charges on retries
- Supports pay-in-full only (no installment plans yet)

### Co-host Splits
Each property can have multiple co-hosts assigned. On booking confirmation, transfers to each co-host's Stripe Connect account are scheduled as separate WP-Cron events. Splits can be `percentage` (0–1) or `fixed` (dollar amount).

---

## Calendar Sync

### Importing External Calendars
Go to **STR Booking > Calendar Sync** and add an iCal feed URL from Airbnb, VRBO, Booking.com, or any `.ics` endpoint. The plugin syncs on a WP-Cron schedule, blocking imported dates in `wp_str_availability`.

### Exporting Your Calendar
Each property has a public iCal endpoint:
```
https://yoursite.com/wp-json/str-booking/v1/calendar/{property_id}
```
Subscribe to this URL in Airbnb/VRBO to push your direct booking availability back to external platforms.

---

## Notifications

Configurable templates for all guest touchpoints — edit under **STR Booking > Notifications**:

| Trigger | Channel |
|---|---|
| Booking confirmation | Email + SMS |
| Payment received (installment) | Email |
| Pre-arrival reminder | Email + SMS |
| Check-in instructions | SMS |
| Check-out reminder | Email + SMS |
| Review request | Email |
| Booking cancellation | Email |

**Email** uses `wp_mail()`. **SMS** uses the Twilio REST API — configure Account SID, Auth Token, and from number in Settings.

---

## Admin Settings

**Settings > STR Booking**

| Setting | Description |
|---|---|
| Payment gateway | Stripe or Square |
| Stripe keys | Publishable key, secret key, webhook secret |
| Stripe Connect client ID | For co-host OAuth onboarding |
| Square credentials | App ID, access token, location ID, environment |
| Currency | USD, EUR, etc. |
| Tax rate | Global default (overridable per property) |
| Business name + email | Used in notification templates |

Per-property settings (in the property post editor): nightly rate, cleaning fee, security deposit, LOS discount tiers, tax override, and payment plan defaults.

---

## Development Setup

**Requirements:** PHP 8.0+, WordPress 6.0+, Node.js 18+, Composer

```bash
# PHP dependencies
composer install

# JS dependencies
npm install

# Build for development (watch mode)
npm run start

# Production build
npm run build
```

Compiled assets go to `assets/js/` and are committed to the repo so the plugin works without a build step on production.

---

## Plugin Updates

The plugin checks for updates against this GitHub repository using the built-in `PluginUpdater` class. WordPress will surface available updates in the standard Plugins dashboard.

---

## Architecture Notes

- **Singleton orchestrator** — `STRBooking` class in `class-str-booking.php` instantiates all manager classes on `plugins_loaded`. Nothing is loaded at the global scope except the singleton getter.
- **No raw card data** — Card details never touch the server. Stripe handles PCI compliance via PaymentIntents + client-side SDK. Only Stripe Customer/PaymentMethod IDs are stored for installment plans.
- **Availability locking** — Availability is re-checked server-side on booking creation (not just on date selection) to prevent double bookings in concurrent sessions.
- **Idempotency** — Square payments use idempotency keys. Stripe uses webhook-driven confirmation (not client callbacks alone) so a failed webhook retry can't double-confirm a booking.
- **REST API security** — Admin endpoints require `manage_options` capability. Public booking endpoints use nonce verification and rate limiting.
