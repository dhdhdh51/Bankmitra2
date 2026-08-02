import java.util.Properties
import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
}

// The modern replacement for the deprecated android.kotlinOptions block.
kotlin {
    compilerOptions {
        jvmTarget.set(JvmTarget.JVM_17)
    }
}

/**
 * Release signing is read from keystore.properties when present.
 *
 * CI builds without it: assembleRelease then produces an unsigned APK, which is
 * exactly what we want for an artefact upload. Signing config is only wired up
 * when the file actually exists, so the build never fails on a missing keystore.
 */
val keystorePropertiesFile = rootProject.file("keystore.properties")
val keystoreProperties = Properties().apply {
    if (keystorePropertiesFile.exists()) {
        keystorePropertiesFile.inputStream().use { load(it) }
    }
}
val hasSigningConfig = keystoreProperties.getProperty("storeFile") != null

android {
    namespace = "com.lrms.recovery"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.lrms.recovery"
        minSdk = 24          // Android 7.0 - covers the low-end devices agents use
        targetSdk = 36
        // Bumped on every release that changes the app. An agent comparing "which build
        // am I on" against what the bank sent out has nothing else to go by, and Android
        // will not install a lower versionCode over a higher one.
        versionCode = 4
        versionName = "1.3.0"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"

        // The production server, baked into the build. Agents must never have to
        // type a URL - and must not be able to point the app somewhere else.
        //
        // ALLOW_CUSTOM_SERVER decides whether the address is editable at all. It
        // is false for release and true for debug, so a developer can still aim a
        // debug build at a laptop without shipping that hole to the field: a
        // borrower-data app that accepts an arbitrary API host is a phishing
        // target, since anyone who can get an agent to paste a URL gets their
        // credentials and their leads.
        buildConfigField("String", "DEFAULT_API_BASE_URL", "\"https://my.controversy.blog/api/v1/\"")
        buildConfigField("boolean", "ALLOW_CUSTOM_SERVER", "false")

        resourceConfigurations += listOf("en", "hi")
    }

    signingConfigs {
        /**
         * A COMMITTED debug keystore, and it is not laziness.
         *
         * Gradle's default is to generate ~/.android/debug.keystore on whatever machine
         * is building, which means every CI runner signs with a brand-new certificate.
         * Android refuses to install an APK over an app signed by a different
         * certificate, so each build was a fresh app that could only be installed after
         * uninstalling the previous one - and "the new APK does not work" is exactly what
         * that looks like on a phone, with the agent's queued work lost to the uninstall.
         *
         * A debug key protects nothing (the passwords are the public defaults, and the
         * debug build carries a .debug application id), so committing it costs nothing
         * and makes every build a normal update of the one before it.
         */
        getByName("debug") {
            storeFile = rootProject.file("app/debug.keystore")
            storePassword = "android"
            keyAlias = "androiddebugkey"
            keyPassword = "android"
        }

        if (hasSigningConfig) {
            create("release") {
                storeFile = rootProject.file(keystoreProperties.getProperty("storeFile"))
                storePassword = keystoreProperties.getProperty("storePassword")
                keyAlias = keystoreProperties.getProperty("keyAlias")
                keyPassword = keystoreProperties.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        debug {
            applicationIdSuffix = ".debug"
            versionNameSuffix = "-debug"
            isMinifyEnabled = false
            // Cleartext is allowed in debug only, so a local http:// server works.
            buildConfigField("boolean", "ALLOW_CLEARTEXT", "true")
            // Only a debug build may be re-pointed at another host.
            buildConfigField("boolean", "ALLOW_CUSTOM_SERVER", "true")
        }

        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
            buildConfigField("boolean", "ALLOW_CLEARTEXT", "false")

            if (hasSigningConfig) {
                signingConfig = signingConfigs.getByName("release")
            }
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    buildFeatures {
        viewBinding = true
        buildConfig = true
    }

    testOptions {
        unitTests {
            isIncludeAndroidResources = true
            isReturnDefaultValues = true
        }
    }

    packaging {
        resources {
            excludes += setOf(
                "/META-INF/{AL2.0,LGPL2.1}",
                "/META-INF/DEPENDENCIES",
                "/META-INF/LICENSE*",
                "/META-INF/NOTICE*",
            )
        }
    }

    lint {
        // Lint findings should not block an APK artefact in CI.
        abortOnError = false
        warningsAsErrors = false
        checkReleaseBuilds = false
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.appcompat)
    implementation(libs.material)
    implementation(libs.androidx.constraintlayout)
    implementation(libs.androidx.swiperefreshlayout)

    implementation(libs.androidx.lifecycle.viewmodel.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.activity.ktx)
    implementation(libs.androidx.fragment.ktx)

    // One branded launch screen across API 24-36. Without it the splash theme is
    // only a window background: Android 12+ draws its own icon over it and
    // everything older shows a blank colour flash.
    implementation(libs.androidx.core.splashscreen)

    implementation(libs.kotlinx.coroutines.android)

    implementation(libs.retrofit)
    implementation(libs.retrofit.converter.gson)
    implementation(libs.okhttp)
    implementation(libs.okhttp.logging)

    implementation(libs.glide)

    // Encrypted SharedPreferences for the JWT and refresh token.
    implementation(libs.androidx.security.crypto)

    // Reads the EXIF orientation so camera photos are not uploaded sideways.
    implementation(libs.androidx.exifinterface)

    testImplementation(libs.junit)
    testImplementation(libs.okhttp.mockwebserver)
    testImplementation(libs.kotlinx.coroutines.test)
    testImplementation(libs.robolectric)

    androidTestImplementation(libs.androidx.junit)
    androidTestImplementation(libs.androidx.espresso.core)
}
