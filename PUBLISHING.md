# Publishing SMSTunnel for Magento

Two places: **Packagist** (so `composer require smstunnel/smstunnel-magento` works for every store) and the
**Adobe Commerce Marketplace** (the listing merchants browse). Packagist comes first: it only takes a few minutes.

## Before each release

- The version lives in three places that must match: `"version"` in `composer.json`, `Client::VERSION` in
  `Model/Api/Client.php` (sent as the User-Agent) and the git tag (`v1.0.0`). Packagist ignores a tag whose version
  differs from the `version` field.
- Add the release to `CHANGELOG.md`.
- Run the checks (they need the Docker stack from `dev/docker`, see the README's Development section):
  `dev/check.sh smst-int-magento249` (Magento coding standard, PHPStan, unit tests) and the end-to-end test.

## 1. GitHub and Packagist

The `smstunnel` vendor on Packagist is already yours (`smstunnel/smstunnel-php`, `smstunnel/smstunnel-laravel`).

```bash
cd D:\ANDROID\integrari\smstunnel-magento
gh repo create narcisbodea/smstunnel-magento --public --source=. --remote=origin --push \
  --description "SMSTunnel for Magento: order texts from your own Android phone, from your own number"
git tag -a v1.0.0 -m "SMSTunnel for Magento 1.0.0"
git push origin v1.0.0
```

Then, signed in to packagist.org with GitHub: <https://packagist.org/packages/submit>, repository URL
`https://github.com/narcisbodea/smstunnel-magento`, **Check**, **Submit**. With a GitHub-linked account Packagist
watches the repository by itself, so later tags show up within a minute or two.

The package archive leaves out `dev/`, `PUBLISHING.md` and `site-page.md` (`.gitattributes` `export-ignore` for the
GitHub zip that Packagist serves, and `archive.exclude` in `composer.json` for `composer archive`).

## 2. Adobe Commerce Marketplace

### Account

1. Sign in with an Adobe ID at <https://commercemarketplace.adobe.com> and open the developer portal
   (<https://commercedeveloper.adobe.com>). Create the seller profile for NARBOWEB SRL (company name, logo, support
   email `contact@narboweb.ro`, website `https://smstunnel.io`).
2. The extension is free, so payout and tax details should not be needed (Adobe asks for them for paid extensions;
   check the current form when you get there).

### Package

```bash
composer archive --format=zip --dir=build --file=smstunnel-magento-1.0.0
```

This builds `build/smstunnel-magento-1.0.0.zip` (63 files, about 70 KB) with `composer.json`, `registration.php` and
the module at the root of the zip, as the Marketplace expects (the limit is 30 MB). In the local tests this exact zip
was installed into Magento 2.4.9 through a Composer artifact repository, compiled with `setup:di:compile` and passed
the end-to-end test. (Adobe's old `magento/marketplace-tools` validator repository is no longer on GitHub.)

What Adobe's technical guidelines ask, and where the package stands:

| Requirement | Status |
| --- | --- |
| `composer.json` with `name`, `type: magento2-module`, `version`; `autoload.files` with `registration.php` and a PSR-4 namespace | yes |
| No `*` versions for Magento packages; the PHP range does not cut out PHP versions the supported Magento versions allow | `~103.0.6` style constraints; PHP `~8.1.0` to `~8.5.0` |
| Valid `etc/module.xml` and `registration.php` | yes |
| No `TODO` / `FIXME` comments, no unhandled errors | yes |
| Adobe's coding standard | `magento/magento-coding-standard` v41: 0 errors; 7 warnings, all XML lines over 120 characters that hold a class name or a default text and cannot be wrapped |
| Dependencies | only Magento's own packages, no shared packages to link |

### Submission

In the developer portal: **Extensions → Add extension** (later versions: **Submit a new version**).

1. **Technical submission**
   - Attach the zip; compatibility: Magento Open Source and Adobe Commerce **2.4.6, 2.4.7, 2.4.8, 2.4.9**.
   - License: MIT.
   - **User Guide** (required, PDF under 5 MB): export the README's *What it sends*, *Installation*, *Setup*,
     *Placeholders*, *Sent texts* and *Troubleshooting* sections, with the screenshots from
     `D:\ANDROID\integrari\magento-screens\`. Adobe does not allow documentation to send users to buy elsewhere,
     so in the PDF say "create a free SMSTunnel account" and leave out plan prices and upgrade links.
   - Release notes (up to 10,000 characters, simple HTML): the 1.0.0 section of `CHANGELOG.md`.
   - Notes for the reviewer: give a key from a dedicated SMSTunnel test account (**Apps → Add an app**), never a
     personal one. With no phone online, SMSTunnel still accepts each text: the reviewer sees it under
     **Sales → SMSTunnel Texts** as **Sent to your phone** with the note "Your phone was offline: it sends the text as
     soon as it is back online", and **Send a Test Text** answers the same way. Delete that key after the review.
   - Submit, then **Confirm**. Adobe runs automated checks (malware, plagiarism, coding standard) and installs the
     package on the versions you picked; results appear under **Test Reports**, with an email.
2. **Marketing submission**
   - Title with our brand first and without "Magento" or "Adobe" in it, e.g. **SMSTunnel Order Texts**
     (Adobe's listing rules restrict their trademarks in titles; check the current marketing guidelines).
   - Short and long description: reuse `site-page.md`; keep the wording rules: "your own phone", "your own number",
     one text per recipient; never "gateway", "bulk", "campaign", "marketing" or "mass".
   - Images: the screenshots in `D:\ANDROID\integrari\magento-screens\` (settings, test text, sent-texts list).
   - Price: **Free**. Support: `contact@narboweb.ro`, documentation link to the GitHub README or the smstunnel.io page.

### Review times

Adobe does not publish fixed times. In practice the automated technical review answers within a few business days,
the marketing review within about two weeks, and a first listing usually goes live two to four weeks after
submission, longer if a check fails and you resubmit. A new version goes through the technical review again; the
marketing review only when the listing changes.

## 3. Mage-OS

Nothing extra: Mage-OS installs extensions from Packagist like Magento. Its packages declare `replace` for the
matching `magento/*` versions (Mage-OS 4.0.0 replaces `magento/framework` 103.0.9, the 2.4.9 release), so the
module's Composer constraints accept them. The tests ran on Magento Open Source 2.4.6-p15 and 2.4.9, not on a Mage-OS
build.
