#!/bin/sh
set -eu

data_dir=/var/www/html/data

install -d -o www-data -g www-data -m 0770 \
    "$data_dir" \
    "$data_dir/system" \
    "$data_dir/temp"

# Existing NAS folders sometimes arrive with ownership that Apache cannot use.
# Enable this once when importing such a folder; it is intentionally optional
# because recursively walking a multi-terabyte library on every start is costly.
if [ "${AI_DRIVE_FIX_PERMISSIONS:-0}" = "1" ]; then
    chown -R www-data:www-data "$data_dir"
    chmod -R u+rwX,g+rwX,o-rwx "$data_dir"
fi

rm -f /var/run/apache2/apache2.pid
. /etc/apache2/envvars
exec "$@"
