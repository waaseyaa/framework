export const PUBLIC_AUTH_PATHS = ['/login', '/register', '/forgot-password', '/reset-password', '/verify-email'] as const

function trimTrailingSlashes(path: string): string {
  return path.replace(/\/+$/, '') || '/'
}

export function normalizeAdminPath(path: string, baseUrl: string = ''): string {
  const normalizedPath = trimTrailingSlashes(path || '/')
  const normalizedBaseUrl = baseUrl && baseUrl !== '/' ? trimTrailingSlashes(baseUrl) : ''

  if (normalizedBaseUrl && normalizedPath === normalizedBaseUrl) {
    return '/'
  }

  if (normalizedBaseUrl && normalizedPath.startsWith(`${normalizedBaseUrl}/`)) {
    return normalizedPath.slice(normalizedBaseUrl.length) || '/'
  }

  return normalizedPath
}

export function isPublicAuthPath(path: string, baseUrl: string = ''): boolean {
  return PUBLIC_AUTH_PATHS.includes(normalizeAdminPath(path, baseUrl) as (typeof PUBLIC_AUTH_PATHS)[number])
}
