/**
 * Halt store/composable background API activity after logout or session expiry.
 * Safe to call repeatedly; uses dynamic imports to avoid circular deps with auth.
 */
export async function stopAuthenticatedBackgroundWork () {
  const [
    { useSystemStore },
    { useProjectUpdateStore },
    { useSpeedTestStore },
    { disconnectLiveStats }
  ] = await Promise.all([
    import('@/stores/system'),
    import('@/stores/projectUpdate'),
    import('@/stores/speedTest'),
    import('@/composables/useLiveStats')
  ])

  useSystemStore().stopAllPolls()
  useProjectUpdateStore().stopPoll()
  useSpeedTestStore().stopPoll()
  disconnectLiveStats()
}
