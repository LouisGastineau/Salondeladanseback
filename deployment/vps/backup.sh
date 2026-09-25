#!/usr/bin/env bash
set -Eeuo pipefail
umask 0077
destination=/var/backups/salon-danse
mkdir -p "$destination"
stamp=$(date -u +%Y%m%dT%H%M%S%N)
mariadb-dump --defaults-extra-file=/etc/salon/backup.cnf --single-transaction --routines --triggers salon_danse | gzip > "$destination/$stamp.sql.gz.tmp"
mv "$destination/$stamp.sql.gz.tmp" "$destination/$stamp.sql.gz"
tar -czf "$destination/$stamp-storage.tar.gz" -C /var/www/back/shared storage .env
# Retain the most recent 14 days on this server.
find "$destination" -maxdepth 1 -type f -name '*.gz' -mtime +14 -delete
