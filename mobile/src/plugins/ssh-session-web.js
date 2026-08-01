import { WebPlugin } from '@capacitor/core'

function sleep (ms) {
  return new Promise(resolve => setTimeout(resolve, ms))
}

export class SshSessionWeb extends WebPlugin {
  connected = false

  async connect (options) {
    await sleep(400)
    if (!options?.host) {
      throw this.unavailable('host is required')
    }
    this.connected = true
    this.host = options.host
    this.port = options.port || 22
    this.username = options.username || 'root'
    return { ok: true, mock: true }
  }

  async exec (options) {
    if (!this.connected) {
      throw this.unavailable('Not connected')
    }
    const command = String(options?.command || '')
    const lines = []

    const emit = async (stream, data) => {
      lines.push(data)
      this.notifyListeners('output', { stream, data })
      await sleep(120)
    }

    if (command.includes('install.sh') || command.includes('awg-gui')) {
      await emit('stdout', '[mock] Connected to ' + this.host + '\n')
      await emit('stdout', '[mock] Running production installer (--yes)\n')
      await emit('stdout', '[awg-gui-install] Ensuring Docker…\n')
      await emit('stdout', '[ok] Docker ready\n')
      await emit('stdout', '[awg-gui-install] Downloading release bundle…\n')
      await emit('stdout', '[awg-gui-install] Starting containers…\n')
      await emit('stdout', '[ok] Installation complete\n')
      const result = {
        ok: true,
        panel_port: 8877,
        username: 'admin',
        password: 'mock-admin-password',
        url: `http://${this.host}:8877`
      }
      await emit('stdout', `AWG_GUI_RESULT=${JSON.stringify(result)}\n`)
      return { code: 0, stdout: lines.join(''), stderr: '' }
    }

    if (command.includes('ADMIN_PASSWORD') || command.includes('.env')) {
      const line = 'ADMIN_PASSWORD=mock-admin-password\n'
      await emit('stdout', line)
      return { code: 0, stdout: line, stderr: '' }
    }

    await emit('stdout', `[mock] exec: ${command}\n`)
    return { code: 0, stdout: lines.join(''), stderr: '' }
  }

  async disconnect () {
    this.connected = false
    return { ok: true }
  }
}
