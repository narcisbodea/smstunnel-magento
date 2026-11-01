# SMSTunnel for Magento

`smstunnel/smstunnel-magento`: order texts for **Magento Open Source, Adobe Commerce and Mage-OS** (2.4.6 to 2.4.9), sent from **your own Android phone**, from **your own number**.

Your store hands each text to your SMSTunnel account and your phone sends it from its own SIM, so customers see a number they know and can reply to. You can cut the store off at any time by deleting its API key in your SMSTunnel dashboard.

Your phone needs the SMSTunnel app from Google Play:

<a href="https://play.google.com/store/apps/details?id=io.smstunnel.app"><img src="https://smstunnel.io/google-play-badge.png" alt="Get it on Google Play" height="56"></a>

## What it sends

| Text | Goes to | When |
| --- | --- | --- |
| **Order placed** | the customer | an order is placed (payments finished on another site: once the payment goes through) |
| **New order** | you and your team, one text per number | an order is placed |
| **Invoice created** | the customer | an invoice is created |
| **Shipment created** | the customer | a shipment is created, with its tracking number |
| **Order canceled** | the customer | the order is canceled |
| **Order status changed** | the customer | the order gets one of the statuses you pick (On Hold, say) |

Every text has its own on/off switch and its own wording, for each website and store view.

- **Checkout never waits.** Texts are queued and sent in the background by Magento's message queue, with a cron job as a fallback. If SMSTunnel cannot be reached, checkout carries on and the text is tried again after 1, 5, 15 and 60 minutes.
- **Numbers that work.** The number comes from the shipping address (or the billing address) and is completed with the country calling code when the customer left it out: `(415) 555-0123` on a US address becomes `+14155550123`, `0722 123 456` on a Romanian one `+40722123456`. Numbers that are not valid, short codes and premium-rate numbers are skipped, with the reason.
- **Never twice.** Each text is queued once, however many times the order is saved.
- **A list of every text** under **Sales → SMSTunnel Texts**: status, reason when one failed or was skipped, **Send Again** and **Delete**.

## Requirements

- Magento Open Source, Adobe Commerce or Mage-OS 2.4.6, 2.4.7, 2.4.8 or 2.4.9, with PHP 8.1 to 8.5 (what your Magento version supports)
- Magento's cron running (every Magento store needs it)
- An Android phone with the [SMSTunnel app](https://play.google.com/store/apps/details?id=io.smstunnel.app) and an SMSTunnel account

No other packages are needed: the module talks to SMSTunnel with Magento's own HTTP client.

## Installation

```bash
composer require smstunnel/smstunnel-magento
bin/magento module:enable SmsTunnel_SmsNotifications
bin/magento setup:upgrade
bin/magento cache:flush
```

In production mode, also run `bin/magento setup:di:compile` and `bin/magento setup:static-content:deploy` before `cache:flush`.

## Setup

1. **Link your phone.** Install the [SMSTunnel app from Google Play](https://play.google.com/store/apps/details?id=io.smstunnel.app) on your Android phone and sign in. Sign in at [smstunnel.io](https://smstunnel.io) on your computer with the same account: the phone appears under **Phones** (you can also link it with the QR code from **Phones → Add phone**).
2. **Create a key for the store.** In your [SMSTunnel dashboard](https://dashboard.smstunnel.io/apps), open **Apps → Add an app** and copy its key. The key can send texts and look up whether they went out.
3. **Connect Magento.** In the Magento admin, open **Stores → Configuration → SMSTunnel → Order Texts → Your Phone**:
   - **Send Order Texts**: Yes
   - **API Key**: paste the key (Magento stores it encrypted)
   - **Phone ID** (optional): to always use one phone, copy its ID from **Phones** in your dashboard (it has a Copy button). Leave it empty to let SMSTunnel use one of your phones.
   - **Default Country Code** (optional): for example `+1`. It completes numbers written without a country code when the address has no country, and your own numbers. Empty means the store's default country.

   Click **Save Config**.
4. **Send a test text.** Type your mobile number in **Test Number** and click **Send a Test Text**. Your phone sends it within seconds.
5. **Pick the texts.** Open each group (**New-Order Texts to You**, **Customer Text: Order Placed**, ...), switch it on or off and change the wording. Use the scope switcher at the top left to give a website or store view its own wording, language or even its own phone.

### Placeholders

| Placeholder | Becomes |
| --- | --- |
| `{{order_id}}` | the order number, e.g. `000000123` |
| `{{customer_firstname}}`, `{{customer_lastname}}` | the customer's name |
| `{{grand_total}}` | the order total, e.g. `$30.00` |
| `{{store_name}}` | the store name (Stores → Configuration → General → Store Information, else the store's name) |
| `{{status}}` | the order status, as the store view shows it to customers |
| `{{tracking_number}}`, `{{carrier}}` | shipment text only: the tracking numbers and carriers |

A line holding `{{tracking_number}}` or `{{carrier}}` is left out when the shipment has no tracking number, so the default shipment text works with and without tracking:

```text
Good news, {{customer_firstname}}: your order #{{order_id}} from {{store_name}} is on its way.
Tracking number: {{tracking_number}}
```

A text can be up to 1600 characters (a long text arrives as several joined SMS).

## How texts go out

1. An order event (placed, invoiced, shipped, canceled, a status change) writes the text to the `smstunnel_message` table and publishes its ID to the `smstunnel.message.send` queue (MySQL queue, so no RabbitMQ is needed).
2. The `smstunnel.message.send` consumer hands it to SMSTunnel. Magento's cron starts consumers every minute (`consumers_runner`); you can also run it yourself:

   ```bash
   bin/magento queue:consumers:start smstunnel.message.send
   ```

   If your `app/etc/env.php` limits `cron_consumers_runner` to a list of consumers, add `smstunnel.message.send`, or leave it out: the `smstunnel_send_pending` cron job sends whatever is waiting every minute anyway (up to 25 texts per run).
3. Errors that can pass (no connection, too many requests, a problem on SMSTunnel's side) are retried after 1, 5, 15 and 60 minutes, then the text is marked **Failed**. A wrong API key or a refused number fails at once.
4. If your phone is offline, SMSTunnel keeps the text and your phone sends it as soon as it is back online; the list shows a note.

Errors and retries are also written to `var/log/smstunnel.log`.

## Sent texts

**Sales → SMSTunnel Texts** lists every text: when it was queued, what it is for, the order, the number, the text, its status (**Waiting**, **Sent to your phone**, **Failed**, **Skipped**) and the reason when it failed or was skipped. Select texts and use the **Actions** menu to **Send Again** (texts that failed or were sent) or **Delete**.

The list keeps texts for 90 days by default (**Stores → Configuration → SMSTunnel → Order Texts → Sent Texts**); 0 keeps them. They hold customers' phone numbers, so keep them only as long as you need them.

Admin roles: **Sales → Operations → SMSTunnel Texts** (view), its child **Send Again and Delete**, and **Stores → Settings → Configuration → SMSTunnel** (settings and the test text).

## Uninstall

```bash
bin/magento module:uninstall --remove-data SmsTunnel_SmsNotifications
```

This removes the list of texts, the settings (the API key included) and the queue, then runs `composer remove`. To keep the data, use `bin/magento module:disable SmsTunnel_SmsNotifications` instead.

## Build it with an AI assistant

Want texts for something the module does not cover, such as a back-in-stock alert or an appointment reminder? Copy this prompt into Claude, ChatGPT, Cursor or Copilot and replace the part in brackets. Never put a real API key in a prompt.

```text
I run a Magento 2 store (Magento Open Source / Adobe Commerce / Mage-OS 2.4.6 to 2.4.9) and want it to send
text messages with SMSTunnel. SMSTunnel sends texts from my own Android phone, from my own number: my code hands
each text to my SMSTunnel account and the SMSTunnel app on my phone sends it from its own SIM.

The module SMSTunnel for Magento is installed (composer require smstunnel/smstunnel-magento,
bin/magento module:enable SmsTunnel_SmsNotifications, bin/magento setup:upgrade). It already texts customers about
their orders and keeps its API key encrypted in Magento's configuration (path smstunnel/general/api_key).

SMSTunnel API facts:
- POST https://smstunnel.io/api/v1/sms/send with the headers X-API-Key: <key> and Content-Type: application/json.
- Body: {"to": "+14155550123", "message": "...", "deviceId": "<optional phone ID>", "defaultCountryCode": "<optional, e.g. +44>"}.
- Success: {"success": true, "messageId": "...", "data": {"messageId": "...", ...}}.
- One recipient per call: to text several people, send one text to each.
- Up to 1600 characters per text; about 30 send requests a minute.
- Numbers in international format (+ and country code). defaultCountryCode completes numbers written without + or 00,
  and it always wins over the phone's own country.
- Sign-in and verification codes go to POST https://smstunnel.io/api/v1/sms/send-2fa instead: the text must contain a
  code of 4 to 10 digits standing on its own (e.g. 482913 or 123-456), at most 320 characters.
- Errors are JSON such as {"message": "Invalid or expired API key", "statusCode": 401}, sometimes with a "code":
  401 = wrong or deleted key; 403 = the key lacks a permission (code PERMISSION_REQUIRED) or the plan lacks the
  feature; 429 = too many requests, wait and try again; 5xx = try again later.
- If the phone is offline, SMSTunnel keeps the text and the phone sends it when it is back online.

What I want: [describe what should send a text, to whom, and when]

Rules for you:
- Write it as a small Magento 2 module (observer, cron job or queue consumer). Send in the background, never inside
  the request that places an order or loads a page.
- Keep the API key out of source code and out of anything sent to the browser: read it from Magento's encrypted
  configuration or app/etc/env.php.
- Validate numbers and turn them into international format before sending; skip the ones that are not valid.
- Handle the errors above: retry 429 and 5xx later, do not retry 401 and 403.
- Only send texts people expect from my store, such as order updates, reminders or codes they asked for.
```

## Troubleshooting

- **No texts at all.** Check that Magento's cron runs (`bin/magento cron:run` from crontab), that **Send Order Texts** is Yes for the store view, and the **Status** and **Details** columns under **Sales → SMSTunnel Texts**.
- **"SMSTunnel answered 401"**: the API key is wrong or was deleted. Create a new one under **Apps → Add an app**.
- **"Waiting" for a while**: SMSTunnel could not be reached or asked to slow down; the text is tried again by itself. The **Details** column says why.
- **A text was "Skipped"**: the number on the order is not valid for its country, or texts were turned off before it went out.

## Responsible use

Send only texts that people expect from you: order updates, alerts to yourself, reminders. Unsolicited messages from a personal SIM break most carriers' terms of service. Please make sure your usage complies with your carrier's terms and with local regulations (in the EU, that includes GDPR and ePrivacy rules on electronic communications).

## Development

The module has unit tests (`Test/Unit`, run with Magento's `dev/tests/unit/phpunit.xml.dist`) and, under `dev/`, a Docker stack and an end-to-end test that places real orders against a local SMSTunnel test server. `dev/` is not part of the package.

```bash
dev/docker/setup-magento.sh 249        # Magento 2.4.9 on PHP 8.5, at http://127.0.0.1:8476/
SMSTUNNEL_KEY_FILE=/path/to/test-key.json STACK=smst-int-magento249 WEB_PORT=8476 node dev/e2e/e2e.mjs
```

## Trademarks

Magento and Adobe Commerce are trademarks of Adobe Inc. Mage-OS is a project of the Mage-OS Association. SMSTunnel for Magento is not affiliated with, endorsed by, or sponsored by Adobe Inc. or the Mage-OS Association.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT, © NARBOWEB SRL. See [LICENSE](LICENSE).
