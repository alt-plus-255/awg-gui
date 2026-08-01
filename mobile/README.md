# AWG-GUI Mobile Installer

Quasar + Vue 3 + Capacitor app that installs [awg-gui](https://github.com/alt-plus-255/awg-gui) on a VDS over SSH, streams the installer log, then opens the panel in an in-app WebView with auto-login.

## Requirements

- Node.js 18+
- For Android builds: Android Studio, JDK 17, Android SDK

## Develop (browser, mock SSH)

Run from WSL (or any Linux/macOS shell) — Windows npm over `\\wsl$\...` UNC paths breaks native postinstall scripts:

```bash
cd mobile
npm install
npm run dev
```

Open http://localhost:9100 — SSH is mocked; install flow prints a fake `AWG_GUI_RESULT` line.

## Build web assets

```bash
npm run build
```

Output: `../build/web` (and `mobile/dist/spa` for Capacitor).

## Android

```bash
npm run cap:sync
npx cap open android
```

Build APK in Android Studio, then copy to `../build/android/`.

Notes for Android Studio setup are written to `../build/android/README.md` by `npm run cap:android`.

### Native pieces

- **SshSession** — Capacitor plugin (JSch on Android; web mock in browser)
- **@capgo/inappbrowser** — panel WebView + injected Sanctum login script

Cleartext HTTP to `http://IP:8877` is enabled for post-install panel access.

## Flow

1. FAQ
2. SSH form (host, port 22, user, password)
3. Live terminal → `dist/install.sh --yes`
4. Parse `AWG_GUI_RESULT=…` (or read `ADMIN_PASSWORD` from `/opt/awg-gui/.env`)
5. Poll `/api/login/info` → WebView auto-login as `admin`
