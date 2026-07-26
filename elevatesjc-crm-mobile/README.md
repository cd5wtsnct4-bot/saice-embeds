# Elevate SJC CRM — native mobile shell

A real Android (`.apk`/`.aab`) and iOS (`.ipa`) app, built with
[Capacitor](https://capacitorjs.com), so the CRM can be installed and
distributed like any other native app — via TestFlight/App Store, Google
Play, MDM, or a direct install.

## How this actually works — read this first

This is **not** a rewrite of the CRM in a native framework. It's a thin
native shell whose only job is to open one WebView pointed at your live
site (`server.url` in `capacitor.config.json`, currently
`https://elevatesjc.co.za/crm/`). All the actual application — every
screen, the calendar, invoicing, the responsive layout, the hamburger
menu — is still the same PHP/JS/CSS running on your server.

**What that means in practice:**
- Almost all future changes (features, bug fixes, styling) happen by
  editing the PHP/JS/CSS on the server, same as always. No app rebuild,
  no re-signing, no store resubmission.
- You only need to touch *this* project and rebuild when something
  native-level changes: the app icon/splash, the bundle ID, permissions,
  or the `server.url` itself.
- The app needs an internet connection to do anything, same as the PWA.
  There is no offline data — only static assets (CSS/JS) are cached by
  the web app's service worker.

### An honest risk: App Store review

Apple's App Store review guidelines (section 4.2, "Minimum Functionality")
have historically flagged apps that are just a website in a WebView with
nothing native added. This app is exactly that pattern. It's often fine —
it depends heavily on the reviewer and how the app presents — but you
should go in aware of the risk rather than be surprised by a rejection.
Google Play is markedly more permissive about this than Apple.

If you hit a 4.2 rejection, the standard fixes are: add at least one
genuinely native capability (push notifications is the most common one
reviewers accept), and make sure the app doesn't read as "just a browser
bookmark" — a native splash screen and app icon (already done here) help,
but reviewers look at actual behaviour, not just presentation. This
project doesn't currently add push notifications — adding
`@capacitor/push-notifications` plus a server-side sender (e.g. via
Firebase Cloud Messaging / APNs) would be the next step if you hit this
wall and want to strengthen the submission.

## Project layout

```
elevatesjc-crm-mobile/
├── capacitor.config.json   # appId, appName, server.url — the only file
│                             you're likely to edit for config changes
├── gen_mobile_assets.php    # regenerates all icons/splash screens
├── www/                     # placeholder only — server.url overrides this
├── ios/App/                 # open App.xcodeproj in Xcode (see below)
└── android/                 # open this folder in Android Studio
```

## Prerequisites

- **iOS builds:** Xcode (you already have this), plus an Apple ID. An
  Apple Developer Program membership ($99/year) is only required to
  install on a real device for more than 7 days, submit to TestFlight, or
  publish to the App Store — running in the Simulator needs neither.
- **Android builds:** [Android Studio](https://developer.android.com/studio)
  (free). A Google Play Console account is a one-time $25 fee, only
  needed if you're publishing to the Play Store.
- Node.js is **only** needed if you want to change `capacitor.config.json`
  and re-sync, or regenerate icons with `gen_mobile_assets.php` (which
  needs PHP with the GD extension, not Node). You do not need Node just to
  open and build the already-generated `ios/` and `android/` projects.

## Building for iOS (in Xcode)

1. Open `ios/App/App.xcodeproj` (not a `.xcworkspace` — this project uses
   Swift Package Manager, not CocoaPods, so there isn't one).
2. Select the **App** target → **Signing & Capabilities** → choose your
   Team (your Apple ID, or your Developer Program team once you have one).
   Xcode will offer to fix any signing errors automatically.
3. Plug in an iPhone (or pick a Simulator) from the device dropdown, then
   press **Run** (▶). It should launch and load the live CRM.
4. **To distribute:** Product → Archive, then **Distribute App**:
   - *TestFlight / App Store*: choose "App Store Connect" — needs an
     active Developer Program membership and an app record created at
     [appstoreconnect.apple.com](https://appstoreconnect.apple.com).
   - *Ad Hoc / Enterprise*: for installing directly on a list of specific
     devices or via your organisation's MDM, without the App Store.

## Building for Android (in Android Studio)

1. Open the `android/` folder directly in Android Studio (File → Open).
   Let Gradle sync — first sync can take a few minutes while it downloads
   dependencies.
2. Pick a device/emulator from the toolbar dropdown and press **Run** (▶).
3. **To distribute:**
   - Build → **Generate Signed App Bundle / APK**. The first time, create
     a new keystore (Android Studio walks you through it) — **back this
     keystore file up somewhere safe**; losing it means you can never
     publish an update to the same app listing again.
   - An `.aab` (Android App Bundle) is what Google Play wants for store
     submission; a `.apk` is what you'd use for direct install / MDM /
     sideloading, or sharing with testers directly.

## Changing the app icon (once you have your real logo file)

The icons are currently the same navy/teal "E" placeholder mark used by
the web app's default icon. Once you have your actual logo as a local
PNG/JPEG/WebP file on your machine:

```bash
php gen_mobile_assets.php /path/to/your-logo.png
```

This regenerates every iOS and Android icon size plus the splash screens,
square-cropping the same way the web app's Settings > Logo upload does
(anchored to the top for a tall mark-then-wordmark lockup, centred for a
landscape or already-square logo). Re-open/re-build in Xcode / Android
Studio afterwards to pick up the change.

If the auto-crop doesn't frame your logo well, crop a square version of
just the mark yourself and pass that in instead — a square input is used
as-is. You can also skip the script entirely and use the standard GUI
tools: drag a 1024×1024 PNG onto `AppIcon.appiconset` in Xcode's asset
catalog editor, or use Android Studio's **Image Asset Studio**
(right-click `res/` → New → Image Asset) for the Android side.

## Changing the app name, bundle ID, or server URL

Edit `capacitor.config.json`, then run:

```bash
npm install   # first time only
npx cap sync
```

This propagates the change into both native projects — Capacitor updates
`Info.plist` / `strings.xml` / `build.gradle` accordingly. Re-build in
Xcode/Android Studio afterwards. Changing the bundle ID or app ID after
you've already published a store listing effectively creates a *new* app
listing — don't change it casually once you're live.

## What's already configured

- **Portrait-locked on phones** (matches the web app's responsive
  hamburger-menu layout); iPad allows rotation.
- **Camera permission strings** (iOS `NSCameraUsageDescription` /
  `NSPhotoLibraryUsageDescription`, Android `CAMERA` permission) so the
  Expenses "Scan a Slip" receipt capture works from a native camera
  picker rather than falling back to a generic file chooser.
- **HTTPS-only** — `cleartext` is disabled in `capacitor.config.json`;
  the app simply won't load over plain HTTP.
