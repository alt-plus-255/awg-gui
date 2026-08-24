import { Capacitor } from '@capacitor/core'
import { InAppBrowser, ToolBarType } from '@capgo/inappbrowser'

function buildLoginScript (username, password) {
  const user = JSON.stringify(String(username || 'admin'))
  const pass = JSON.stringify(String(password || ''))
  return `
(async function () {
  try {
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' });
    var match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    var xsrf = match ? decodeURIComponent(match[1]) : '';
    var res = await fetch('/api/login', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrf
      },
      body: JSON.stringify({ username: ${user}, password: ${pass} })
    });
    if (!res.ok) {
      console.error('login failed', res.status);
      return;
    }
    location.replace('/');
  } catch (e) {
    console.error(e);
  }
})();
true;
`
}

export function isNativePanel () {
  return Capacitor.isNativePlatform()
}

/**
 * Open panel in native in-app browser and inject Sanctum login.
 */
export async function openPanelWithAutoLogin ({ url, username, password }) {
  const target = String(url || '').replace(/\/$/, '')
  if (!target) throw new Error('Panel URL is required')

  if (!isNativePanel()) {
    window.open(`${target}/login`, '_blank', 'noopener,noreferrer')
    return { mode: 'external' }
  }

  await InAppBrowser.openWebView({
    url: `${target}/login`,
    title: 'AWG-GUI',
    toolbarType: ToolBarType.NAVIGATION,
    isPresentAfterPageLoad: true,
    activeNativeNavigationForWebview: true
  })

  // Give the login page a moment to load, then inject.
  await new Promise(resolve => setTimeout(resolve, 1500))
  try {
    await InAppBrowser.executeScript({
      code: buildLoginScript(username, password)
    })
  } catch (err) {
    console.warn('executeScript failed', err)
  }

  return { mode: 'webview' }
}

export async function closePanelBrowser () {
  if (!isNativePanel()) return
  try {
    await InAppBrowser.close()
  } catch {
    // ignore
  }
}
