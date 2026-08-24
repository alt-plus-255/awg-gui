import { Capacitor } from '@capacitor/core'
import { SshSession } from '@/plugins/ssh-session'

/**
 * @param {(chunk: { stream: string, data: string }) => void} onOutput
 */
export async function withSshSession (credentials, onOutput, fn) {
  let handle
  try {
    handle = await SshSession.addListener('output', (event) => {
      onOutput?.(event)
    })
  } catch {
    // web mock still works via notifyListeners
  }

  try {
    await SshSession.connect({
      host: credentials.host,
      port: Number(credentials.port) || 22,
      username: credentials.username,
      password: credentials.password
    })
    return await fn(SshSession)
  } finally {
    try {
      await SshSession.disconnect()
    } catch {
      // ignore
    }
    if (handle) {
      await handle.remove()
    }
  }
}

export function isNativeSsh () {
  return Capacitor.isNativePlatform()
}
