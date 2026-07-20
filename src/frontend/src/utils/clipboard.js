/**
 * Copy text to clipboard. Uses Clipboard API when available,
 * falls back to a copy-event / execCommand path (needed on HTTP
 * and inside Quasar dialogs where focus is trapped).
 */
export async function copyText (text) {
  const value = String(text ?? '')
  if (!value) {
    throw new Error('Nothing to copy')
  }

  if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(value)
      return
    } catch {
      // fall through to legacy path
    }
  }

  const onCopy = (event) => {
    event.clipboardData.setData('text/plain', value)
    event.preventDefault()
  }

  document.addEventListener('copy', onCopy)
  let ok = false
  try {
    ok = document.execCommand('copy')
  } finally {
    document.removeEventListener('copy', onCopy)
  }

  if (ok) return

  // Last resort: temporary textarea (may fail under dialog focus traps)
  const area = document.createElement('textarea')
  area.value = value
  area.setAttribute('readonly', '')
  area.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;padding:0;border:0;opacity:0;'
  document.body.appendChild(area)
  area.focus()
  area.select()
  area.setSelectionRange(0, value.length)
  try {
    ok = document.execCommand('copy')
  } finally {
    area.remove()
  }

  if (!ok) {
    throw new Error('Copy command failed')
  }
}
