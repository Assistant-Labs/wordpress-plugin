=== AssistantLabs ===
Contributors: assistantlabs
Tags: ai chatbot, live chat, ai agent, lead generation, whatsapp
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI agent for your site: it chats with visitors, sends form leads to your CRM, and drafts site changes you approve from Claude, ChatGPT or WhatsApp.

== Description ==

AssistantLabs connects your WordPress site to an AI agent that knows your business.

* **Build an agent from your site.** The agent reads your pages, posts and policies and is ready to answer customers in minutes. Already have an agent? Connect it instead.
* **Chat on your site.** Show your agent in a chat bubble on every page, or hide it on the pages you choose.
* **Form leads into your CRM.** Submissions from Contact Form 7, WPForms, Elementor Pro Forms and Gravity Forms become contacts in your AssistantLabs CRM, so your agent can follow up on WhatsApp.
* **Manage your site from Claude, ChatGPT or WhatsApp.** Ask for a new page, a better title, or an edit to your opening hours. The agent saves every change as a draft and asks you when to publish it.
* **Nothing goes live on its own.** Every change waits on the Pending changes screen with a preview. Publish now, schedule it, or discard it. Changes are attributed to a dedicated "AssistantLabs" user, so your revision history shows exactly what the agent did.

Selling with WooCommerce? Add **AssistantLabs for WooCommerce** to let the agent answer product and order questions and draft product, price, stock and coupon changes under the same approval rule.

An AssistantLabs account is required.

== External services ==

This plugin connects to AssistantLabs (https://assistantlabs.io), the service that runs your AI agent. It sends data only after you connect your site, and only as described here.

* **Connecting** — when you click "Connect my agent" or "Build an agent from this site", your browser goes to the AssistantLabs app to sign in. The plugin then sends AssistantLabs your site address, REST API address, site name and tagline, language, WordPress and plugin versions, a site ID and a shared signing secret.
* **Your agent reading the site** — after you connect, AssistantLabs calls this site's REST API (namespace `assistantlabs/v1`), signed with the shared secret, to read your pages, posts and drafts and, when you ask it to, to create drafts and proposed changes. It never publishes anything unless you approve.
* **Status and updates** — the AssistantLabs admin screen asks AssistantLabs for your agent's status. When you press "Update the agent from my site", AssistantLabs reads the site again. The plugin never triggers this on its own.
* **Form leads (off by default)** — when you turn on Form leads, each submission of a supported form is sent to AssistantLabs: the form name, the page address and the fields the visitor filled in. Password and payment fields are never sent.
* **Chat (off by default)** — when you turn on the chat, visitors' browsers load the chat script from the AssistantLabs app (assistantlabs-loader.js) and send their chat messages to AssistantLabs.

Service endpoints: the AssistantLabs API (https://server-150134556021.us-central1.run.app) and app (https://assistantlabs.io).

* Terms of Service: https://assistantlabs.io/terms
* Privacy Policy: https://assistantlabs.io/privacy

== Installation ==

1. Install and activate the plugin.
2. Open **AssistantLabs** in the WordPress admin menu.
3. Click **Build an agent from this site** or **Connect my agent**, and sign in.
4. Turn on **Chat on your site** and **Form leads** when you are ready.

== Frequently Asked Questions ==

= Can the agent change my live site without asking? =

No. Every edit to a published page or post is stored as a proposed change, and new content is saved as a draft. Only you can publish it: on the Pending changes screen, in Claude, or by approving on WhatsApp.

= Why is there a new "AssistantLabs" user? =

So every change the agent makes is attributed in your revision history. Nobody can sign in as this user. It is kept if you uninstall the plugin, so your history stays readable.

= Does the plugin use Application Passwords? =

No. Calls between your site and AssistantLabs are signed with a shared secret in custom headers, which works on hosts that strip the Authorization header.

== Changelog ==

= 1.0.0 =
* First release: connect or build an agent, chat widget, form leads, and drafts you approve.
