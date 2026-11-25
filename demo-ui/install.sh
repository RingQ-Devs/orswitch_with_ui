#!/usr/bin/env bash
# OpenRings demo UI installer for Debian / Ubuntu.
#
#   sudo bash install.sh [--no-nginx] [--no-cert]
#
# Asks for: the host name, the switch address and administrator login, the
# demo's own administrator user name and password, and the certificate.
# Then it installs nginx + PHP-FPM, copies the demo to /var/www/openrings-demo,
# writes its settings, creates the administrator and serves it over HTTPS.
# Safe to run again: it keeps what exists unless you say otherwise.
#
# Every question can also be answered up front with an environment variable
# (for scripts): OR_FQDN, OR_DOMAIN, OR_SWITCH_URL, OR_SWITCH_USER,
# OR_SWITCH_PASS, OR_SWITCH_VERIFY (1/0), OR_ADMIN_USER, OR_ADMIN_PASS,
# OR_CERT (letsencrypt|selfsigned), OR_EMAIL, OR_YES=1 (accept the defaults).
# Passwords are never put on a command line or printed (except a generated one, once).
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
APP="${OR_APP_DIR:-/var/www/openrings-demo}"
DATA="${OR_DATA_DIR:-/var/lib/openrings-demo}"
NO_NGINX=0
NO_CERT=0
GENERATED_PW=""

die()  { echo "error: $*" >&2; exit 1; }
step() { echo; echo "==> $*"; }
note() { echo "    $*"; }

for a in "$@"; do
  case "$a" in
    --no-nginx) NO_NGINX=1; NO_CERT=1 ;;
    --no-cert)  NO_CERT=1 ;;
    -h|--help)  sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "unknown option: $a (try --help)" ;;
  esac
done

[ "$(id -u)" -eq 0 ] || die "run as root: sudo bash $0"
command -v apt-get >/dev/null || die "this installer needs Debian or Ubuntu (apt)."
[ -f "$SRC/lib.php" ] || die "run it from the demo-ui folder (lib.php not found next to it)."

# ---- questions -------------------------------------------------------------------------------
# ask VAR "Question" "default"       (an exported OR_ variable of the same name answers it)
ask() {
  local var="$1" q="$2" def="${3:-}" ans
  if [ -n "${!var:-}" ]; then return; fi
  if [ "${OR_YES:-0}" = 1 ] && [ -n "$def" ]; then printf -v "$var" '%s' "$def"; return; fi
  if [ -n "$def" ]; then read -r -p "$q [$def]: " ans; else read -r -p "$q: " ans; fi
  printf -v "$var" '%s' "${ans:-$def}"
}
# ask_secret VAR "Question"   (hidden input; may stay empty)
ask_secret() {
  local var="$1" q="$2" ans
  if [ -n "${!var:-}" ]; then return; fi
  [ "${OR_YES:-0}" = 1 ] && return 0
  read -r -s -p "$q: " ans; echo
  printf -v "$var" '%s' "$ans"
}
yesno() { # yesno "question" default(y|n)
  local q="$1" def="$2" ans
  if [ "${OR_YES:-0}" = 1 ]; then [ "$def" = y ]; return; fi
  read -r -p "$q [$([ "$def" = y ] && echo Y/n || echo y/N)]: " ans
  ans="${ans:-$def}"; [[ "$ans" =~ ^[Yy] ]]
}
pw_problem() {
  [ "${#1}" -ge 10 ] || { echo "at least 10 characters"; return; }
  [[ "$1" =~ [A-Za-z] && "$1" =~ [0-9] ]] || echo "letters and at least one digit"
}

echo "OpenRings demo UI installer"
echo "---------------------------"
OR_FQDN="${OR_FQDN:-}"
ask OR_FQDN "Host name of this server (DNS must already point here)" "$(hostname -f 2>/dev/null || hostname)"
[[ "$OR_FQDN" =~ ^[A-Za-z0-9.-]+$ ]] || die "the host name may contain only letters, digits, dots and dashes."

OR_SWITCH_URL="${OR_SWITCH_URL:-}"
ask OR_SWITCH_URL "ORswitch web address" "https://$OR_FQDN:8443"
OR_SWITCH_URL="${OR_SWITCH_URL%/}"
[[ "$OR_SWITCH_URL" =~ ^https?://[A-Za-z0-9.-]+(:[0-9]+)?$ ]] || die "the switch address must look like https://host:8443"

OR_DOMAIN="${OR_DOMAIN:-}"
ask OR_DOMAIN "Company (domain) in the switch that the demo manages" "$OR_FQDN"

CFGFILE="$APP/config.local.php"
OLD_SWITCH_PASS=""
if [ -f "$CFGFILE" ] && command -v php >/dev/null; then
  OLD_SWITCH_PASS="$(CFGFILE="$CFGFILE" php -r '$c = @include getenv("CFGFILE"); echo is_array($c) ? ($c["admin_pass"] ?? "") : "";' 2>/dev/null || true)"
fi
OR_SWITCH_USER="${OR_SWITCH_USER:-}"
ask OR_SWITCH_USER "ORswitch administrator user name" "admin"
OR_SWITCH_PASS="${OR_SWITCH_PASS:-}"
if [ -z "$OR_SWITCH_PASS" ] && [ -n "$OLD_SWITCH_PASS" ]; then
  note "The switch password saved by an earlier install is kept (type a new one to replace it)."
fi
ask_secret OR_SWITCH_PASS "ORswitch administrator password (hidden)${OLD_SWITCH_PASS:+, Enter keeps the saved one}"
[ -n "$OR_SWITCH_PASS" ] || OR_SWITCH_PASS="$OLD_SWITCH_PASS"

if [ -z "${OR_SWITCH_VERIFY:-}" ]; then
  if yesno "Verify the switch's TLS certificate? (say no for a self-signed switch)" y; then OR_SWITCH_VERIFY=1; else OR_SWITCH_VERIFY=0; fi
fi

# The demo's own administrator.
KEEP_USERS=0
if [ -s "$DATA/users.json" ] && [ "$(tr -d ' \n\t' < "$DATA/users.json")" != "[]" ]; then
  echo
  echo "The demo already has an administrator."
  if yesno "Keep the existing demo administrator(s)?" y; then KEEP_USERS=1; fi
fi
if [ "$KEEP_USERS" = 0 ]; then
  echo
  echo "Demo UI administrator (the login for the website):"
  OR_ADMIN_USER="${OR_ADMIN_USER:-}"
  ask OR_ADMIN_USER "  User name" "admin"
  [[ "$OR_ADMIN_USER" =~ ^[A-Za-z0-9._-]{3,32}$ ]] || die "user name: 3-32 letters, digits, . _ -"
  OR_ADMIN_PASS="${OR_ADMIN_PASS:-}"
  if [ -z "$OR_ADMIN_PASS" ] && [ "${OR_YES:-0}" != 1 ]; then
    while true; do
      read -r -s -p "  Password (hidden, 10+ characters with letters and a digit; Enter = generate one): " OR_ADMIN_PASS; echo
      [ -z "$OR_ADMIN_PASS" ] && break
      if p="$(pw_problem "$OR_ADMIN_PASS")" && [ -n "$p" ]; then echo "  Not strong enough: $p."; OR_ADMIN_PASS=""; continue; fi
      read -r -s -p "  Repeat the password: " again; echo
      [ "$again" = "$OR_ADMIN_PASS" ] && break
      echo "  The two passwords differ, try again."; OR_ADMIN_PASS=""
    done
  fi
  if [ -z "$OR_ADMIN_PASS" ]; then
    OR_ADMIN_PASS="$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16)x7"
    GENERATED_PW="$OR_ADMIN_PASS"
  fi
  p="$(pw_problem "$OR_ADMIN_PASS")"; [ -z "$p" ] || die "the demo administrator password needs $p."
fi

OR_CERT="${OR_CERT:-}"
if [ "$NO_CERT" = 0 ]; then
  if [ -f "/etc/letsencrypt/live/$OR_FQDN/fullchain.pem" ]; then
    OR_CERT=existing
    note "Using the Let's Encrypt certificate that already exists for $OR_FQDN."
  else
    echo
    echo "Certificate for https://$OR_FQDN/ :"
    echo "  1) Let's Encrypt (free, trusted; needs DNS pointing here and port 80 open for the check)"
    echo "  2) Self-signed (browsers warn once; fine for a quick test)"
    if [ -z "$OR_CERT" ]; then
      if [ "${OR_YES:-0}" = 1 ]; then OR_CERT=letsencrypt; else
        read -r -p "Choose [1]: " c; [ "${c:-1}" = 2 ] && OR_CERT=selfsigned || OR_CERT=letsencrypt
      fi
    fi
    if [ "$OR_CERT" = letsencrypt ]; then
      OR_EMAIL="${OR_EMAIL:-}"
      if [ -z "$OR_EMAIL" ] && [ "${OR_YES:-0}" != 1 ]; then
        read -r -p "Email for Let's Encrypt expiry notices (optional, Enter to skip): " OR_EMAIL
      fi
      note "Continuing accepts the Let's Encrypt subscriber agreement."
    fi
  fi
fi

# ---- packages --------------------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
step "Installing packages"
apt-get update -qq
PKGS="php-cli php-curl curl openssl ca-certificates"
[ "$NO_NGINX" = 1 ] || PKGS="$PKGS nginx php-fpm"
[ "$OR_CERT" = letsencrypt ] && PKGS="$PKGS certbot"
# shellcheck disable=SC2086
apt-get install -y -qq $PKGS >/dev/null
note "installed: $PKGS"

# ---- certificate (before nginx exists, so that port 80 is free for the check) ------------------
CERT=""; KEY=""
if [ "$NO_CERT" = 0 ]; then
  case "$OR_CERT" in
    existing)
      CERT="/etc/letsencrypt/live/$OR_FQDN/fullchain.pem"; KEY="/etc/letsencrypt/live/$OR_FQDN/privkey.pem" ;;
    letsencrypt)
      step "Requesting a Let's Encrypt certificate for $OR_FQDN"
      if ss -ltnH 'sport = :80' 2>/dev/null | grep -q .; then
        die "port 80 is in use, Let's Encrypt cannot check the host name. Stop what uses it (ss -ltnp 'sport = :80') and run again, or choose the self-signed certificate."
      fi
      if [ -n "${OR_EMAIL:-}" ]; then MAILARG="--email $OR_EMAIL"; else MAILARG="--register-unsafely-without-email"; fi
      # shellcheck disable=SC2086
      certbot certonly --standalone -d "$OR_FQDN" --non-interactive --agree-tos $MAILARG \
        || die "Let's Encrypt refused. Check that $OR_FQDN points to this server and that port 80 is open in every firewall."
      CERT="/etc/letsencrypt/live/$OR_FQDN/fullchain.pem"; KEY="/etc/letsencrypt/live/$OR_FQDN/privkey.pem" ;;
    selfsigned)
      step "Creating a self-signed certificate"
      install -d -m 0755 /etc/openrings-demo/tls
      openssl req -x509 -newkey rsa:2048 -nodes -days 825 -subj "/CN=$OR_FQDN" \
        -addext "subjectAltName=DNS:$OR_FQDN" \
        -keyout /etc/openrings-demo/tls/privkey.pem -out /etc/openrings-demo/tls/fullchain.pem 2>/dev/null
      chmod 600 /etc/openrings-demo/tls/privkey.pem
      CERT=/etc/openrings-demo/tls/fullchain.pem; KEY=/etc/openrings-demo/tls/privkey.pem ;;
    *) die "OR_CERT must be letsencrypt or selfsigned." ;;
  esac
fi

# ---- files ------------------------------------------------------------------------------------
step "Copying the demo UI to $APP"
mkdir -p "$APP" "$DATA"
# Everything except the installer, the Apache snippet, local settings and runtime data.
tar -C "$SRC" --exclude=./install.sh --exclude=./apache-proxy.conf --exclude=./config.local.php --exclude=./data -cf - . | tar -C "$APP" -xf -
chown -R root:root "$APP"
find "$APP" -type d -exec chmod 755 {} +
find "$APP" -type f -exec chmod 644 {} +
chown www-data:www-data "$DATA"; chmod 700 "$DATA"

step "Writing the settings ($CFGFILE)"
if [ -f "$CFGFILE" ]; then
  # Backups go next to the data (never into the web root, where a file could be downloaded).
  BAK="$DATA/config.local.php.bak.$(date +%Y%m%d-%H%M%S)"
  cp -p "$CFGFILE" "$BAK" && chmod 600 "$BAK" && note "previous settings saved as $BAK"
fi
SW_URL="$OR_SWITCH_URL" SW_USER="$OR_SWITCH_USER" SW_PASS="$OR_SWITCH_PASS" SW_VERIFY="$OR_SWITCH_VERIFY" \
DOM="$OR_DOMAIN" DATADIR="$DATA" CFGFILE="$CFGFILE" php -r '
$c = [
  "switch_url" => getenv("SW_URL"), "admin_user" => getenv("SW_USER"), "admin_pass" => getenv("SW_PASS"),
  "domain" => getenv("DOM"), "verify_tls" => getenv("SW_VERIFY") === "1", "ws_path" => "/orws",
  "data_dir" => getenv("DATADIR"), "mock" => false,
];
file_put_contents(getenv("CFGFILE"), "<?php\n// Written by install.sh. Holds the switch administrator password: keep it private.\nreturn " . var_export($c, true) . ";\n");'
chown root:www-data "$CFGFILE"; chmod 640 "$CFGFILE"

# ---- does the switch accept the login? (one try only: the switch locks out after 10 failures) ---------
step "Checking the switch login"
if [ -z "$OR_SWITCH_PASS" ]; then
  note "No switch password given: the demo pages cannot reach the switch until you set it in $CFGFILE (admin_pass)."
else
  CODE="$(SW_URL="$OR_SWITCH_URL" SW_USER="$OR_SWITCH_USER" SW_PASS="$OR_SWITCH_PASS" SW_VERIFY="$OR_SWITCH_VERIFY" php -r '
    $v = getenv("SW_VERIFY") === "1";
    $ch = curl_init(getenv("SW_URL") . "/api/login");
    curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 10,
      CURLOPT_HTTPHEADER => ["Content-Type: application/json", "X-RQ: 1"],
      CURLOPT_POSTFIELDS => json_encode(["user" => getenv("SW_USER"), "password" => getenv("SW_PASS")]),
      CURLOPT_SSL_VERIFYPEER => $v, CURLOPT_SSL_VERIFYHOST => $v ? 2 : 0]);
    curl_exec($ch); echo curl_errno($ch) ? "ERR:" . curl_error($ch) : curl_getinfo($ch, CURLINFO_HTTP_CODE);' 2>/dev/null || echo "ERR")"
  if [ "$CODE" = 200 ]; then
    note "the switch accepted the administrator login."
  else
    echo "WARNING: the switch login did not work (answer: $CODE)." >&2
    echo "         Check the address, user name, password and the TLS verification choice in $CFGFILE." >&2
    echo "         (The demo still installs; fix the settings and reload the page.)" >&2
  fi
fi

# ---- the demo administrator ---------------------------------------------------------------------
if [ "$KEEP_USERS" = 0 ]; then
  step "Creating the demo administrator '$OR_ADMIN_USER'"
  rm -f "$DATA/users.json" "$DATA/failures.json"
  DATADIR="$DATA" APPDIR="$APP" AUSER="$OR_ADMIN_USER" APW="$OR_ADMIN_PASS" \
    runuser -u www-data -- php -r '$CFG = ["data_dir" => getenv("DATADIR")]; require getenv("APPDIR") . "/auth.php"; user_create(getenv("AUSER"), getenv("APW"));' \
    || die "could not create the administrator."
  note "created (the password is stored only as a hash)."
fi

# ---- web server -----------------------------------------------------------------------------------
if [ "$NO_NGINX" = 0 ]; then
  step "Configuring nginx"
  SOCK="$(ls /run/php/php-fpm.sock /run/php/php*-fpm.sock 2>/dev/null | head -1 || true)"
  [ -n "$SOCK" ] || die "PHP-FPM socket not found in /run/php/."
  if ss -ltnpH 'sport = :443' 2>/dev/null | grep -v nginx | grep -q .; then
    die "port 443 is used by something other than nginx. Free it, or serve the demo another way (see README)."
  fi
  SW_HOSTPORT="${OR_SWITCH_URL#*://}"
  SW_HOST="${SW_HOSTPORT%%:*}"
  if [ "${OR_SWITCH_URL%%://*}" = https ]; then
    UPSTREAM="https://$SW_HOSTPORT/api/ws"
    if [ "$OR_SWITCH_VERIFY" = 1 ]; then
      SSL="proxy_ssl_server_name on;
        proxy_ssl_name $SW_HOST;
        proxy_ssl_verify on;
        proxy_ssl_verify_depth 4;
        proxy_ssl_trusted_certificate /etc/ssl/certs/ca-certificates.crt;"
    else
      SSL="proxy_ssl_server_name on;
        proxy_ssl_name $SW_HOST;
        proxy_ssl_verify off;"
    fi
  else
    UPSTREAM="http://$SW_HOSTPORT/api/ws"; SSL=""
  fi
  cat > /etc/nginx/sites-available/openrings-demo <<'EOF'
# OpenRings demo UI (written by install.sh). HTTPS only; port 80 stays free for certbot renewal.
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name @FQDN@;

    ssl_certificate     @CERT@;
    ssl_certificate_key @KEY@;
    ssl_protocols TLSv1.2 TLSv1.3;

    root @APP@;
    index index.php;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy no-referrer always;

    # Include files and docs are not pages.
    location ~ ^/(config|auth|lib)\.php$ { return 404; }
    location ~ ^/config\.local { return 404; }
    location ~ \.(md|conf|sh)$ { return 404; }
    location ~ /\. { return 404; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:@SOCK@;
    }

    # Browser WebSocket -> the switch's /api/ws. The Host header stays this site's
    # name, which is what the switch's same-origin check compares with the page.
    location /orws {
        proxy_pass @UPSTREAM@;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        @SSL@
        proxy_read_timeout 3600s;
    }
}
EOF
  # Fill the placeholders (| as the sed delimiter; values contain no |).
  ESC_SSL="$(printf '%s' "$SSL" | sed 's/[&|\\]/\\&/g' | sed ':a;N;$!ba;s/\n/\\n/g')"
  sed -i -e "s|@FQDN@|$OR_FQDN|" -e "s|@CERT@|$CERT|" -e "s|@KEY@|$KEY|" -e "s|@APP@|$APP|" \
         -e "s|@SOCK@|$SOCK|" -e "s|@UPSTREAM@|$UPSTREAM|" -e "s|@SSL@|$ESC_SSL|" /etc/nginx/sites-available/openrings-demo
  rm -f /etc/nginx/sites-enabled/default
  ln -sf /etc/nginx/sites-available/openrings-demo /etc/nginx/sites-enabled/openrings-demo
  nginx -t
  systemctl enable nginx >/dev/null 2>&1 || true
  systemctl restart nginx
  if [ "$OR_CERT" = letsencrypt ] || [ "$OR_CERT" = existing ]; then
    install -d /etc/letsencrypt/renewal-hooks/deploy
    printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/nginx-reload.sh
    chmod 755 /etc/letsencrypt/renewal-hooks/deploy/nginx-reload.sh
  fi
  if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q "Status: active"; then
    ufw allow 443/tcp >/dev/null && note "firewall: 443/tcp opened (ufw)"
  fi
fi

# ---- summary -----------------------------------------------------------------------------------
echo
echo "=================================================================="
echo "OpenRings demo UI installed."
[ "$NO_NGINX" = 1 ] || echo "  Open      : https://$OR_FQDN/"
echo "  Files     : $APP"
echo "  Settings  : $CFGFILE   (holds the switch administrator password)"
echo "  Users     : $DATA/users.json   (hashed passwords)"
if [ "$KEEP_USERS" = 0 ]; then
  echo "  Sign in   : $OR_ADMIN_USER"
  if [ -n "$GENERATED_PW" ]; then
    echo "  Password  : $GENERATED_PW"
    echo "              (generated; shown only now, write it down)"
  else
    echo "  Password  : the one you typed"
  fi
fi
echo
echo "Open port 443/TCP in your cloud firewall. Port 80 must stay free for certificate renewal."
echo "=================================================================="
