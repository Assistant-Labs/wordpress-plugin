# AssistantLabs for WordPress

Connect your WordPress site to your [AssistantLabs](https://assistantlabs.io) agent.

## Install

Download the zips from the [latest release](https://github.com/Assistant-Labs/wordpress-plugin/releases/latest),
then in WordPress admin go to **Plugins → Add New → Upload Plugin**.

| Plugin | What it does |
| --- | --- |
| **AssistantLabs** (`assistantlabs.zip`) | Connects your site to an AssistantLabs agent. Build an agent from your site or connect one you already have. Chat on your site, form leads into your CRM, and manage the site from Claude, ChatGPT or WhatsApp. |
| **AssistantLabs for WooCommerce** (`assistantlabs-for-woocommerce.zip`) | Add-on, needs the plugin above and WooCommerce. Your agent answers product and order questions, drafts product, price, stock and coupon changes, and recovers abandoned carts. |

Then open **AssistantLabs** in the WordPress admin menu and click **Connect**.

## Your approval comes first

Everything the agent changes on your site is saved as a **draft**. Nothing goes live until you publish it,
from Claude, from a WhatsApp approval, or from **AssistantLabs → Pending changes** in WordPress admin.
Chat on your site, form leads and abandoned-cart capture are all **off until you switch them on**.

## Requirements

WordPress 6.5+, PHP 7.4+. The add-on needs WooCommerce 8.2+.

## What leaves your site

The plugin talks to AssistantLabs only after you connect. The readme.txt of each plugin lists exactly what is sent and when
(the "External services" section).

## Build the zips

```bash
bash scripts/build-zips.sh   # writes dist/
```

## License

GPLv2 or later. See [LICENSE](LICENSE).
