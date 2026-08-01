const INSTALL_URL = 'https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/install.sh'
const RESULT_PREFIX = 'AWG_GUI_RESULT='

export function buildInstallCommand ({ skipKernel = false } = {}) {
  const args = ['--yes']
  if (skipKernel) args.push('--no-awg-kernel')
  const joined = args.join(' ')
  // Prefer root shell; if not root, use sudo -n when possible.
  return [
    'set -euo pipefail',
    'export DEBIAN_FRONTEND=noninteractive',
    `CMD='curl -fsSL ${INSTALL_URL} | bash -s -- ${joined}'`,
    'if [ "$(id -u)" -eq 0 ]; then',
    '  eval "$CMD"',
    'elif command -v sudo >/dev/null 2>&1; then',
    `  sudo -n bash -lc "$CMD" || sudo bash -lc "$CMD"`,
    'else',
    '  echo "[error] root or sudo required" >&2',
    '  exit 1',
    'fi'
  ].join('\n')
}

export function parseInstallResult (stdout) {
  const lines = String(stdout || '').split(/\r?\n/)
  for (let i = lines.length - 1; i >= 0; i -= 1) {
    const line = lines[i].trim()
    const idx = line.indexOf(RESULT_PREFIX)
    if (idx === -1) continue
    const raw = line.slice(idx + RESULT_PREFIX.length)
    try {
      const data = JSON.parse(raw)
      return {
        ok: Boolean(data.ok),
        panelPort: Number(data.panel_port) || 8877,
        username: data.username || 'admin',
        password: data.password || '',
        url: data.url || ''
      }
    } catch {
      // keep scanning
    }
  }
  return null
}

export function parseEnvPassword (stdout) {
  const match = String(stdout || '').match(/^ADMIN_PASSWORD=(.*)$/m)
  if (!match) return ''
  return match[1].trim().replace(/^['"]|['"]$/g, '')
}

export async function readAdminPasswordViaSsh (ssh) {
  const command = [
    'set -e',
    'for f in /opt/awg-gui/.env /opt/awg-gui/src/.env; do',
    '  if [ -f "$f" ]; then',
    '    grep -E "^ADMIN_PASSWORD=" "$f" | tail -n1',
    '    exit 0',
    '  fi',
    'done',
    'echo ""'
  ].join('\n')
  const result = await ssh.exec({ command })
  return parseEnvPassword(result.stdout || '')
}

export async function pollPanelReady (baseUrl, { timeoutMs = 180000, intervalMs = 3000 } = {}) {
  const url = `${String(baseUrl).replace(/\/$/, '')}/api/login/info`
  const started = Date.now()
  let lastError = null

  while (Date.now() - started < timeoutMs) {
    try {
      const controller = new AbortController()
      const timer = setTimeout(() => controller.abort(), 8000)
      const res = await fetch(url, {
        method: 'GET',
        headers: { Accept: 'application/json' },
        signal: controller.signal
      })
      clearTimeout(timer)
      if (res.ok) {
        const data = await res.json().catch(() => ({}))
        return { ok: true, info: data }
      }
      lastError = new Error(`HTTP ${res.status}`)
    } catch (err) {
      lastError = err
    }
    await new Promise(resolve => setTimeout(resolve, intervalMs))
  }

  throw lastError || new Error('Panel readiness timeout')
}

/**
 * Full install pipeline over an open SSH session.
 */
export async function runRemoteInstall (ssh, { skipKernel = false, onStatus } = {}) {
  onStatus?.('running')
  const command = buildInstallCommand({ skipKernel })
  const result = await ssh.exec({ command })
  const combined = `${result.stdout || ''}\n${result.stderr || ''}`
  let parsed = parseInstallResult(combined)

  if (!parsed?.password) {
    onStatus?.('readingCreds')
    const password = await readAdminPasswordViaSsh(ssh)
    parsed = {
      ok: result.code === 0,
      panelPort: parsed?.panelPort || 8877,
      username: parsed?.username || 'admin',
      password,
      url: parsed?.url || ''
    }
  }

  if (result.code !== 0 && !parsed?.password) {
    const err = new Error(`Installer exited with code ${result.code}`)
    err.code = result.code
    err.stdout = result.stdout
    err.stderr = result.stderr
    throw err
  }

  return parsed
}
