# Site page: SMSTunnel for Magento

Content for a future page on smstunnel.io (suggested path `/integrations/magento`). The site belongs to another
project; this file only holds the words, the structure and the assets to use.

- **Page title (≤ 60 chars):** SMSTunnel for Magento: order texts from your own phone
- **Meta description (≤ 155 chars):** Text your Magento customers about their orders from your own Android phone and
  number. Order placed, shipped with tracking, canceled. Free module.
- **Screenshots:** `D:\ANDROID\integrari\magento-screens\` (01 settings and test text, 02 all text settings,
  03 the list of sent texts)

---

## Hero

**Order texts from your own phone, for Magento**

Magento Open Source, Adobe Commerce and Mage-OS stores text customers about their orders from your own Android phone,
from your own number. Customers see a number they know, and they can simply reply.

[Get it on Google Play](https://play.google.com/store/apps/details?id=io.smstunnel.app) (badge:
`/google-play-badge.png`) · `composer require smstunnel/smstunnel-magento`

## How it works

1. **Your phone.** Install the SMSTunnel app on your Android phone and sign in.
2. **Your store.** Install the module, paste the key from **Apps → Add an app**, send a test text.
3. **Your customers.** Each order event hands a text to your SMSTunnel account, and your phone sends it from its own
   SIM.

## What your store sends

| Text | To | Example |
| --- | --- | --- |
| Order placed | the customer | Hi Ana, thanks for your order #000000042 at Luma Shop. Total: $30.00. We will text you when it ships. |
| New order | you and your team | New order #000000042 at Luma Shop: $30.00 from Ana Pop. |
| Invoice created | the customer | Hi Ana, your order #000000042 at Luma Shop has been invoiced. Total: $30.00. |
| Shipment created | the customer | Good news, Ana: your order #000000042 from Luma Shop is on its way. Tracking number: 1Z999AA10123456784 |
| Order canceled | the customer | Luma Shop: your order #000000042 has been canceled. |
| Status changed | the customer | Luma Shop: your order #000000042 is now On Hold. |

Every text can be switched on or off and reworded, for each website and store view, in any language.

## Built for real stores

- **Checkout never waits.** Texts go out in the background through Magento's message queue, with a cron job as a
  safety net. If your phone or the connection is down, checkout carries on and the text is tried again.
- **Numbers that work.** Local numbers are completed with the country calling code from the address
  (`(415) 555-0123` becomes `+14155550123`). Invalid and premium-rate numbers are skipped, with the reason.
- **Never twice.** Each text goes out once, however many times an order is saved.
- **Every text in one list.** Sales → SMSTunnel Texts shows what went out, what is waiting and why something was
  skipped, with Send Again.
- **Several stores, several phones.** Each website or store view can have its own wording, its own key, even its own
  phone.
- **Nothing else to install.** No extra libraries: the module uses Magento's own HTTP client, MySQL queue and cron.

## Install

```bash
composer require smstunnel/smstunnel-magento
bin/magento module:enable SmsTunnel_SmsNotifications
bin/magento setup:upgrade
bin/magento cache:flush
```

Then open **Stores → Configuration → SMSTunnel → Order Texts**, set **Send Order Texts** to Yes, paste the API key
from **Apps → Add an app** in your dashboard, and click **Send a Test Text**. Works with Magento Open Source, Adobe
Commerce and Mage-OS 2.4.6 to 2.4.9, PHP 8.1 to 8.5.

## Build more with an AI assistant

Need a text the module does not send, such as a back-in-stock alert? The README has a ready-to-copy prompt for
Claude, ChatGPT, Cursor or Copilot that explains the SMSTunnel API (one recipient per call, `X-API-Key`, codes of
4 to 10 digits for sign-in texts, `defaultCountryCode`, the errors to handle) and asks for a small Magento module that
keeps the key out of the code. Show the prompt on the page in a copy box, from the README section
"Build it with an AI assistant".

## FAQ

**Do I need a special SIM or number?** No. Texts leave from the phone and SIM you already have.

**What if my phone is offline?** SMSTunnel keeps the text and your phone sends it as soon as it is back online.

**Can customers reply?** Yes: the text comes from your number, so replies reach your phone.

**Does it slow down checkout?** No. Texts are queued and sent in the background.

**Which Magento versions?** Magento Open Source, Adobe Commerce and Mage-OS 2.4.6, 2.4.7, 2.4.8 and 2.4.9.

**How do I stop it?** Set Send Order Texts to No, or delete the store's key in your SMSTunnel dashboard.

## Links

- Packagist: `https://packagist.org/packages/smstunnel/smstunnel-magento` (after publishing)
- Source and documentation: `https://github.com/narcisbodea/smstunnel-magento` (after publishing)
- Adobe Commerce Marketplace listing (after review)
- Google Play: https://play.google.com/store/apps/details?id=io.smstunnel.app

## Footer note

Magento and Adobe Commerce are trademarks of Adobe Inc. Mage-OS is a project of the Mage-OS Association.
SMSTunnel for Magento is not affiliated with, endorsed by, or sponsored by Adobe Inc. or the Mage-OS Association.
