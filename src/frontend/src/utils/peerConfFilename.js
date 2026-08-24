/** Suggested .conf filename from `# Name =` in peer export (matches backend clientImportLabel). */
export function peerConfFilename (confText, fallback = 'peer.conf') {
  const match = String(confText ?? '').match(/^#\s*Name\s*=\s*(.+)$/m)
  const label = match?.[1]?.trim()
  if (!label) {
    return fallback
  }
  const safe = label.replace(/[^\w.-]+/g, '-')

  return `${safe || 'peer'}.conf`
}
