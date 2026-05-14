#!/bin/sh
set -e

APP_DIR="/app"
CREDENTIALS_RELATIVE_PATH="${FIREBASE_CREDENTIALS_PATH:-storage/firebase-credentials.json}"

case "$CREDENTIALS_RELATIVE_PATH" in
  /*) CREDENTIALS_PATH="$CREDENTIALS_RELATIVE_PATH" ;;
  *) CREDENTIALS_PATH="$APP_DIR/$CREDENTIALS_RELATIVE_PATH" ;;
esac

if [ -n "$FIREBASE_CREDENTIALS_JSON" ]; then
  mkdir -p "$(dirname "$CREDENTIALS_PATH")"
  printf "%s" "$FIREBASE_CREDENTIALS_JSON" > "$CREDENTIALS_PATH"
  chmod 600 "$CREDENTIALS_PATH"
fi

cd "$APP_DIR"
php artisan config:clear >/dev/null 2>&1 || true
php artisan cache:clear >/dev/null 2>&1 || true

exec "$@"
