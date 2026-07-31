#!/bin/sh
# ---------------------------------------------------------------------------
# Proves both APK paths in .github/workflows/build-android.yml actually work.
#
#   Scenario A - keystore present: assembleRelease must emit a SIGNED
#                app-release.apk that apksigner verifies against our certificate.
#
#   Scenario B - no keystore: the workflow builds the DEBUG APK instead, because
#                assembleRelease would emit app-release-unsigned.apk and Android
#                refuses to install an unsigned APK. This scenario proves the
#                debug APK really is signed and therefore installable.
#
# It also pins down the PKCS12 password trap: keytool's default keystore format
# cannot hold a key password different from the store password, and silently
# ignores -keypass. Gradle then fails with "Given final block not properly
# padded", which says nothing about passwords.
#
# Everything it creates is removed on exit, including the test-signed APK.
#
# Usage: sh tools/verify-signing.sh
# ---------------------------------------------------------------------------
set -eu

ROOT=$(cd "$(dirname "$0")/.." && pwd)
ANDROID_DIR="$ROOT/android"
WORK="$ROOT/.verify/signing"

JAVA_HOME=${JAVA_HOME:-/root/.local/share/mise/installs/java/17}
export JAVA_HOME
PATH="$JAVA_HOME/bin:$PATH"
export PATH

ANDROID_HOME=${ANDROID_HOME:-$HOME/android-sdk}
export ANDROID_HOME
ANDROID_SDK_ROOT="$ANDROID_HOME"
export ANDROID_SDK_ROOT

# PKCS12 keystores require the key password to equal the store password.
STORE_PASS='test-store-pass'
KEY_PASS="$STORE_PASS"
KEY_ALIAS='lrms-test'

PASSED=0
FAILED=0

cleanup() {
    rm -f "$ANDROID_DIR/release.keystore" "$ANDROID_DIR/keystore.properties"
    # A test-signed APK must never be mistaken for a real release later.
    rm -f "$ANDROID_DIR/app/build/outputs/apk/release/app-release.apk"
    rm -rf "$WORK"
}
trap cleanup EXIT INT TERM

pass() { PASSED=$((PASSED + 1)); printf '  PASS  %s\n' "$1"; }
fail() { FAILED=$((FAILED + 1)); printf '  FAIL  %s\n' "$1"; }
die()  { printf '\nABORT: %s\n' "$1" >&2; exit 1; }

cleanup
mkdir -p "$WORK"

APKSIGNER=$(find "$ANDROID_HOME/build-tools" -name apksigner -type f 2>/dev/null | sort | tail -1)
[ -n "$APKSIGNER" ] || die "apksigner not found under $ANDROID_HOME/build-tools (run tools/verify-android.sh first)"

cd "$ANDROID_DIR"

# ---------------------------------------------------------------------------
echo
echo '== The PKCS12 password trap'
# ---------------------------------------------------------------------------
keytool -genkeypair -keystore "$WORK/mismatch.jks" \
    -storepass 'store-one' -keypass 'key-two' \
    -alias a -keyalg RSA -keysize 2048 -validity 5 -dname 'CN=Mismatch' \
    > "$WORK/mismatch.log" 2>&1 || die 'keytool failed on the mismatch keystore'

if grep -q 'Different store and key passwords not supported for PKCS12' "$WORK/mismatch.log"; then
    pass 'keytool warns that PKCS12 ignores a separate -keypass'
else
    fail 'expected keytool to warn about PKCS12 ignoring -keypass'
fi

if keytool -list -keystore "$WORK/mismatch.jks" -storepass 'store-one' 2>/dev/null \
       | grep -q 'Keystore type: PKCS12'; then
    pass 'keytool still defaults to PKCS12'
else
    fail 'expected keytool to default to a PKCS12 keystore'
fi

# This is why the workflow compares the two passwords instead of probing with
# keytool: on PKCS12 every keytool probe silently falls back to the store
# password, so a wrong key password looks fine right up until Gradle fails.
if keytool -certreq -alias a -keystore "$WORK/mismatch.jks" \
       -storepass 'store-one' -keypass 'key-two' > /dev/null 2>&1; then
    pass 'keytool cannot detect a wrong key password on PKCS12 (probe is useless there)'
else
    fail 'expected the PKCS12 keytool probe to succeed even with a wrong key password'
fi

# On JKS the key is encrypted separately, so -certreq is a genuine probe and the
# workflow uses it for that format.
keytool -genkeypair -storetype JKS -keystore "$WORK/real.jks" \
    -storepass 'store-one' -keypass 'key-two' \
    -alias a -keyalg RSA -keysize 2048 -validity 5 -dname 'CN=Jks' \
    > "$WORK/jks.log" 2>&1 || die 'keytool failed on the JKS keystore'

if keytool -certreq -alias a -keystore "$WORK/real.jks" \
       -storepass 'store-one' -keypass 'key-two' > /dev/null 2>&1; then
    pass 'on JKS the probe accepts the correct key password'
else
    fail 'the JKS probe rejected the correct key password'
fi
if keytool -certreq -alias a -keystore "$WORK/real.jks" \
       -storepass 'store-one' -keypass 'wrong-pass' > /dev/null 2>&1; then
    fail 'the JKS probe accepted a wrong key password'
else
    pass 'on JKS the probe rejects a wrong key password'
fi

# Finally: prove the mismatch really does break the build, which is the failure
# the workflow precheck exists to pre-empt.
printf 'storeFile=mismatch.jks\nstorePassword=store-one\nkeyAlias=a\nkeyPassword=key-two\n' \
    > keystore.properties
cp "$WORK/mismatch.jks" mismatch.jks
if ./gradlew --no-daemon --quiet :app:assembleRelease > "$WORK/mismatch-build.log" 2>&1; then
    fail 'a PKCS12 key-password mismatch should have failed the build'
else
    if grep -q 'final block not properly padded' "$WORK/mismatch-build.log"; then
        pass 'the mismatch fails the build with the opaque padding error the check pre-empts'
    else
        pass 'the mismatch fails the build'
    fi
fi
rm -f keystore.properties mismatch.jks

# ---------------------------------------------------------------------------
echo
echo '== Scenario A: keystore configured -> signed release APK'
# ---------------------------------------------------------------------------
keytool -genkeypair -v \
    -keystore "$WORK/lrms-test.jks" \
    -storepass "$STORE_PASS" -keypass "$KEY_PASS" \
    -alias "$KEY_ALIAS" \
    -keyalg RSA -keysize 2048 -validity 30 \
    -dname 'CN=LRMS Signing Test, OU=CI, O=LRMS, L=Pune, S=MH, C=IN' \
    > "$WORK/keytool.log" 2>&1 || die 'keytool could not create the test keystore'

# Encode and decode exactly the way KEYSTORE_BASE64 is handled in CI.
base64 -w0 "$WORK/lrms-test.jks" > "$WORK/keystore.b64"
printf '%s' "$(cat "$WORK/keystore.b64")" | base64 -d > release.keystore

if [ "$(sha256sum < "$WORK/lrms-test.jks")" = "$(sha256sum < release.keystore)" ]; then
    pass 'the base64 round trip is byte-identical'
else
    fail 'the base64 round trip corrupted the keystore'
fi

if keytool -list -keystore release.keystore -storepass "$STORE_PASS" > /dev/null 2>&1; then
    pass 'the decoded keystore opens'
else
    fail 'the decoded keystore could not be opened'
fi

if keytool -certreq -alias "$KEY_ALIAS" -keystore release.keystore \
       -storepass "$STORE_PASS" -keypass "$KEY_PASS" > /dev/null 2>&1; then
    pass 'the key password precheck used by the workflow succeeds'
else
    fail 'the key password precheck failed on a good keystore'
fi

umask 077
{
    echo 'storeFile=release.keystore'
    echo "storePassword=$STORE_PASS"
    echo "keyAlias=$KEY_ALIAS"
    echo "keyPassword=$KEY_PASS"
} > keystore.properties

rm -f app/build/outputs/apk/release/*.apk
echo '  ... assembleRelease'
./gradlew --no-daemon --quiet :app:assembleRelease > "$WORK/release.log" 2>&1 \
    || { tail -25 "$WORK/release.log"; die 'assembleRelease failed with a signing config present'; }

if [ -f app/build/outputs/apk/release/app-release-unsigned.apk ]; then
    fail 'Gradle emitted an UNSIGNED APK even though keystore.properties existed'
else
    pass 'Gradle did not fall back to an unsigned APK'
fi

APK=app/build/outputs/apk/release/app-release.apk
if [ -f "$APK" ]; then
    # --apparent-size: a signed, zipaligned APK is block-padded on disk, so
    # plain `du -h` reports ~6.7M for a 2.7M file.
    pass "app-release.apk exists ($(du -h --apparent-size "$APK" | cut -f1))"
else
    die "expected $APK to exist"
fi

"$APKSIGNER" verify --verbose --print-certs "$APK" > "$WORK/verify-release.log" 2>&1 \
    || { cat "$WORK/verify-release.log"; die 'apksigner rejected the release APK'; }

if grep -q 'Verifies' "$WORK/verify-release.log"; then
    pass 'apksigner verifies the release APK'
else
    fail 'apksigner did not report Verifies for the release APK'
fi
if grep -qi 'LRMS Signing Test' "$WORK/verify-release.log"; then
    pass 'the release APK carries our test certificate'
else
    fail 'the release APK is not signed by the expected certificate'
fi
if grep -q 'v2 scheme (APK Signature Scheme v2): true' "$WORK/verify-release.log"; then
    pass 'signed with APK Signature Scheme v2'
else
    fail 'APK Signature Scheme v2 is not present'
fi

# ---------------------------------------------------------------------------
echo
echo '== Scenario B: no keystore -> installable debug APK'
# ---------------------------------------------------------------------------
rm -f release.keystore keystore.properties
rm -f app/build/outputs/apk/release/app-release.apk

echo '  ... assembleRelease with no keystore'
./gradlew --no-daemon --quiet :app:assembleRelease > "$WORK/unsigned.log" 2>&1 \
    || { tail -25 "$WORK/unsigned.log"; die 'assembleRelease failed without a keystore'; }

if [ -f app/build/outputs/apk/release/app-release-unsigned.apk ]; then
    pass 'without a keystore Gradle emits app-release-unsigned.apk'
else
    fail 'expected an unsigned release APK when no keystore is configured'
fi

# The whole reason the workflow ships the debug APK in this case.
if "$APKSIGNER" verify app/build/outputs/apk/release/app-release-unsigned.apk > /dev/null 2>&1; then
    fail 'the unsigned release APK unexpectedly verified'
else
    pass 'the unsigned release APK does NOT verify, so it cannot be installed'
fi

echo '  ... assembleDebug'
./gradlew --no-daemon --quiet :app:assembleDebug > "$WORK/debug.log" 2>&1 \
    || { tail -25 "$WORK/debug.log"; die 'assembleDebug failed'; }

DEBUG_APK=app/build/outputs/apk/debug/app-debug.apk
if [ -f "$DEBUG_APK" ]; then
    pass "app-debug.apk exists ($(du -h --apparent-size "$DEBUG_APK" | cut -f1))"
else
    die "expected $DEBUG_APK to exist"
fi

"$APKSIGNER" verify --verbose --print-certs "$DEBUG_APK" > "$WORK/verify-debug.log" 2>&1 \
    || { cat "$WORK/verify-debug.log"; die 'apksigner rejected the debug APK'; }

if grep -q 'Verifies' "$WORK/verify-debug.log"; then
    pass 'the debug APK is signed and verifies, so it installs on a device'
else
    fail 'the debug APK does not verify'
fi
if grep -qi 'Android Debug' "$WORK/verify-debug.log"; then
    pass 'the debug APK carries the standard Android debug certificate'
else
    fail 'unexpected certificate on the debug APK'
fi

# ---------------------------------------------------------------------------
echo
echo '============================================================'
printf '  SIGNING: %s passed, %s failed\n' "$PASSED" "$FAILED"
echo '============================================================'
[ "$FAILED" -eq 0 ] || exit 1
echo
echo 'RELEASE SIGNING OK'
