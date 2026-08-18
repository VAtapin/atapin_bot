#!/usr/bin/env bash
set -Eeuo pipefail

ENV_FILE="${SWPRO_HEALTH_ENV_FILE:-/etc/swpro-health/swpro-health.env}"
STATE_DIR="/var/lib/swpro-health"
STATE_FILE="$STATE_DIR/state.env"
LOCK_FILE="$STATE_DIR/health.lock"

mkdir -p "$STATE_DIR"
chmod 700 "$STATE_DIR"
[[ -r "$ENV_FILE" ]] && source "$ENV_FILE"

DOMAIN="${SWPRO_MONITOR_DOMAIN:-swpro.ru}"
URL="${SWPRO_MONITOR_URL:-https://$DOMAIN/}"
INTERVAL="${SWPRO_HEALTH_INTERVAL_SECONDS:-300}"
ALERT_COOLDOWN="${SWPRO_ALERT_COOLDOWN_SECONDS:-1800}"
SSL_WARN="${SWPRO_SSL_WARN_DAYS:-30}"
SSL_CRIT="${SWPRO_SSL_CRITICAL_DAYS:-7}"
DISK_WARN="${SWPRO_DISK_WARN_PERCENT:-80}"
DISK_CRIT="${SWPRO_DISK_CRITICAL_PERCENT:-90}"
INODE_WARN="${SWPRO_INODE_WARN_PERCENT:-80}"
INODE_CRIT="${SWPRO_INODE_CRITICAL_PERCENT:-90}"
RAM_WARN="${SWPRO_RAM_WARN_AVAILABLE_PERCENT:-20}"
RAM_CRIT="${SWPRO_RAM_CRITICAL_AVAILABLE_PERCENT:-10}"
SWAP_WARN="${SWPRO_SWAP_WARN_PERCENT:-25}"
SWAP_CRIT="${SWPRO_SWAP_CRITICAL_PERCENT:-50}"
BACKUP_WARN="${SWPRO_BACKUP_WARN_AGE_HOURS:-26}"
BACKUP_CRIT="${SWPRO_BACKUP_CRITICAL_AGE_HOURS:-50}"

exec 9>"$LOCK_FILE"
flock -n 9 || exit 0

log(){ logger -t swpro-health -- "$*"; }
state(){ local key="$1" value="$2"; printf '%s=%q\n' "$key" "$value"; }

TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT
{
  echo "timestamp=$(date -Is)"
  echo "hostname=$(hostname -f 2>/dev/null || hostname)"
  echo "uptime=$(uptime -p 2>/dev/null || true)"

  if command -v curl >/dev/null 2>&1; then
    start=$(date +%s%3N)
    http_code=$(curl -4 -L -sS -o /dev/null --connect-timeout 5 --max-time 15 -w '%{http_code}' "$URL" 2>/dev/null || echo 000)
    end=$(date +%s%3N)
    echo "website_http=$http_code"
    echo "website_ms=$((end-start))"
  else
    echo "website_http=000"
    echo "website_ms=-1"
  fi

  if getent ahostsv4 "$DOMAIN" >/dev/null 2>&1; then echo "dns=OK"; else echo "dns=CRITICAL"; fi

  if command -v openssl >/dev/null 2>&1; then
    expiry=$(echo | timeout 10 openssl s_client -connect "$DOMAIN:443" -servername "$DOMAIN" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2 || true)
    if [[ -n "$expiry" ]]; then
      expiry_epoch=$(date -d "$expiry" +%s 2>/dev/null || echo 0)
      days=$(( (expiry_epoch-$(date +%s))/86400 ))
      echo "ssl_days=$days"
      echo "ssl=$([[ $days -le $SSL_CRIT ]] && echo CRITICAL || ([[ $days -le $SSL_WARN ]] && echo WARN || echo OK))"
    else
      echo "ssl_days=-1"
      echo "ssl=CRITICAL"
    fi
  fi

  read -r _ load1 load5 load15 _ < /proc/loadavg || true
  echo "load1=${load1:-0}"
  echo "load5=${load5:-0}"
  echo "load15=${load15:-0}"

  read -r mem_total mem_used mem_free mem_shared mem_cache mem_available _ < <(free -m | awk '/^Mem:/ {print $2,$3,$4,$5,$6,$7,"MB"}')
  echo "ram_total_mb=${mem_total:-0}"
  echo "ram_available_mb=${mem_available:-0}"
  if [[ ${mem_total:-0} -gt 0 ]]; then echo "ram_available_percent=$((mem_available*100/mem_total))"; fi

  read -r swap_total swap_used swap_free _ < <(free -m | awk '/^Swap:/ {print $2,$3,$4,"MB"}')
  echo "swap_total_mb=${swap_total:-0}"
  echo "swap_used_mb=${swap_used:-0}"
  if [[ ${swap_total:-0} -gt 0 ]]; then echo "swap_used_percent=$((swap_used*100/swap_total))"; else echo "swap_used_percent=0"; fi

  df -P -x tmpfs -x devtmpfs | awk 'NR>1 {gsub("%","",$5); print "disk_"$6"_percent=" $5; print "disk_"$6"_available=" $4}' | sed 's#/#root#g;s#[^A-Za-z0-9_./=-]#_#g'
  df -Pi -x tmpfs -x devtmpfs | awk 'NR>1 {gsub("%","",$5); print "inode_"$6"_percent=" $5}' | sed 's#/#root#g;s#[^A-Za-z0-9_./=-]#_#g'

  for svc in nginx php8.3-fpm mariadb ssh fail2ban swpro-telegram-tunnel; do
    if systemctl is-active --quiet "$svc"; then echo "service_${svc}=active"; else echo "service_${svc}=inactive"; fi
  done

  if systemctl list-timers --all --no-legend 2>/dev/null | grep -q 'swpro-restic-backup.timer'; then echo "restic_timer=present"; else echo "restic_timer=missing"; fi
  if systemctl is-active --quiet swpro-restic-backup.timer; then echo "restic_timer_active=active"; else echo "restic_timer_active=inactive"; fi

  # Keep a small, non-sensitive journal sample for the /logs command.
  journalctl -p 0..3 -n 20 --no-pager -o short-iso 2>/dev/null | sed -E 's/(token|password|secret|api[_-]?key)=([^ ]+)/\1=[REDACTED]/gi' | tail -20 > "$STATE_DIR/critical.log" || true
} > "$TMP"

install -m 600 "$TMP" "$STATE_DIR/last.env"
log "health check completed for $DOMAIN"

# Recovery is intentionally opt-in and restricted to a fixed allowlist.
if [[ "${SWPRO_RECOVERY_ENABLED:-false}" == "true" ]]; then
  for svc in nginx php8.3-fpm mariadb swpro-telegram-tunnel; do
    if ! systemctl is-active --quiet "$svc"; then
      if systemctl restart "$svc"; then
        log "recovery: restarted $svc"
        echo "recovery_${svc}=$(date -Is)" >> "$STATE_DIR/last.env"
      fi
    fi
  done
fi
