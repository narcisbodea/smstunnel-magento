#!/usr/bin/env bash
# Dev only: copies the module into a test stack, then runs the Magento coding standard, PHPStan and the unit tests.
#   dev/check.sh smst-int-magento249
set -uo pipefail

PHP_CONTAINER="${1:-smst-int-magento249}-php-1"
run() { docker exec -u www-data "$PHP_CONTAINER" sh -c "cd /var/www/html && $1"; }
status=0

run 'composer reinstall smstunnel/smstunnel-magento --no-interaction >/dev/null 2>&1 && php bin/magento cache:flush >/dev/null'
docker restart "$PHP_CONTAINER" >/dev/null # PHP-FPM keeps old file paths in its realpath cache

echo "== Magento coding standard (phpcs --standard=Magento2)"
run 'php vendor/bin/phpcs --standard=Magento2 --extensions=php,phtml,xml --severity=1 --report=summary \
  vendor/smstunnel/smstunnel-magento 2>/dev/null | grep -v "^DEPRECATED\|^The Magento2\|sniff is listening"; \
  php vendor/bin/phpcs --standard=Magento2 --extensions=php,phtml,xml --error-severity=1 --warning-severity=0 -q \
  vendor/smstunnel/smstunnel-magento >/dev/null 2>&1 && echo "phpcs: no errors"' || status=1

echo "== PHPStan (bitexpert/phpstan-magento)"
run 'mkdir -p /tmp/phpstan-tools && cp /module/dev/phpstan/composer.json /module/dev/phpstan/phpstan.neon /tmp/phpstan-tools/ \
  && cd /tmp/phpstan-tools && (test -d vendor || composer install --no-interaction --no-progress >/dev/null 2>&1) \
  && php vendor/bin/phpstan analyse --memory-limit=2G --no-progress' || status=1

echo "== Unit tests"
run 'cd dev/tests/unit && php ../../../vendor/bin/phpunit --do-not-cache-result ../../../vendor/smstunnel/smstunnel-magento/Test/Unit \
  | tail -4' || status=1

exit $status
