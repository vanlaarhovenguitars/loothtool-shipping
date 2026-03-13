# Loothtool Shipping

A custom WordPress plugin that adds **live Shippo shipping rates** at checkout, **multi-vendor package splitting** for Dokan marketplaces, and a **vendor label purchasing dashboard** — all with automatic Dokan balance deduction.

Built for [Loothtool.com](https://loothtool.com) — a WooCommerce + Dokan multi-vendor marketplace.

---

## Features

### Live Shipping Rates at Checkout
- Calls the [Shippo API](https://goshippo.com) in real time to return carrier rates (UPS, USPS, FedEx, DHL, and more)
- Rates appear as selectable options on the WooCommerce checkout page
- Each rate shows carrier name, service level, estimated delivery days, and price

### Multi-Vendor Package Splitting
- When a cart contains items from multiple Dokan vendors, the cart is automatically split into one shipment per vendor
- Each vendor's items are rated separately using that vendor's own store address as the ship-from location
- Customers see the total shipping cost across all vendors combined

### Vendor Label Purchasing (Dokan Dashboard)
- Vendors get a **"Shipping Labels"** tab in their Dokan dashboard
- After an order comes in, the vendor clicks **Get Shipping Rates** to fetch live carrier options for that order
- The vendor selects a rate and clicks **Buy Label** — the label is purchased from Shippo instantly
- The tracking number is saved to the order and shown to the customer on their order detail page
- Labels are available to download as PDF (or PNG / ZPL for thermal printers)

### Automatic Dokan Balance Deduction
- Label costs are **automatically deducted from the vendor's Dokan earnings balance** — no manual billing
- The vendor sees their current available balance at the top of the Shipping Labels page before buying
- Platform owners can set a **markup percentage** (e.g. 10%) — the vendor pays the marked-up price, the difference stays with the platform
- If a label purchase fails for any reason, the deduction is **automatically refunded** to the vendor's balance
- All transactions are logged in Dokan's balance table with full audit trail

### Admin Controls
- Simple settings page under **WooCommerce → Shippo Shipping**
- Set the Shippo API key (test or live)
- Set a default ship-from address (used as fallback if a vendor hasn't filled in their store address)
- Configure default parcel dimensions and weight (used when products don't have dimensions set)
- Set a platform markup % on label purchases
- Choose label format: PDF, PNG, or ZPLII

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.0+ |
| WooCommerce | 7.0+ |
| Dokan Lite | 3.7+ |
| PHP | 8.1+ |
| Shippo account | Free — [goshippo.com](https://goshippo.com) |

---

## Installation

1. Download or clone this repo into your WordPress plugins folder:
   ```
   wp-content/plugins/loothtool-shipping/
   ```

2. In WordPress Admin → **Plugins** → find **Loothtool Shipping** → **Activate**

3. Go to **WooCommerce → Shippo Shipping** and enter your Shippo API key

4. Go to **WooCommerce → Settings → Shipping** → open a shipping zone → **Add Shipping Method** → select **Shippo Live Rates** → Save

5. Each vendor should fill in their store address under **Dokan Dashboard → Settings → Store**

---

## Configuration

### Shippo API Key
Get a free API key at [goshippo.com](https://goshippo.com). Use the **test token** (`shippo_test_...`) during development — it returns real carrier rates but never charges you or creates real labels.

### Default From Address
Used as the ship-from address when a vendor hasn't set their own store address. Should be your warehouse or a central fulfillment address.

### Default Parcel Dimensions
Used when a product doesn't have weight/dimensions entered in WooCommerce. Set these to your most common box size to get reasonable rate estimates.

### Platform Markup
Set a percentage to add on top of the actual Shippo label cost. For example, if you set 10% and a label costs $5.00, the vendor's balance is debited $5.50. You keep the $0.50. Set to 0 to pass costs through at no markup.

---

## How Money Flows

```
Customer pays shipping at checkout
        ↓
Vendor earns their sale proceeds (held in Dokan balance)
        ↓
Vendor buys a label from their dashboard
        ↓
Label cost deducted from Dokan balance automatically
        ↓
Shippo charges the platform owner's account
        ↓
Platform owner is reimbursed by the deduction (+ any markup)
```

Vendors are never billed separately — it all flows through their existing Dokan earnings balance.

---

## File Structure

```
loothtool-shipping/
├── loothtool-shipping.php              # Plugin entry point, registers shipping method
├── includes/
│   ├── class-shippo-api.php            # Shippo REST API wrapper (rates + label purchase)
│   ├── class-cart-packages.php         # Splits WooCommerce cart by Dokan vendor
│   ├── class-shipping-method.php       # WooCommerce shipping method, calls Shippo per package
│   ├── class-admin-settings.php        # WooCommerce admin settings page
│   ├── class-vendor-dashboard.php      # Dokan vendor dashboard tab + balance deduction logic
│   └── class-order-labels.php          # AJAX rate fetching, customer tracking display, admin meta box
└── assets/
    └── vendor-shipping.js              # Frontend JS for vendor dashboard interactions
```

---

## Shippo API Usage

This plugin uses the following Shippo API endpoints:

| Endpoint | Purpose |
|---|---|
| `POST /shipments/` | Create a shipment and fetch live rates |
| `POST /transactions/` | Purchase a label from a rate |
| `POST /addresses/` | (Optional) Validate an address before rating |

Full Shippo API docs: [docs.goshippo.com](https://docs.goshippo.com/shippoapi/public-api/)

---

## Roadmap

- [ ] Email vendor the label PDF automatically after purchase
- [ ] Shippo tracking webhook → auto-update WooCommerce order status
- [ ] Vendor dashboard earnings report showing label spend history
- [ ] Shippo Connect / sub-accounts (vendor-direct billing, Option B)
- [ ] Admin report: platform label markup revenue by month

---

## License

MIT — free to use, modify, and deploy on your own platform.
