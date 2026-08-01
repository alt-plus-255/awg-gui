import { registerPlugin } from '@capacitor/core'

/**
 * @typedef {object} SshConnectOptions
 * @property {string} host
 * @property {number} port
 * @property {string} username
 * @property {string} password
 */

/**
 * Native Android plugin + web mock.
 * @type {import('@capacitor/core').Plugin & {
 *   connect(options: SshConnectOptions): Promise<{ ok: boolean }>
 *   exec(options: { command: string }): Promise<{ code: number, stdout: string, stderr: string }>
 *   disconnect(): Promise<{ ok: boolean }>
 *   addListener(eventName: 'output', listener: (event: { stream: string, data: string }) => void): Promise<import('@capacitor/core').PluginListenerHandle>
 * }}
 */
export const SshSession = registerPlugin('SshSession', {
  web: () => import('./ssh-session-web').then(m => new m.SshSessionWeb())
})
