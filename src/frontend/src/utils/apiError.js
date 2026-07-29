export function apiErrorMessage (error, fallback) {
  const data = error?.response?.data
  const fromMessage = data?.message != null ? String(data.message).trim() : ''
  if (fromMessage) return fromMessage

  const fromErrors = Object.values(data?.errors || {}).flat().map((v) => String(v || '').trim()).find(Boolean)
  if (fromErrors) return fromErrors

  // Prefer human text; skip machine codes like "not_found"
  const fromError = data?.error != null ? String(data.error).trim() : ''
  if (fromError && (/\s/.test(fromError) || fromError.length > 40)) {
    return fromError
  }

  return fallback
}

export function logApiErrorDebug (error) {
  const debug = error?.response?.data?.debug
  if (!debug) return

  const short = `${debug.exception ?? 'Exception'}: ${debug.message ?? ''}`
  const location = `${debug.file ?? ''}:${debug.line ?? ''}`
  const trace = Array.isArray(debug.trace) ? debug.trace.join('\n') : ''

  console.group('[API 500]', short)
  console.error('Location:', location)
  if (trace) console.error('Trace:\n' + trace)
  console.groupEnd()
}

export function getApiDebugMessage (error) {
  const debug = error?.response?.data?.debug
  if (!debug) return null
  const ex = debug.exception ? debug.exception.replace(/^.*\\/, '') : ''
  const msg = debug.message ?? ''
  const loc = debug.file ? (debug.file.replace(/^.*\//, '') + ':' + (debug.line ?? '')) : ''
  const parts = [ex, msg, loc].filter(Boolean)
  return parts.join(' · ')
}
