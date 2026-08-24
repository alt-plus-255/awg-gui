# Android build

1. Install Android Studio + SDK
2. From `mobile/`:
   ```bash
   npm run cap:sync
   npx cap open android
   ```
3. Build → Build APK / Bundle in Android Studio
4. Copy the resulting APK/AAB here, e.g.:
   ```text
   build/android/awg-gui-installer.apk
   ```

Debug APK path after Gradle assemble:
`mobile/android/app/build/outputs/apk/debug/app-debug.apk`
