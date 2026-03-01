export interface JsonApiResource {
  type: string
  id: string
  attributes: Record<string, any>
  relationships?: Record<string, any>
  links?: Record<string, string>
  meta?: Record<string, any>
}

export interface JsonApiDocument {
  jsonapi: { version: string }
  data: JsonApiResource | JsonApiResource[] | null
  errors?: Array<{ status: string; title: string; detail?: string }>
  meta?: Record<string, any>
  links?: Record<string, string>
}

export function useEntity() {
  async function list(
    type: string,
    query: Record<string, any> = {},
  ): Promise<{ data: JsonApiResource[]; meta: Record<string, any>; links: Record<string, string> }> {
    const params = new URLSearchParams()

    if (query.page) {
      const offset = typeof query.page.offset === 'number' ? query.page.offset : 0
      const limit = typeof query.page.limit === 'number' ? query.page.limit : 25
      params.set('page[offset]', String(offset))
      params.set('page[limit]', String(limit))
    }
    if (query.sort) {
      params.set('sort', query.sort)
    }

    const qs = params.toString()
    const url = `/api/${type}${qs ? '?' + qs : ''}`

    const response = await $fetch<JsonApiDocument>(url)
    return {
      data: (Array.isArray(response.data) ? response.data : []) as JsonApiResource[],
      meta: response.meta ?? {},
      links: response.links ?? {},
    }
  }

  async function get(type: string, id: string): Promise<JsonApiResource> {
    const response = await $fetch<JsonApiDocument>(`/api/${type}/${id}`)
    return response.data as JsonApiResource
  }

  async function create(type: string, attributes: Record<string, any>): Promise<JsonApiResource> {
    const response = await $fetch<JsonApiDocument>(`/api/${type}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/vnd.api+json' },
      body: {
        data: { type, attributes },
      },
    })
    return response.data as JsonApiResource
  }

  async function update(
    type: string,
    id: string,
    attributes: Record<string, any>,
  ): Promise<JsonApiResource> {
    const response = await $fetch<JsonApiDocument>(`/api/${type}/${id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/vnd.api+json' },
      body: {
        data: { type, id, attributes },
      },
    })
    return response.data as JsonApiResource
  }

  async function remove(type: string, id: string): Promise<void> {
    await $fetch(`/api/${type}/${id}`, { method: 'DELETE' })
  }

  async function search(
    type: string,
    labelField: string,
    query: string,
    limit: number = 10,
  ): Promise<JsonApiResource[]> {
    if (query.length < 2) return []

    const params = new URLSearchParams()
    params.set(`filter[${labelField}][operator]`, 'STARTS_WITH')
    params.set(`filter[${labelField}][value]`, query)
    params.set('page[limit]', String(limit))
    params.set('sort', labelField)

    const url = `/api/${type}?${params.toString()}`
    const response = await $fetch<JsonApiDocument>(url)
    return (Array.isArray(response.data) ? response.data : []) as JsonApiResource[]
  }

  return { list, get, create, update, remove, search }
}
