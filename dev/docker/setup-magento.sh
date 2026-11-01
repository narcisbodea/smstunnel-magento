#!/usr/bin/env bash
# Dev only: builds and installs a Magento test stack for this module.
#   dev/docker/setup-magento.sh 249   (Magento 2.4.9, PHP 8.5)
#   dev/docker/setup-magento.sh 246   (Magento 2.4.6-p15, PHP 8.1)
# Magento comes from the Mage-OS mirror of the Magento Open Source packages (no Adobe keys needed).
set -euo pipefail

STACK_ID="$1"
cd "$(dirname "$0")"
# shellcheck disable=SC1090
source "./${STACK_ID}.vars"
PROJECT="smst-int-magento${STACK_ID}"
PHP_CONTAINER="${PROJECT}-php-1"
compose() { docker compose -p "$PROJECT" --env-file "${STACK_ID}.vars" "$@"; }
as_www() { docker exec -u www-data "$PHP_CONTAINER" sh -c "cd /var/www/html && $1"; }

compose build php
compose up -d db search php

if ! as_www 'test -f bin/magento'; then
  docker exec "$PHP_CONTAINER" sh -c 'rm -rf /var/www/html/* /var/www/html/.[!.]* 2>/dev/null; chown -R www-data:www-data /var/www/html'
  as_www "composer create-project --repository-url=https://mirror.mage-os.org/ magento/project-community-edition:${MAGENTO_VERSION} /var/www/html --no-interaction --no-progress --no-install"
  if [ -n "${ALLOW_ADVISORIES:-}" ]; then
    # Older Magento releases pin packages that Composer 2.10+ refuses because of security advisories.
    as_www 'composer config policy.advisories.block false'
  fi
  as_www 'composer install --no-interaction --no-progress'
fi

until docker exec "$PHP_CONTAINER" sh -c 'curl -fs search:9200 >/dev/null'; do sleep 2; done

as_www "php -d memory_limit=-1 bin/magento setup:install \
  --base-url=http://127.0.0.1:${WEB_PORT}/ --db-host=db --db-name=magento --db-user=magento --db-password=magento \
  --admin-firstname=Store --admin-lastname=Owner --admin-email=owner@example.com --admin-user=admin \
  --admin-password=SmsTunnel123! --language=en_US --currency=USD --timezone=UTC --use-rewrites=1 \
  --backend-frontname=admin --search-engine=opensearch --opensearch-host=search --opensearch-port=9200 \
  --opensearch-index-prefix=m${STACK_ID} --disable-modules=Magento_TwoFactorAuth${EXTRA_DISABLE:-} \
  --cleanup-database --no-interaction"

# The module, copied from the mounted folder through a Composer path repository.
as_www 'composer config repositories.smstunnel "{\"type\": \"path\", \"url\": \"/module\", \"options\": {\"symlink\": false}}"'
as_www 'composer require smstunnel/smstunnel-magento:1.0.0 --no-interaction --no-progress'
as_www 'php bin/magento module:enable SmsTunnel_SmsNotifications && php bin/magento setup:upgrade'
as_www 'php bin/magento deploy:mode:set developer'
as_www 'php bin/magento config:set admin/security/use_form_key 0'
compose up -d web
echo "READY ${PROJECT} http://127.0.0.1:${WEB_PORT}/"
