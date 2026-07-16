export interface NormalizedValidationFailure {
  fieldErrors: Record<string, string>
  globalErrors: string[]
}

interface ValidationErrorLike {
  status?: unknown
  title?: unknown
  detail?: unknown
  message?: unknown
  source?: unknown
  parameter?: unknown
  pointer?: unknown
  data?: unknown
  errors?: unknown
  cause?: unknown
}

function objectValue(value: unknown): ValidationErrorLike | null {
  return typeof value === 'object' && value !== null ? value as ValidationErrorLike : null
}

function collectErrors(value: unknown, seen = new Set<unknown>()): ValidationErrorLike[] {
  const candidate = objectValue(value)
  if (candidate === null || seen.has(value)) return []
  seen.add(value)

  if (Array.isArray(candidate.errors)) {
    const errors = candidate.errors
      .map(objectValue)
      .filter((error): error is ValidationErrorLike => error !== null)
    if (errors.length > 0) return errors
  }

  const nested = collectErrors(candidate.data, seen)
  return nested.length > 0 ? nested : [candidate]
}

function sourceField(source: unknown): string | null {
  const candidate = objectValue(source)
  if (candidate === null) return null

  if (typeof candidate.parameter === 'string') {
    let parameter = candidate.parameter
    if (parameter.startsWith('attributes.')) parameter = parameter.slice('attributes.'.length)
    if (parameter.startsWith('attributes[')) parameter = parameter.slice('attributes['.length)
    if (parameter.endsWith(']')) parameter = parameter.slice(0, -1)
    return parameter.split('.', 1)[0]?.split('[', 1)[0] || null
  }

  if (typeof candidate.pointer !== 'string') return null
  const segments = candidate.pointer
    .split('/')
    .filter(Boolean)
    .map(segment => segment.replace(/~1/g, '/').replace(/~0/g, '~'))
  const attributes = segments.lastIndexOf('attributes')
  return attributes >= 0 && typeof segments[attributes + 1] === 'string'
    ? segments[attributes + 1]!
    : null
}

function statusOf(error: ValidationErrorLike): number {
  const status = Number(error.status)
  return Number.isFinite(status) ? status : 0
}

function isNetworkFailure(value: unknown): boolean {
  let current: unknown = value
  const seen = new Set<unknown>()
  while (current !== null && typeof current === 'object' && !seen.has(current)) {
    if (current instanceof TypeError) return true
    seen.add(current)
    current = (current as ValidationErrorLike).cause
  }
  return false
}

function structuredMessage(error: ValidationErrorLike): string | null {
  if (typeof error.detail === 'string' && error.detail.trim() !== '') return error.detail
  if (objectValue(error.source) !== null) {
    if (typeof error.message === 'string' && error.message.trim() !== '') return error.message
    if (typeof error.title === 'string' && error.title.trim() !== '') return error.title
  }
  return null
}

export function normalizeValidationFailure(
  failure: unknown,
  knownFields: ReadonlySet<string>,
): NormalizedValidationFailure {
  const fieldErrors: Record<string, string> = {}
  const globalErrors: string[] = []
  const errors = collectErrors(failure)

  for (const error of errors) {
    const message = structuredMessage(error)
    if (message === null) continue
    const field = sourceField(error.source)
    if (field !== null && knownFields.has(field)) {
      fieldErrors[field] ??= message
    } else {
      globalErrors.push(message)
    }
  }

  if (Object.keys(fieldErrors).length > 0 || globalErrors.length > 0) {
    return { fieldErrors, globalErrors }
  }

  const first = errors[0] ?? {}
  const status = statusOf(first)
  if (status === 401 || status === 403) {
    globalErrors.push('You do not have permission to save this record.')
  } else if (isNetworkFailure(failure)) {
    globalErrors.push('A network error prevented this record from being saved.')
  } else if (status >= 500) {
    globalErrors.push('The server could not save this record. Please try again.')
  } else {
    globalErrors.push('The record could not be saved. Review the form and try again.')
  }

  return { fieldErrors, globalErrors }
}
