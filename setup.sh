#!/usr/bin/env bash
# Interactive setup for emailAI (Ubuntu). Creates .env and installs a 20-minute cron job.
set -euo pipefail
cd "$(dirname "$0")"
DIR="$(pwd)"

if ! command -v php >/dev/null; then
  echo "PHP CLI not found. Installing (needs sudo)..."
  sudo apt-get update && sudo apt-get install -y php-cli php-curl php-mbstring
fi
for ext in curl mbstring openssl; do
  php -m | grep -qi "^$ext$" || { echo "Missing PHP extension: $ext (apt install php-$ext)"; exit 1; }
done

if [ -f .env ]; then
  read -rp ".env already exists. Overwrite? [y/N] " a
  [[ "$a" =~ ^[Yy]$ ]] || { echo "Keeping existing .env"; SKIP_ENV=1; }
fi

ask() { # ask VAR "prompt" "default" [secret]
  local var=$1 prompt=$2 def=${3:-} secret=${4:-} val
  while true; do
    if [ -n "$secret" ]; then read -rsp "$prompt: " val; echo; else read -rp "$prompt${def:+ [$def]}: " val; fi
    val=${val:-$def}
    if [[ "$val" == *$'\n'* || ( "$val" == *"'"* && "$val" == *'"'* ) ]]; then
      echo "Value may not contain both ' and \" characters."; continue
    fi
    break
  done
  printf -v "$var" '%s' "$val"
}
q() { if [[ "$1" == *"'"* ]]; then printf '"%s"' "$1"; else printf "'%s'" "$1"; fi; }

if [ -z "${SKIP_ENV:-}" ]; then
  ask KEY "OpenAI API key" "" secret
  ask MODEL "OpenAI model" "gpt-4o-mini"
  ask TZN "Timezone" "UTC"
  ask INSTR "Extra agent instructions (tone, language, signature)" "Write concise, polite replies in the language of the incoming e-mail."
  ask CAL "Unified calendar file" "data/calendar.ics"
  ids=(); out=""
  while true; do
    ask ID "Account id (letters/digits, e.g. WORK)" "ACCOUNT$((${#ids[@]}+1))"
    ID=$(echo "$ID" | tr -cd 'A-Za-z0-9' | tr a-z A-Z); ids+=("$ID")
    ask WEB "  Webmail login address (URL)" ""
    ask EMAIL "  E-mail address" ""
    ask PASS "  Password" "" secret
    ask PROTO "  Protocol (imap/pop3)" "imap"
    ask HOST "  Server host" "${PROTO}.${EMAIL#*@}"
    enc="ssl"; ask ENC "  Encryption (ssl/starttls/none)" "$enc"
    defport=993; [ "$PROTO" = pop3 ] && defport=995
    [ "$ENC" != ssl ] && { defport=143; [ "$PROTO" = pop3 ] && defport=110; }
    ask PORT "  Port" "$defport"
    ask DRAFTS "  Drafts folder (IMAP only)" "Drafts"
    out+="ACCOUNT_${ID}_WEBLOGIN=$(q "$WEB")"$'\n'"ACCOUNT_${ID}_EMAIL=$(q "$EMAIL")"$'\n'"ACCOUNT_${ID}_PASSWORD=$(q "$PASS")"$'\n'
    out+="ACCOUNT_${ID}_PROTOCOL=$PROTO"$'\n'"ACCOUNT_${ID}_HOST=$HOST"$'\n'"ACCOUNT_${ID}_PORT=$PORT"$'\n'"ACCOUNT_${ID}_ENCRYPTION=$ENC"$'\n'"ACCOUNT_${ID}_DRAFTS_FOLDER=$(q "$DRAFTS")"$'\n'
    read -rp "Add another account? [y/N] " more
    [[ "$more" =~ ^[Yy]$ ]] || break
  done
  umask 077
  {
    echo "OPENAI_API_KEY=$(q "$KEY")"
    echo "OPENAI_MODEL=$MODEL"
    echo "OPENAI_BASE_URL=https://api.openai.com/v1"
    echo "AGENT_INSTRUCTIONS=$(q "$INSTR")"
    echo "TIMEZONE=$TZN"
    echo "CALENDAR_FILE=$CAL"
    echo "ACCOUNTS=$(IFS=,; echo "${ids[*]}")"
    printf '%s' "$out"
  } > .env
  chmod 600 .env
  echo ".env written."
fi

mkdir -p data
php tests/run.php

read -rp "Install cron job (every 20 minutes)? [Y/n] " c
if [[ ! "$c" =~ ^[Nn]$ ]]; then
  PHP_BIN="$(command -v php)"
  LINE="*/20 * * * * $PHP_BIN $DIR/bin/run.php >> $DIR/data/cron.log 2>&1"
  ( crontab -l 2>/dev/null | grep -vF "$DIR/bin/run.php" || true; echo "$LINE" ) | crontab -
  echo "Cron installed: $LINE"
fi
read -rp "Run once now? [y/N] " r
[[ "$r" =~ ^[Yy]$ ]] && php bin/run.php
echo "Done. Calendar: $DIR/data/calendar.ics"
