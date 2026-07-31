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
        versionCode = 1
        versionName = "1.0.0"

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
