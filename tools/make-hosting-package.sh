#!/bin/sh
# ---------------------------------------------------------------------------
# Builds the upload-ready hosting package: the tree you drop straight into
# public_html on cPanel or any shared host.
#
#   sh tools/make-hosting-package.sh [output-dir] [git-ref]
#
# Defaults: output .verify/hosting, ref HEAD.
#
# Files are taken from the committed git tree, NOT the working directory. That
# is deliberate - the working copy accumulates local run artefacts (error logs,
# imported CSVs under storage/, a real config.php with live credentials) and
# none of that must ever reach a package that gets published.
#
# Layout produced (branch root == web root):
#
#   index.php  .htaccess  app/  config/  views/  assets/  cron/  storage/  uploads/
#   schema.sql  DEPLOYMENT.md  README.md
# ---------------------------------------------------------------------------
set -eu

ROOT=$(cd "$(dirname "$0")/.." && pwd)
OUT=${1:-$ROOT/.verify/hosting}
REF=${2:-HEAD}

cd "$ROOT"

case "$OUT" in
    /*) ;;
    *) OUT="$ROOT/$OUT" ;;
esac

if ! git rev-parse --verify --quiet "$REF" > /dev/null; then
    echo "!! '$REF' is not a git ref" >&2
    exit 1
fi

# Refuse to build from a ref whose admin/ differs from what is committed, so the
# package can never quietly omit an uncommitted fix.
if [ "$REF" = "HEAD" ] && ! git diff --quiet HEAD -- admin schema.sql; then
    echo "!! admin/ or schema.sql has uncommitted changes; commit them first" >&2
    git status --short -- admin schema.sql >&2
    exit 1
fi

echo "==> building from $(git rev-parse --short "$REF") into $OUT"
rm -rf "$OUT"
mkdir -p "$OUT"

# The contents of admin/, not the folder itself.
git archive "$REF:admin" | tar -x -C "$OUT"

git show "$REF:schema.sql"    > "$OUT/schema.sql"
git show "$REF:DEPLOYMENT.md" > "$OUT/DEPLOYMENT.md"
git show "$REF:tools/hosting-README.md" > "$OUT/README.md"

# A self-check page for the install, gated behind ?i-understand=1 so a crawler
# gets nothing. Meant to be deleted once the site is up.
git show "$REF:tools/hosting-diag.php" > "$OUT/diag.php"

# One-time key generator, so the three crypto keys never have to be hand-typed
# into PHP source over FTP. Also meant to be deleted once the panel loads.
git show "$REF:tools/hosting-setup-keys.php" > "$OUT/setup-keys.php"

# Runtime directories that must exist and be writable on the host. git archive
# carries the .gitkeep/.htaccess files, but be explicit so a missing one is a
# build failure rather than a 500 in production.
for d in storage/logs storage/backups storage/imports storage/imports/errors storage/tmp uploads; do
    mkdir -p "$OUT/$d"
    [ -f "$OUT/$d/.gitkeep" ] || : > "$OUT/$d/.gitkeep"
done

echo '==> checks'
fail=0
note() { printf '  %-6s %s\n' "$1" "$2"; [ "$1" = 'ok' ] || fail=1; }

for f in index.php .htaccess app/bootstrap.php config/config.sample.php \
         config/.htaccess views/layouts/app.php assets/css/app.css \
         cron/backup.php cron/reminders.php storage/.htaccess uploads/.htaccess \
         schema.sql README.md DEPLOYMENT.md diag.php setup-keys.php; do
    if [ -e "$OUT/$f" ]; then note ok "$f"; else note MISSING "$f"; fi
done

# Nothing that belongs only to development may ship.
for f in config/config.php tools android .github .git .verify; do
    if [ -e "$OUT/$f" ]; then note LEAKED "$f must not be in the package"; fi
done

# A stray real config would publish live database credentials and crypto keys.
if [ -f "$OUT/config/config.php" ]; then
    note LEAKED 'config/config.php'
else
    note ok 'no config.php (credentials stay off the package)'
fi

# Local run artefacts must not have come along.
strays=$(find "$OUT/storage" "$OUT/uploads" -type f \
    ! -name '.gitkeep' ! -name '.htaccess' 2>/dev/null | wc -l | tr -d ' ')
if [ "$strays" = '0' ]; then
    note ok 'storage/ and uploads/ contain no leftover run artefacts'
else
    note STRAY "$strays leftover file(s) under storage/ or uploads/"
    find "$OUT/storage" "$OUT/uploads" -type f ! -name '.gitkeep' ! -name '.htaccess' | sed 's/^/         /'
fi

# Every PHP file must parse under the target runtime.
php_files=$(find "$OUT" -name '*.php' | wc -l | tr -d ' ')
if find "$OUT" -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v 'No syntax errors' ; then
    note FAILED "php -l reported errors"
else
    note ok "$php_files PHP files parse"
fi

[ "$fail" -eq 0 ] || { echo; echo 'HOSTING PACKAGE BUILD FAILED'; exit 1; }

size=$(du -sh --apparent-size "$OUT" | cut -f1)
count=$(find "$OUT" -type f | wc -l | tr -d ' ')
echo
echo "HOSTING PACKAGE OK  ($count files, $size)"
echo "  $OUT"
echo
echo "Verify it actually serves traffic with:"
echo "  LRMS_DOCROOT=$OUT sh tools/smoke-panel.sh"
