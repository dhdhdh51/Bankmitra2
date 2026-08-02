#!/usr/bin/env sh
# ---------------------------------------------------------------------------
# Builds the Android app locally: unit tests + debug APK + release APK.
#
#   sh tools/verify-android.sh
#
# Installs the Android command line tools and SDK packages on first run if
# ANDROID_HOME is not already populated. Requires JDK 17.
# ---------------------------------------------------------------------------
set -e

ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)
ANDROID_DIR="$ROOT_DIR/android"

# JDK 17: AGP 8.13 requires 17, and the Gradle in this image cannot run on 25.
if [ -d /root/.local/share/mise/installs/java/17 ]; then
  JAVA_HOME=/root/.local/share/mise/installs/java/17
  export JAVA_HOME
  PATH="$JAVA_HOME/bin:$PATH"
  export PATH
fi

echo "==> java: $(java -version 2>&1 | head -1)"

SDK_ROOT=${ANDROID_HOME:-$HOME/android-sdk}
export ANDROID_HOME="$SDK_ROOT"
export ANDROID_SDK_ROOT="$SDK_ROOT"

if [ ! -d "$SDK_ROOT/platforms/android-36" ]; then
  echo "==> installing the Android SDK into $SDK_ROOT"
  mkdir -p "$SDK_ROOT/cmdline-tools"

  if [ ! -x "$SDK_ROOT/cmdline-tools/latest/bin/sdkmanager" ]; then
    TOOLS_ZIP="$SDK_ROOT/cmdline-tools.zip"
    curl -fsSL -o "$TOOLS_ZIP" \
      https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip
    (cd "$SDK_ROOT/cmdline-tools" && unzip -q -o "$TOOLS_ZIP" && rm -f "$TOOLS_ZIP")
    mv "$SDK_ROOT/cmdline-tools/cmdline-tools" "$SDK_ROOT/cmdline-tools/latest" 2>/dev/null || true
  fi

  SDKMANAGER="$SDK_ROOT/cmdline-tools/latest/bin/sdkmanager"
  yes 2>/dev/null | "$SDKMANAGER" --licenses >/dev/null 2>&1 || true
  "$SDKMANAGER" --install "platform-tools" "platforms;android-36" "build-tools;35.0.0" >/dev/null
fi

echo "==> sdk ready: $(ls "$SDK_ROOT/platforms" 2>/dev/null | tr '\n' ' ')"

cd "$ANDROID_DIR"

# local.properties is git-ignored; the SDK path is machine specific.
printf 'sdk.dir=%s\n' "$SDK_ROOT" > local.properties

# Geometry, before anything expensive. A launcher masks an adaptive icon into a
# circle, and artwork that leaves the safe circle is cropped on a real phone
# while compiling and rendering perfectly here - which is how the mark shipped
# with the top of its "2" cut off.
echo ""
echo "==> adaptive icon safe zone"
python3 "$ROOT_DIR/tools/check-icon-safezone.py"

# Before the build, because it costs a second and the failure it catches costs a crash on
# somebody else's phone in a language nobody here tests in.
echo ""
echo "==> translations"
python3 "$ROOT_DIR/tools/verify-android-strings.py"

echo ""
echo "==> unit tests"
./gradlew --no-daemon testDebugUnitTest

echo ""
echo "==> assembleDebug"
./gradlew --no-daemon assembleDebug

echo ""
echo "==> assembleRelease"
./gradlew --no-daemon assembleRelease

echo ""
echo "==> artefacts"
find app/build/outputs -name '*.apk' -exec ls -lh {} \; 2>/dev/null || true

echo ""
echo "ANDROID BUILD OK"
