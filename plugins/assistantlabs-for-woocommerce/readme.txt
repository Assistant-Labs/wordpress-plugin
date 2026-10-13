=== AssistantLabs for WooCommerce ===
Contributors: assistantlabs
Tags: woocommerce, ai chatbot, ai agent, whatsapp, customer support
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: assistantlabs, woocommerce
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your AssistantLabs agent answers product and order questions, drafts product and coupon changes for you to approve, and recovers abandoned carts.

== Description ==

An add-on for the **AssistantLabs** plugin. It connects your store with the same click that connects your site.

* **Your agent knows your store.** Products, prices, stock, shipping zones, and your returns, terms and privacy pages become part of what your agent knows.
* **Sales at a glance.** Ask Claude, ChatGPT or WhatsApp "how did we do today?" and get orders, revenue and low-stock products.
* **Draft first, always.** Ask for a new product, a price change, a stock update or a coupon. New products and coupons are saved as drafts, and a change to a live product waits on the Pending changes screen until you publish it. Nothing reaches shoppers without your approval.
* **Orders and customers in real time.** New, paid and cancelled orders and new customers reach AssistantLabs as they happen, so your agent can answer "where is my order?" and your sales journeys can follow up. Everything is sent in the background with WooCommerce's Action Scheduler and retried if AssistantLabs is briefly unavailable: checkout never waits for it.
* **Abandoned-cart recovery (off by default).** Turn on **Recover abandoned carts** in AssistantLabs → Store. When a shopper types an email or phone number at checkout (classic or block checkout) and leaves without ordering, the cart is saved for 30 days and your sales journey can remind them with a link that restores it (`/?al_cart=…`).
* **Cart links.** Links like `/?al_add=12:1&coupon=WELCOME10` fill a shopper's cart with the products and coupon your agent or journey picked and open checkout.
* **Compatible with High-Performance Order Storage (HPOS).**

Requires the **AssistantLabs** plugin and **WooCommerce**.

== External services ==

This add-on works through the AssistantLabs service (https://assistantlabs.io), using the connection made by the AssistantLabs plugin.

* **When you connect** — the add-on creates a WooCommerce REST API key (read and write) for the dedicated AssistantLabs user and sends it to AssistantLabs with the connection, together with your WooCommerce version. AssistantLabs uses it to look up products and orders for your customers. The key is revoked when you disconnect or uninstall.
* **When your agent reads the store** — AssistantLabs calls this site, signed with the shared secret, to read products, shipping zones, policy pages, and a sales summary (order count, revenue, the latest orders with customer names, and low-stock products).
* **When your agent drafts changes** — AssistantLabs asks this site to create draft products or coupons, or to store a proposed product change. They go live only when you publish them.
* **Store events, while the site is connected** — when an order is created, paid (processing or completed), cancelled, refunded or failed, the add-on sends AssistantLabs the order: its number, status, totals, coupon codes, products (name, quantity, price, image and product links), shipping method and tracking number, and the customer's name, email, phone, order count and total spent. When a customer account is created or its contact details change, it sends that customer's name, email, phone, order count and total spent. Staff accounts are never sent.
* **Abandoned carts (off by default)** — only after you turn on "Recover abandoned carts": when a shopper enters an email address or phone number at checkout, the add-on saves their cart on this site for up to 30 days and sends AssistantLabs the cart contents (products, quantities, prices, coupons, total), the email, phone and name entered, a link that restores the cart, and their marketing consent when your checkout has an opt-in checkbox. When the cart is emptied or becomes an order, it sends a notice that the cart is gone and deletes the saved cart.

Store events are signed with the site's shared secret and sent to the AssistantLabs API (https://server-150134556021.us-central1.run.app, path /vendor/wordpress/site/woocommerce/events). Saved carts can be exported and erased with WordPress's personal-data tools (Tools → Export / Erase Personal Data), and the add-on suggests text for your privacy policy (Settings → Privacy).

* Terms of Service: https://assistantlabs.io/terms
* Privacy Policy: https://assistantlabs.io/privacy

== Installation ==

1. Install and activate the AssistantLabs plugin and WooCommerce.
2. Install and activate AssistantLabs for WooCommerce.
3. Open **AssistantLabs** in the WordPress admin menu and connect. Already connected before installing the add-on? Nothing to do: the next time you open the AssistantLabs screen, the store joins the same agent.
4. Optional: in **AssistantLabs → Store**, turn on **Recover abandoned carts**, and add the suggested text to your privacy policy.

== Frequently Asked Questions ==

= Does this slow down checkout? =

No. Store events are written to a small queue and sent by WooCommerce's Action Scheduler in the background. If AssistantLabs is unavailable, an event is retried up to five times over about an hour and a half.

= Which marketing opt-in does it read? =

Only an explicit opt-in checkbox on your checkout (for example MailPoet's). Developers can name their own field with the `assistantlabs_wc_marketing_consent_fields` filter. Without one, no consent is sent.

== Changelog ==

= 2.1.0 =
* Store events: orders (created, paid, cancelled) and customers reach AssistantLabs in the background, with retries.
* Abandoned-cart recovery for classic and block checkout (off by default), with restore links and cart links.
* Personal-data export and erase for saved carts, and a suggested privacy-policy text.

= 2.0.0 =
* Rebuilt as an add-on to the AssistantLabs plugin: one connection, signed calls, store tools, and drafts you approve. The old settings page that embedded the AssistantLabs app is gone.
