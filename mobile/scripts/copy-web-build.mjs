import { cpSync, mkdirSync, rmSync, existsSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const spa = join(root, 'dist', 'spa')
const out = join(root, '..', 'build', 'web')

if (!existsSync(spa)) {
  console.error('Missing dist/spa — run vite build first')
  process.exit(1)
}

mkdirSync(dirname(out), { recursive: true })
rmSync(out, { recursive: true, force: true })
cpSync(spa, out, { recursive: true })
writeFileSync(join(root, '..', 'build', '.gitkeep'), '')
console.log(`Copied web build → ${out}`)
