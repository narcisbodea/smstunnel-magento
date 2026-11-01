# Changelog

All notable changes to `smstunnel/smstunnel-magento` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-02

### Added

- Module `SmsTunnel_SmsNotifications` for Magento Open Source, Adobe Commerce and Mage-OS 2.4.6 to 2.4.9 (PHP 8.1 to 8.5).
- **Stores → Configuration → SMSTunnel → Order Texts**, per website and store view: API key (stored encrypted),
  optional phone ID, default country code, and **Send a Test Text**.
- Customer texts for order placed, invoice created, shipment created (with tracking numbers and carriers), order
  canceled and order status changed (statuses you pick), each with its own switch and template.
- New-order texts to one or more of your own numbers.
- Placeholders `{{order_id}}`, `{{customer_firstname}}`, `{{customer_lastname}}`, `{{grand_total}}`, `{{store_name}}`,
  `{{status}}`, `{{tracking_number}}` and `{{carrier}}`; a line with a tracking placeholder is left out when the
  shipment has no tracking number.
- Numbers from the shipping (else billing) address, completed with the country calling code; invalid, short-code,
  premium-rate and satellite numbers are skipped with the reason.
- Background sending: a MySQL message queue consumer (`smstunnel.message.send`) and the `smstunnel_send_pending` cron
  job; retries after 1, 5, 15 and 60 minutes; each text is queued only once.
- **Sales → SMSTunnel Texts** with status, reason, **Send Again** and **Delete**; nightly cleanup after 90 days
  (configurable); `var/log/smstunnel.log`.
- ACL resources, declarative schema, `module:uninstall --remove-data` support and `i18n/en_US.csv`.
