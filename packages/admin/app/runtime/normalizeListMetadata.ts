export const LIST_FORMATTERS = ['text', 'date', 'datetime', 'boolean/status', 'enum'] as const
export const LIST_OPERATORS = ['EQUALS', 'NOT_EQUALS', 'CONTAINS', 'STARTS_WITH', 'IN', 'GT', 'LT', 'GTE', 'LTE'] as const

export type ListFormatter = typeof LIST_FORMATTERS[number]
export type ListOperator = typeof LIST_OPERATORS[number]
export type ListDirection = 'ASC' | 'DESC'

export interface ListColumnMetadata {
  field: string
  label: string
  sortable: boolean
  formatter: ListFormatter
  valueLabels?: Record<string, string>
}

export interface ListSearchMetadata {
  field: string
  operator: ListOperator
  label: string
  description?: string
}

export interface ListFilterOption { value: string | number | boolean | null; label: string }
export interface ListFilterMetadata {
  field: string
  operator: ListOperator
  label: string
  options?: ListFilterOption[]
  default?: string | number | boolean | null
}

export interface ListSortMetadata { field: string; direction: ListDirection; label: string }
export interface ListMetadata {
  columns: ListColumnMetadata[]
  search?: ListSearchMetadata
  filters: ListFilterMetadata[]
  sorts: ListSortMetadata[]
  defaultSort?: { field: string; direction: ListDirection }
}

const FIELD = /^[A-Za-z_][A-Za-z0-9_.]*$/

function record(value: unknown): Record<string, unknown> | null {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    ? value as Record<string, unknown>
    : null
}

function hasOnlyKeys(value: Record<string, unknown>, keys: string[]): boolean {
  return Object.keys(value).every(key => keys.includes(key))
}

function field(value: unknown): value is string {
  return typeof value === 'string' && FIELD.test(value)
}

function label(value: unknown): value is string {
  return typeof value === 'string' && value.trim() !== '' && value.length <= 500
}

function scalar(value: unknown): value is string | number | boolean | null {
  return value === null || ['string', 'number', 'boolean'].includes(typeof value)
}

export function normalizeListMetadata(raw: unknown): ListMetadata | null {
  const source = record(raw)
  if (!source || !hasOnlyKeys(source, ['columns', 'search', 'filters', 'sorts', 'defaultSort'])) return null

  const columns: ListColumnMetadata[] = []
  if ('columns' in source) {
    if (!Array.isArray(source.columns)) return null
    for (const rawColumn of source.columns) {
      const column = record(rawColumn)
      if (!column || !hasOnlyKeys(column, ['field', 'label', 'sortable', 'formatter', 'valueLabels'])) return null
      if (!field(column.field) || !label(column.label) || typeof column.sortable !== 'boolean'
        || typeof column.formatter !== 'string' || !LIST_FORMATTERS.includes(column.formatter as ListFormatter)) return null
      const normalized: ListColumnMetadata = {
        field: column.field,
        label: column.label.trim(),
        sortable: column.sortable,
        formatter: column.formatter as ListFormatter,
      }
      if ('valueLabels' in column) {
        const values = record(column.valueLabels)
        if (!values) return null
        const normalizedValues: Record<string, string> = {}
        for (const [value, rawLabel] of Object.entries(values)) {
          if (!label(rawLabel)) return null
          normalizedValues[value] = rawLabel.trim()
        }
        normalized.valueLabels = normalizedValues
      }
      columns.push(normalized)
    }
  }

  let search: ListSearchMetadata | undefined
  if ('search' in source) {
    const value = record(source.search)
    if (!value || !hasOnlyKeys(value, ['field', 'operator', 'label', 'description'])
      || !field(value.field) || !label(value.label) || typeof value.operator !== 'string'
      || !LIST_OPERATORS.includes(value.operator as ListOperator)
      || ('description' in value && !label(value.description))) return null
    search = { field: value.field, operator: value.operator as ListOperator, label: value.label.trim() }
    if (typeof value.description === 'string') search.description = value.description.trim()
  }

  const filters: ListFilterMetadata[] = []
  if ('filters' in source) {
    if (!Array.isArray(source.filters)) return null
    for (const rawFilter of source.filters) {
      const value = record(rawFilter)
      if (!value || !hasOnlyKeys(value, ['field', 'operator', 'label', 'options', 'default'])
        || !field(value.field) || !label(value.label) || typeof value.operator !== 'string'
        || !LIST_OPERATORS.includes(value.operator as ListOperator)) return null
      const normalized: ListFilterMetadata = { field: value.field, operator: value.operator as ListOperator, label: value.label.trim() }
      if ('options' in value) {
        if (!Array.isArray(value.options)) return null
        normalized.options = []
        for (const rawOption of value.options) {
          const option = record(rawOption)
          if (!option || !hasOnlyKeys(option, ['value', 'label']) || !('value' in option)
            || !scalar(option.value) || !label(option.label)) return null
          normalized.options.push({ value: option.value, label: option.label.trim() })
        }
      }
      if ('default' in value) {
        if (!scalar(value.default)) return null
        normalized.default = value.default
      }
      filters.push(normalized)
    }
  }

  const sorts: ListSortMetadata[] = []
  if ('sorts' in source) {
    if (!Array.isArray(source.sorts)) return null
    for (const rawSort of source.sorts) {
      const value = record(rawSort)
      if (!value || !hasOnlyKeys(value, ['field', 'direction', 'label']) || !field(value.field)
        || !label(value.label) || !['ASC', 'DESC'].includes(String(value.direction))) return null
      sorts.push({ field: value.field, direction: value.direction as ListDirection, label: value.label.trim() })
    }
  }

  let defaultSort: { field: string; direction: ListDirection } | undefined
  if ('defaultSort' in source) {
    const value = record(source.defaultSort)
    if (!value || !hasOnlyKeys(value, ['field', 'direction']) || !field(value.field)
      || !['ASC', 'DESC'].includes(String(value.direction))) return null
    defaultSort = { field: value.field, direction: value.direction as ListDirection }
    if (!sorts.some(sort => sort.field === defaultSort?.field && sort.direction === defaultSort.direction)) return null
  }

  if (columns.some(column => column.sortable && !sorts.some(sort => sort.field === column.field))) return null

  return { columns, ...(search ? { search } : {}), filters, sorts, ...(defaultSort ? { defaultSort } : {}) }
}
