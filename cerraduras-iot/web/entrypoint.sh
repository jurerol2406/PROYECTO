#!/bin/bash
set -e

mkdir -p /var/lib/smarthome
chown -R www-data:www-data /var/lib/smarthome
chmod 775 /var/lib/smarthome

[ -f /var/lib/smarthome/smarthome.db ] \
    && chown www-data:www-data /var/lib/smarthome/smarthome.db \
    && chmod 664 /var/lib/smarthome/smarthome.db

exec apache2-foreground
