#!/bin/sh
set -eu

mkdir -p \
  /app/var/cache \
  /app/var/log \
  /app/public/uploads/communication_files \
  /app/public/uploads/logos \
  /app/public/uploads/evidences \
  /app/public/build

if [ -d /opt/begreen-build ]; then
  find /app/public/build -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null || true
  cp -a /opt/begreen-build/. /app/public/build/
fi

if [ "$(id -u)" = "0" ]; then
  chown -R www-data:www-data /app/var /app/public/uploads || true
fi

exec "$@"
