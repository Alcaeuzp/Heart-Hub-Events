#!/bin/sh
set -eu
cp -a /usr/src/wordpress/. /var/www/html/
wp config create --dbname=hherm_test --dbuser=hherm_test --dbpass=hherm_disposable_test_password --dbhost=db --skip-check --extra-php <<'PHP'
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', '/results/wordpress-debug.log');
define('WP_DEBUG_DISPLAY', false);
PHP
wp core install --url=http://hherm.test --title='Heart Hub disposable validation' --admin_user=hherm_test_admin --admin_password=hherm_disposable_admin_password --admin_email=admin@example.test --skip-email
mkdir -p wp-content/mu-plugins
cat > wp-content/mu-plugins/hherm-test-safety.php <<'PHP'
<?php
// Container-only mail interception. The internal Docker network also blocks egress.
add_filter('pre_wp_mail', static function () { return true; });
PHP
ln -s /plugin wp-content/plugins/heart-hub-event-registration-manager
wp plugin activate heart-hub-event-registration-manager
wp option update timezone_string Australia/Sydney
touch /tmp/hherm-wordpress-ready
exec "$@"
