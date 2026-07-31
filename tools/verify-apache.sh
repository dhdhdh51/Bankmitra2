#!/bin/sh
# ---------------------------------------------------------------------------
# Serves the hosting package through a REAL Apache with `AllowOverride All`,
# which is the only way to test admin/.htaccess at all.
#
# Every other harness in this repo uses PHP's built-in server, and that server
# ignores .htaccess completely - so rewrite rules, deny rules, the HTTPS
# redirect and DirectoryIndex were never exercised until this script existed.
#
#   sh tools/verify-apache.sh [package-dir]
#
# Needs httpd + php-fpm (Amazon Linux: dnf install -y httpd php-fpm php-mysqlnd
# php-mbstring php-gd php-zip) and a MySQL for the app itself.
# ---------------------------------------------------------------------------
set -eu

ROOT=$(cd "$(dirname "$0")/.." && pwd)
DOCROOT=${1:-$ROOT/.verify/hosting}
DOCROOT=$(cd "$DOCROOT" && pwd)
WORK=$ROOT/.verify/apache
PORT=8097
CT=lrms-apache
DB_PORT=13308

[ -f "$DOCROOT/index.php" ] || { echo "!! $DOCROOT has no index.php"; exit 1; }

PASSED=0
FAILED=0
pass() { PASSED=$((PASSED + 1)); printf '  PASS  %s\n' "$1"; }
fail() { FAILED=$((FAILED + 1)); printf '  FAIL  %s\n' "$1"; }

cleanup() {
    [ -f "$WORK/httpd.pid" ] && kill "$(cat "$WORK/httpd.pid")" 2>/dev/null || true
    [ -f "$WORK/php-fpm.pid" ] && kill "$(cat "$WORK/php-fpm.pid")" 2>/dev/null || true
    docker rm -f "$CT" >/dev/null 2>&1 || true
    rm -f "$DOCROOT/config/config.php"
}
trap cleanup EXIT INT TERM

cleanup
rm -rf "$WORK"
mkdir -p "$WORK/logs" "$WORK/sessions" "$WORK/uploadtmp"
chmod 733 "$WORK/sessions" "$WORK/uploadtmp"

# ---------------------------------------------------------------------------
echo "==> MySQL on port $DB_PORT"
# ---------------------------------------------------------------------------
docker run -d --name "$CT" -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=lrms \
    -p "$DB_PORT":3306 mysql:8.0 > /dev/null

i=0
while [ "$i" -lt 120 ]; do
    docker exec "$CT" mysql -uroot -proot -e "SELECT 1" > /dev/null 2>&1 && break
    i=$((i + 1)); sleep 2
done
[ "$i" -lt 120 ] || { echo '!! MySQL never became ready'; exit 1; }
docker exec -i "$CT" mysql -uroot -proot lrms < "$ROOT/schema.sql" 2>&1 | grep -v 'Using a password' || true

# ---------------------------------------------------------------------------
echo '==> config.php'
# ---------------------------------------------------------------------------
APP_KEY=$(php -r 'echo bin2hex(random_bytes(32));')
DATA_KEY=$(php -r 'echo bin2hex(random_bytes(32));')
PEPPER=$(php -r 'echo bin2hex(random_bytes(32));')
cat > "$DOCROOT/config/config.php" <<PHPEOF
<?php
return [
    'db' => ['host' => '127.0.0.1', 'port' => $DB_PORT, 'name' => 'lrms',
             'user' => 'root', 'pass' => 'root', 'charset' => 'utf8mb4'],
    'app_key' => '$APP_KEY', 'data_key' => '$DATA_KEY', 'hash_pepper' => '$PEPPER',
    'app' => ['url' => 'https://127.0.0.1:$PORT', 'base_path' => '', 'env' => 'local',
              'debug' => true, 'timezone' => 'Asia/Kolkata'],
    'paths' => ['uploads' => __DIR__ . '/../uploads', 'storage' => __DIR__ . '/../storage'],
    'uploads' => ['max_photo_bytes' => 8388608, 'max_document_bytes' => 12582912,
        'max_import_bytes' => 26214400,
        'allowed_image_mime' => ['image/jpeg', 'image/png', 'image/webp'],
        'allowed_doc_mime' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']],
    'session' => ['name' => 'lrms_session', 'lifetime' => 7200, 'secure' => false],
];
PHPEOF

LRMS_APP_ROOT="$DOCROOT" LRMS_DB_HOST=127.0.0.1 LRMS_DB_PORT="$DB_PORT" \
LRMS_DB_NAME=lrms LRMS_DB_USER=root LRMS_DB_PASS=root \
    php "$ROOT/tools/seed-demo.php" > "$WORK/seed.log" 2>&1 \
    || { cat "$WORK/seed.log"; exit 1; }

# ---------------------------------------------------------------------------
# Ownership must match the PHP/Apache user, or storage/ and uploads/ writes fail
# with a permission error - which is itself one of the classic causes of a 403.
chown -R nobody:nobody "$DOCROOT"

echo '==> php-fpm'
# ---------------------------------------------------------------------------
cat > "$WORK/php-fpm.conf" <<FPMEOF
[global]
pid = $WORK/php-fpm.pid
error_log = $WORK/logs/php-fpm.log
daemonize = yes
[www]
; php-fpm refuses to run a pool as root. On a real cPanel host this is the site
; user; here it is nobody, and the docroot is chowned to match so the app can
; write to storage/ and uploads/ exactly as it would in production.
user = nobody
group = nobody
listen = 127.0.0.1:9123
pm = static
pm.max_children = 4
php_admin_value[error_log] = $WORK/logs/php-error.log
php_admin_flag[log_errors] = on
; /tmp is not writable by the pool user in this sandbox; a real host has its own
; session directory already set up.
php_admin_value[session.save_path] = $WORK/sessions
php_admin_value[upload_tmp_dir] = $WORK/uploadtmp
FPMEOF
php-fpm --fpm-config "$WORK/php-fpm.conf"

# ---------------------------------------------------------------------------
echo '==> Apache with AllowOverride All'
# ---------------------------------------------------------------------------
MODDIR=/usr/lib64/httpd/modules
cat > "$WORK/httpd.conf" <<APEOF
ServerName 127.0.0.1
Listen $PORT
PidFile $WORK/httpd.pid
ErrorLog $WORK/logs/error.log
LogLevel warn rewrite:trace2
User nobody
Group nobody

LoadModule mpm_prefork_module $MODDIR/mod_mpm_prefork.so
LoadModule unixd_module $MODDIR/mod_unixd.so
LoadModule authz_core_module $MODDIR/mod_authz_core.so
LoadModule authz_host_module $MODDIR/mod_authz_host.so
LoadModule access_compat_module $MODDIR/mod_access_compat.so
LoadModule dir_module $MODDIR/mod_dir.so
LoadModule mime_module $MODDIR/mod_mime.so
LoadModule log_config_module $MODDIR/mod_log_config.so
LoadModule alias_module $MODDIR/mod_alias.so
LoadModule rewrite_module $MODDIR/mod_rewrite.so
LoadModule headers_module $MODDIR/mod_headers.so
LoadModule expires_module $MODDIR/mod_expires.so
LoadModule filter_module $MODDIR/mod_filter.so
LoadModule deflate_module $MODDIR/mod_deflate.so
LoadModule setenvif_module $MODDIR/mod_setenvif.so
LoadModule proxy_module $MODDIR/mod_proxy.so
LoadModule proxy_fcgi_module $MODDIR/mod_proxy_fcgi.so

TypesConfig /etc/mime.types
CustomLog $WORK/logs/access.log "%h %r %>s %b"

DocumentRoot "$DOCROOT"
<Directory "$DOCROOT">
    # Exactly what cPanel gives you.
    AllowOverride All
    Require all granted
</Directory>

<FilesMatch "\.php\$">
    SetHandler "proxy:fcgi://127.0.0.1:9123"
</FilesMatch>
APEOF

httpd -f "$WORK/httpd.conf" -t || { echo '!! httpd config invalid'; exit 1; }
httpd -f "$WORK/httpd.conf"

i=0
while [ "$i" -lt 40 ]; do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break
    i=$((i + 1)); sleep 0.5
done

B="http://127.0.0.1:$PORT"
# The .htaccess forces HTTPS; this header is what a real proxy-terminated TLS
# setup sends, and it lets us exercise the app over plain HTTP here.
H='-H X-Forwarded-Proto:https'

code() { curl -s -o /dev/null -w '%{http_code}' $H "$B$1"; }
body() { curl -s $H "$B$1"; }

echo
echo '== The HTTPS redirect'
RAW=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/login")
case "$RAW" in
    301*https://*) pass "plain HTTP is redirected to HTTPS ($RAW)" ;;
    *) fail "expected a 301 to https, got: $RAW" ;;
esac
if [ "$(code /login)" = '200' ]; then
    pass 'X-Forwarded-Proto: https is honoured (no redirect loop behind a proxy)'
else
    fail "with X-Forwarded-Proto the login page returned $(code /login)"
fi

echo
echo '== Front controller and pretty URLs'
for p in / /login /forgot-password; do
    c=$(code "$p")
    case "$p:$c" in
        /:200|/:302|/login:200|/forgot-password:200) pass "GET $p -> $c" ;;
        *) fail "GET $p -> $c" ;;
    esac
done
if body /login | grep -q 'csrf'; then
    pass 'login page really rendered through PHP'
else
    fail 'login page did not render'
fi

echo
echo '== Internals must not be reachable'
for p in /app/bootstrap.php /app/Core/Crypto.php /config/config.php \
         /config/config.sample.php /views/layouts/app.php /storage/logs/php-error.log \
         /schema.sql /README.md /DEPLOYMENT.md; do
    c=$(code "$p")
    case "$c" in
        403|404) pass "$p is blocked ($c)" ;;
        *) fail "$p returned $c - it should be blocked" ;;
    esac
done

if body /config/config.php | grep -qi 'data_key'; then
    fail 'config.php contents were served!'
else
    pass 'config.php contents are not served'
fi

echo
echo '== Cron scripts are not usable over the web'
# cron/ sits inside the document root on shared hosting because there is nowhere
# else to put it, so a browser can reach the URL. The scripts refuse to run
# outside the CLI; this proves the guard holds through Apache rather than only in
# the source.
for p in /cron/backup.php /cron/reminders.php; do
    c=$(code "$p")
    b=$(body "$p")
    case "$c" in
        403|404)
            if printf '%s' "$b" | grep -qi 'backup ok\|reminders sent'; then
                fail "$p returned $c but still executed the job"
            else
                pass "$p is refused ($c) and did not run"
            fi
            ;;
        *) fail "$p returned $c - a cron script must not be web-runnable" ;;
    esac
done
# A backup triggered over the web would leak the whole database as a download.
BEFORE_BK=$(find "$DOCROOT/storage/backups" -name '*.sql' 2>/dev/null | wc -l | tr -d ' ')
code /cron/backup.php > /dev/null
AFTER_BK=$(find "$DOCROOT/storage/backups" -name '*.sql' 2>/dev/null | wc -l | tr -d ' ')
[ "$BEFORE_BK" = "$AFTER_BK" ] && pass 'a web request to cron/backup.php creates no backup file' \
    || fail 'cron/backup.php ran over HTTP' "backups $BEFORE_BK -> $AFTER_BK"

echo
echo '== Uploads are not web-served'
mkdir -p "$DOCROOT/uploads/photos"
echo 'secret-image-bytes' > "$DOCROOT/uploads/photos/probe.jpg"
c=$(code /uploads/photos/probe.jpg)
case "$c" in
    403|404) pass "uploads/photos/probe.jpg is blocked ($c)" ;;
    *) fail "uploads/photos/probe.jpg returned $c" ;;
esac
rm -f "$DOCROOT/uploads/photos/probe.jpg"

echo
echo '== Public assets still work'
c=$(code /assets/css/app.css)
if [ "$c" = '200' ]; then pass "assets/css/app.css -> 200"; else fail "assets/css/app.css -> $c"; fi

echo
echo '== Security headers'
HDRS=$(curl -s -D - -o /dev/null $H "$B/login")
for h in X-Content-Type-Options X-Frame-Options Referrer-Policy; do
    if echo "$HDRS" | grep -qi "^$h:"; then pass "$h present"; else fail "$h missing"; fi
done

echo
echo '== Directory listing'
c=$(code /assets/)
case "$c" in
    403|404) pass "directory listing refused ($c)" ;;
    200) fail 'directory listing is enabled' ;;
    *) fail "unexpected $c for /assets/" ;;
esac

echo
echo '== API through Apache (Bearer auth must survive)'
LOGIN_RESP=$(curl -s $H -X POST "$B/api/v1/auth/login" \
    -H 'Content-Type: application/json' \
    -d '{"employee_code":"AGT001","password":"Agent@123"}')
TOKEN=$(printf '%s' "$LOGIN_RESP" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["access_token"] ?? "";')
if [ -n "$TOKEN" ]; then
    pass 'API login through Apache returned a token'
    if curl -s $H -H "Authorization: Bearer $TOKEN" "$B/api/v1/auth/me" | grep -q '"success":true'; then
        pass 'Authorization header reaches PHP (Bearer auth works under FastCGI)'
    else
        fail 'the Authorization header did not reach PHP'
    fi
else
    fail 'API login through Apache failed'
    printf '        response: %s\n' "$(printf '%s' "$LOGIN_RESP" | head -c 400)"
fi

echo
echo '============================================================'
printf '  APACHE: %s passed, %s failed\n' "$PASSED" "$FAILED"
echo '============================================================'
if [ "$FAILED" -ne 0 ]; then
    echo
    echo '== Apache error log (last 25 lines)'
    tail -25 "$WORK/logs/error.log" 2>/dev/null || true
    exit 1
fi
echo
echo 'APACHE / HTACCESS OK'
