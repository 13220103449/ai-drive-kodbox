#!/bin/sh
set -eu

data_dir=/var/www/html/data
setting_file=/var/www/html/config/setting_user.php
setting_backup="$data_dir/system/setting_user.php"

# Debian's Apache package creates /var/www/html before the application is
# copied into the image. COPY --chown updates the copied children, but not
# that pre-existing directory itself. KodBox checks BASIC_PATH explicitly
# during installation, so ensure Apache can write the application root too.
chown www-data:www-data /var/www/html
chmod 0755 /var/www/html

install -d -o www-data -g www-data -m 0770 \
    "$data_dir" \
    "$data_dir/system" \
    "$data_dir/temp"

# KodBox stores its database connection in config/setting_user.php, outside
# the mounted data directory. Preserve that small file inside data/system so
# replacing the image cannot send an installed server back to the installer.
setting_is_valid() {
    [ -f "$1" ] && grep -q "DB_TYPE" "$1"
}

if setting_is_valid "$setting_backup" && ! setting_is_valid "$setting_file"; then
    cp "$setting_backup" "$setting_file"
    chown www-data:www-data "$setting_file"
    chmod 0660 "$setting_file"
elif setting_is_valid "$setting_file" && ! setting_is_valid "$setting_backup"; then
    cp "$setting_file" "$setting_backup"
    chown www-data:www-data "$setting_backup"
    chmod 0660 "$setting_backup"
fi

# On a fresh web installation the file is created after Apache starts. Keep a
# short background watcher until installation finishes, then persist it.
if ! setting_is_valid "$setting_backup"; then
    (
        attempts=0
        while [ "$attempts" -lt 720 ]; do
            if setting_is_valid "$setting_file"; then
                cp "$setting_file" "$setting_backup"
                chown www-data:www-data "$setting_backup"
                chmod 0660 "$setting_backup"
                exit 0
            fi
            attempts=$((attempts + 1))
            sleep 5
        done
    ) &
fi

# Existing NAS folders sometimes arrive with ownership that Apache cannot use.
# Enable this once when importing such a folder; it is intentionally optional
# because recursively walking a multi-terabyte library on every start is costly.
if [ "${AI_DRIVE_FIX_PERMISSIONS:-0}" = "1" ]; then
    chown -R www-data:www-data "$data_dir"
    chmod -R u+rwX,g+rwX,o-rwx "$data_dir"
fi

rm -f /var/run/apache2/apache2.pid
export APACHE_CONFDIR=/etc/apache2
. /etc/apache2/envvars
exec "$@"
