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
  if (debug) {
    console.error('[API debug]', debug)
  }
}
