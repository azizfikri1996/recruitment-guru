#!/bin/zsh
# ──────────────────────────────────────────────
#  START PRODUKSI – Sistem Penerimaan Guru Baru
# ──────────────────────────────────────────────
APP_DIR="/Users/manager_it/Documents/My Alazka/Recruitment Karyawan"
GUNICORN_VENV="$APP_DIR/.venv/bin/gunicorn"
GUNICORN_USER="/Users/manager_it/Library/Python/3.9/bin/gunicorn"
WORKERS=2
PORT=8000
HOST="0.0.0.0"
LOGDIR="$APP_DIR/logs"
PIDFILE="$APP_DIR/gunicorn.pid"

mkdir -p "$LOGDIR"

if [ -x "$GUNICORN_VENV" ]; then
  GUNICORN="$GUNICORN_VENV"
elif [ -x "$GUNICORN_USER" ]; then
  GUNICORN="$GUNICORN_USER"
else
  echo "Gunicorn tidak ditemukan di venv maupun user install."
  echo "Install salah satu: pip install gunicorn"
  exit 1
fi

if [ -f "$APP_DIR/.env" ]; then
  set -a
  source "$APP_DIR/.env"
  set +a
fi

if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
  echo "Server sudah berjalan (PID: $(cat "$PIDFILE"))."
  echo "Gunakan stop.sh jika ingin restart."
  exit 0
fi

LAN_IP="$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || echo 127.0.0.1)"

echo "──────────────────────────────────────────"
echo " Recruitment Guru – Mode Produksi"
echo " Alamat: http://$LAN_IP:$PORT"
echo "──────────────────────────────────────────"

cd "$APP_DIR"
"$GUNICORN" app:app \
  --bind $HOST:$PORT \
  --workers $WORKERS \
  --daemon \
  --pid "$PIDFILE" \
  --access-logfile "$LOGDIR/access.log" \
  --error-logfile  "$LOGDIR/error.log"  \
  --log-level info

echo "Deploy selesai."
