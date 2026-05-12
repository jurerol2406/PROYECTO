#!/bin/bash
set -e

mkdir -p /var/lib/smarthome
touch /var/lib/smarthome/smarthome.db
chown -R www-data:www-data /var/lib/smarthome
chmod 775 /var/lib/smarthome
chmod 664 /var/lib/smarthome/smarthome.db

exec apache2-foreground
