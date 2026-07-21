export function apiErrorMessage (error, fallback) {
  const data = error?.response?.data
  return data?.message || data?.error
    || Object.values(data?.errors || {}).flat()[0]
    || fallback
}

export function logApiErrorDebug (error) {
  const debug = error?.response?.data?.debug
  if (debug) {
    console.error('[API debug]', debug)
  }
}
