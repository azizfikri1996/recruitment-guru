#!/bin/zsh
# Hentikan proses gunicorn
APP_DIR="/Users/manager_it/Documents/My Alazka/Recruitment Karyawan"
PIDFILE="$APP_DIR/gunicorn.pid"

if [ -f "$PIDFILE" ]; then
  PID=$(cat "$PIDFILE")
  if kill -0 "$PID" 2>/dev/null; then
    kill "$PID" && rm -f "$PIDFILE"
    echo "Server dihentikan."
  else
    rm -f "$PIDFILE"
    echo "PID lama ditemukan dan sudah dibersihkan."
  fi
else
  echo "Server tidak sedang berjalan (PID file tidak ada)."
fi
