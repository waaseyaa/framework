export const ADMIN_CONTRACT_VERSION = '0.1'

/**
 * Check if a server-reported version is compatible with the client version.
 * Compatible = same major version (pre-1.0: same major.minor).
 */
export function isContractCompatible(serverVersion: string, clientVersion: string = ADMIN_CONTRACT_VERSION): boolean {
  const [sMajor, sMinor] = serverVersion.split('.').map(Number)
  const [cMajor, cMinor] = clientVersion.split('.').map(Number)
  if (cMajor === 0) return sMajor === cMajor && sMinor === cMinor
  return sMajor === cMajor
}
