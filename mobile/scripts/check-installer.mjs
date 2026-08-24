import { parseInstallResult, parseEnvPassword, buildInstallCommand } from '../src/services/installer.js'

function assert (cond, msg) {
  if (!cond) throw new Error(msg)
}

const sample = `
[ok] Installation complete
AWG_GUI_RESULT={"ok":true,"panel_port":8877,"username":"admin","password":"s3cret","url":"http://1.2.3.4:8877"}
`
const parsed = parseInstallResult(sample)
assert(parsed?.password === 's3cret', 'password')
assert(parsed?.panelPort === 8877, 'port')
assert(parsed?.url.includes('1.2.3.4'), 'url')

assert(parseEnvPassword('ADMIN_PASSWORD=abc123\n') === 'abc123', 'env pass')
assert(buildInstallCommand({ skipKernel: true }).includes('--no-awg-kernel'), 'skip kernel')
assert(buildInstallCommand({}).includes('--yes'), 'yes flag')

console.log('installer service checks ok')
