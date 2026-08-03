// Root build file. Plugins are declared here and applied in :app.
//
// Touched to re-trigger build-android.yml (path-filtered to android/**) after a run of
// backend-only fixes on main that this workflow's push trigger never saw.
plugins {
    alias(libs.plugins.android.application) apply false
    alias(libs.plugins.kotlin.android) apply false
}
