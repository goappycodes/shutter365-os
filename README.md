# Shutters365 — Business OS

A full-screen, owner-facing business dashboard for the Shutters365 WooCommerce
store. It gives Jamie one place to run the whole business: sales, the order
pipeline, delivery risk, leads, analytics, margin per sale, vendor payments and
support — pulling live data from WooCommerce and the lead CRM, with clearly
labelled sample data where a data source doesn't exist in the system yet.

## Where it lives

- **URL:** `https://shutters365.co.uk/admin/` (fallbacks: `/owner/`, `/business-os/`)
- **Auth:** delegated entirely to WordPress. Logged-out visitors are bounced to
  `wp-login` and returned here after signing in; a logged-in user without the
  `manage_woocommerce` (or `manage_options`) capability is refused. No separate
  login, no separate password.
- It renders a standalone HTML document and `exit`s, so it never loads the
  marketing theme's chrome and can't affect the rest of the site.

## Files

| File | Responsibility |
|------|----------------|
| `loader.php` | Routing (`template_redirect`), the wp-admin auth gate, the wp-admin menu/toolbar shortcuts. |
| `data.php`   | Every query. Pulls orders, revenue, pipeline, delayed orders, leads, top products/customers/cities, margin, a projected vendor ledger and support. Cached in a 10-minute transient. |
| `render.php` | The dashboard view — KPI tiles, funnels, tables and inline SVG/CSS charts. All output escaped; sample sections badged. |

## Installing into the theme

One line in the theme's `functions.php`, loaded on the **front end** (not gated
behind `is_admin()`, because `/admin/` is a front-end route):

```php
require_once S365_DIR . '/inc/business-os/loader.php';
```

## Real vs. sample data

Everything the store already records is **live**: revenue, order counts, the
fulfilment pipeline (Design → Manufacturing → In transit → With courier →
Delivered), delayed orders, the lead CRM, top products, top customers and cities.

These are **projected / sample** (badged in the UI) until their data source is
captured, and are shown to illustrate the full Business OS:

- **Margin & cost of goods** — estimated at a configurable gross-margin rate
  (`s365_bos_margin_rate`, default 55%) until per-product supplier costs are
  entered; then it's exact, per sale.
- **Vendor payments** — a projected owed-vs-paid ledger built from each
  in-production order's estimated cost, until real vendor invoices are entered.
- **Material/finish split** — until the configurator's chosen material is mapped
  to structured line-item meta.
- **Support queue** — until the info@ inbox / helpdesk is connected.

## Roadmap hooks

The dashboard is the capstone of the wider "sample-to-shutter" automation plan:
per-stage timestamps (turning approximate time-in-stage into exact), a vendor PO
record, the support inbox and a weekly digest email all feed straight into it.

---
Part of the Shutters365 theme. Deployed with the theme (git pull on the server).
