# Loothtool Shipping

A custom WordPress plugin that adds **live shipping rates** at checkout, **multi-vendor package splitting** for Dokan marketplaces, and a **vendor label purchasing dashboard** — with support for Shippo and ShipStation, and the ability for vendors to connect and be billed through their own shipping accounts.

Built for [Loothtool.com](https://loothtool.com) — a WooCommerce + Dokan multi-vendor marketplace.

---

## Features

### Live Shipping Rates at Checkout
- Calls Shippo or ShipStation in real time to return carrier rates (UPS, USPS, FedEx, DHL, and more)
- Rates appear as selectable options on the WooCommerce checkout page
- Each rate shows carrier name, service level, estimated delivery days, and price

### Multi-Vendor Package Splitting
- When a cart contains items from multiple Dokan vendors, the cart is automatically split into one shipment per vendor
- Each vendor's items are rated separately using that vendor's own store address as the ship-from location
- Customers see the total shipping cost across all vendors combined

### Two Shipping Providers Supported

| Provider | Best for | How rates work |
|---|---|---|
| **Shippo** | Rate shopping across all carriers | One API call returns rates from UPS, USPS, FedEx, DHL, and more simultaneously |
| **ShipStation** | Platforms already managing orders in ShipStation | Queries each connected carrier account, combines results |

The platform owner picks one as the default. Vendors can also connect their own account of either type.

### Vendor Label Purchasing (Dokan Dashboard)
- Vendors get a **"Shipping Labels"** tab in their Dokan dashboard
- After an order comes in, the vendor clicks **Get Shipping Rates** to fetch live carrier options for that order
- The vendor selects a rate and clicks **Buy Label** — the label is purchased instantly
- Tracking number is saved to the order and shown to the customer on their order detail page
- Labels downloadable as PDF, PNG, or ZPL (thermal printer format)

### Vendor Direct Billing — Connect Your Own Account
Vendors can connect their own Shippo or ShipStation account from the Shipping Labels dashboard:

- **When connected:** labels are purchased through the vendor's own API credentials and billed directly to their carrier account — the platform is never involved in the transaction
- **When not connected:** labels go through the platform account with automatic Dokan balance deduction (see below)
- API keys are encrypted at rest using AES-256-CBC with the WordPress auth salt

### Automatic Dokan Balance Deduction (Platform Account)
When a vendor uses the platform shipping account (no own account connected):

- Label costs are **automatically deducted from the vendor's Dokan earnings balance**
- The vendor sees their current available balance before buying
- Platform owners can set a **markup percentage** — the vendor pays the marked-up price, the difference stays with the platform
- If a label purchase fails, the deduction is **automatically refunded**
- All transactions are logged in Dokan's balance table with a full audit trail

### Admin Controls
- Settings page under **WooCommerce → Loothtool Shipping Settings**
- Choose platform provider: **Shippo** or **ShipStation**
- Enter API credentials for whichever provider you choose
- Set a default ship-from address (fallback when a vendor hasn't filled in their store address)
- Configure default parcel dimensions and weight
- Set a platform markup % on label purchases (platform account only)
- Choose label format: PDF, PNG, or ZPLII

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.0+ |
| WooCommerce | 7.0+ |
| Dokan Lite | 3.7+ |
| PHP | 8.1+ |
| Shippo account (optional) | Free — [goshippo.com](https://goshippo.com) |
| ShipStation account (optional) | Paid — [shipstation.com](https://shipstation.com) |

At least one platform provider (Shippo or ShipStation) must be configured. Vendors can additionally connect their own account of either type.

---

## Installation

1. Download or clone this repo into your WordPress plugins folder:
   ```
   wp-content/plugins/loothtool-shipping/
   ```

2. In WordPress Admin → **Plugins** → find **Loothtool Shipping** → **Activate**

3. Go to **WooCommerce → Loothtool Shipping Settings**
   - Choose your platform provider (Shippo or ShipStation)
   - Enter the API credentials

4. Go to **WooCommerce → Settings → Shipping** → open a shipping zone → **Add Shipping Method** → select **Shippo Live Rates** → Save

5. Each vendor should fill in their store address under **Dokan Dashboard → Settings → Store**

---

## Configuration

### Platform Provider
Choose **Shippo** or **ShipStation** as the platform default. This is used when a vendor hasn't connected their own account.

- **Shippo:** Get a free API key at [goshippo.com](https://goshippo.com). Use the test token (`shippo_test_...`) during development — returns real carrier rates but never charges you or creates real labels.
- **ShipStation:** Get API credentials at ShipStation → Account Settings → API Keys. Requires an active ShipStation subscription.

### Vendor Own Account
Vendors connect their own account from **Dokan Dashboard → Shipping Labels → Your Shipping Account**. They choose Shippo or ShipStation, paste their credentials, and click **Connect & Verify** — the plugin tests the key live before saving. They can disconnect at any time and fall back to the platform account.

### Default From Address
Used as the ship-from address when a vendor hasn't set their own store address. Should be your warehouse or a central fulfillment address.

### Default Parcel Dimensions
Used when a product doesn't have weight/dimensions entered in WooCommerce. Set these to your most common box size to get reasonable rate estimates.

### Platform Markup
Set a percentage to add on top of the actual label cost when a vendor uses the platform account. For example, 10% on a $5.00 label = vendor pays $5.50, you keep $0.50. Does not apply when a vendor uses their own account.

---

## How Money Flows

### Vendor using their own account (direct billing)
```
Customer pays shipping at checkout
        ↓
Vendor earns their sale proceeds
        ↓
Vendor buys label from their Dokan dashboard
        ↓
Label purchased via vendor's own Shippo/ShipStation credentials
        ↓
Vendor's carrier account is billed directly — platform not involved
```

### Vendor using platform account (Dokan balance deduction)
```
Customer pays shipping at checkout
        ↓
Vendor earns their sale proceeds (held in Dokan balance)
        ↓
Vendor buys label from their Dokan dashboard
        ↓
Label cost (+ any markup) deducted from Dokan balance automatically
        ↓
Shippo/ShipStation charges the platform owner's account
        ↓
Platform owner is reimbursed by the deduction (+ keeps the markup)
```

---

## File Structure

```
loothtool-shipping/
├── loothtool-shipping.php                  # Plugin entry point, registers shipping method
├── includes/
│   ├── class-shippo-api.php                # Shippo REST API wrapper (rates + label purchase)
│   ├── class-shipstation-api.php           # ShipStation REST API wrapper (rates + label purchase)
│   ├── class-provider-factory.php          # Resolves which provider/credentials to use per vendor
│   ├── class-vendor-credentials.php        # Vendor own-account connect UI + encrypted key storage
│   ├── class-cart-packages.php             # Splits WooCommerce cart by Dokan vendor
│   ├── class-shipping-method.php           # WooCommerce shipping method, calls provider per package
│   ├── class-admin-settings.php            # WooCommerce admin settings page
│   ├── class-vendor-dashboard.php          # Dokan vendor dashboard tab + balance deduction logic
│   └── class-order-labels.php              # AJAX rate fetching, customer tracking display, admin meta box
└── assets/
    └── vendor-shipping.js                  # Frontend JS for vendor dashboard interactions
```

---

## Provider API Reference

### Shippo
| Endpoint | Purpose |
|---|---|
| `POST /shipments/` | Create a shipment and fetch live rates across all carriers |
| `POST /transactions/` | Purchase a label from a rate ID |

Full docs: [docs.goshippo.com](https://docs.goshippo.com/shippoapi/public-api/)

### ShipStation
| Endpoint | Purpose |
|---|---|
| `GET /carriers` | List all connected carrier accounts |
| `POST /shipments/getrates` | Get rates for a specific carrier |
| `POST /shipments/createlabel` | Purchase and generate a label |

Full docs: [shipstation.com/developer](https://www.shipstation.com/docs/api/)

---

## Roadmap

- [ ] Email vendor the label PDF automatically after purchase
- [ ] Shippo/ShipStation tracking webhook → auto-update WooCommerce order status
- [ ] Vendor dashboard earnings report showing label spend history
- [ ] Admin report: platform label markup revenue by month
- [ ] Support for additional providers (EasyPost, Stamps.com)

---

## License

MIT — free to use, modify, and deploy on your own platform.
