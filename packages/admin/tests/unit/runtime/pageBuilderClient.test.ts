import { describe, expect, it, vi } from 'vitest'
import type { PageBuilderDraft } from '~/contracts/pageBuilder'
import { PageBuilderClient } from '~/runtime/pageBuilderClient'

const draft: PageBuilderDraft = {
  entity_id: '42',
  entity_revision_id: 7,
  document_fingerprint: 'a'.repeat(64),
  document: {
    schema: 'waaseyaa.layout',
    version: 1,
    template: { id: 'standard', version: 1 },
    sections: [],
  },
}

describe('PageBuilderClient', () => {
  it('loads definitions and the current draft from the common admin surface', async () => {
    const fetch = vi.fn().mockResolvedValue({ ok: true, data: {} })
    const client = new PageBuilderClient('/admin/', fetch)

    await client.definitions('page')
    await client.draft('page', '42')

    expect(fetch).toHaveBeenNthCalledWith(1, '/admin/_surface/page-builder/page/definitions', {
      ignoreResponseError: true,
    })
    expect(fetch).toHaveBeenNthCalledWith(2, '/admin/_surface/page-builder/page/42', {
      ignoreResponseError: true,
      cache: 'no-store',
    })
  })

  it('binds every edit to the observed revision, document, and idempotency key', async () => {
    const fetch = vi.fn().mockResolvedValue({ ok: true, data: draft })
    const client = new PageBuilderClient('/admin/', fetch)
    const command = { type: 'remove_block', block_id: 'blk_intro' } as const

    await client.command('page', '42', draft, command, 'operation-123')

    expect(fetch).toHaveBeenCalledWith('/admin/_surface/page-builder/page/42/commands', {
      ignoreResponseError: true,
      method: 'POST',
      body: {
        expected_entity_revision_id: 7,
        expected_document_fingerprint: 'a'.repeat(64),
        idempotency_key: 'operation-123',
        command,
      },
    })
  })

  it('omits the receipt key entirely for an ordinary save', async () => {
    const fetch = vi.fn().mockResolvedValue({ ok: true, data: draft })
    const client = new PageBuilderClient('/admin/', fetch)

    await client.command('page', '42', draft, { type: 'remove_block', block_id: 'blk_intro' }, 'operation-123', [])

    const body = fetch.mock.calls[0]?.[1]?.body as Record<string, unknown>
    expect(Object.keys(body).sort()).toEqual([
      'command',
      'expected_document_fingerprint',
      'expected_entity_revision_id',
      'idempotency_key',
    ])
  })

  it('returns the received acknowledgements verbatim on the same bound retry', async () => {
    const fetch = vi.fn().mockResolvedValue({ ok: true, data: draft })
    const client = new PageBuilderClient('/admin/', fetch)
    const command = { type: 'remove_block', block_id: 'blk_intro' } as const
    const receipts = ['b'.repeat(64), 'c'.repeat(64)]

    await client.command('page', '42', draft, command, 'operation-123', receipts)

    expect(fetch).toHaveBeenCalledWith('/admin/_surface/page-builder/page/42/commands', {
      ignoreResponseError: true,
      method: 'POST',
      body: {
        expected_entity_revision_id: 7,
        expected_document_fingerprint: 'a'.repeat(64),
        idempotency_key: 'operation-123',
        command,
        save_advisory_acknowledgements: receipts,
      },
    })
  })

  /**
   * The seven endpoints promote a typed refusal onto the status line (#2409).
   * `usePageBuilder` reads 409, 428, and 501 out of the resolved body, so every
   * call has to opt out of ofetch's throw-on-refusal or the review flow is lost.
   */
  it('resolves the refusal body on every page-builder request', async () => {
    const fetch = vi.fn().mockResolvedValue({ ok: true, data: draft })
    const client = new PageBuilderClient('/admin/', fetch)

    await client.definitions('page')
    await client.draft('page', '42')
    await client.command('page', '42', draft, { type: 'remove_block', block_id: 'blk_intro' }, 'operation-123')
    await client.preview('page', '42', 7)
    await client.history('page', '42')
    await client.revision('page', '42', 5)
    await client.restore('page', '42', 5, 7, 'restore-123')

    expect(fetch).toHaveBeenCalledTimes(7)
    for (const call of fetch.mock.calls) {
      expect(call[1]).toMatchObject({ ignoreResponseError: true })
    }
  })

  /**
   * The same contract against an ofetch-shaped transport rather than the option
   * object: `useApi().apiFetch` is plain `$fetch`, which rejects on a non-2xx
   * status unless the call opts out. `usePageBuilder` reads the conflict,
   * advisory-review, and unsupported-acknowledgement branches out of the
   * resolved body, so a rejection here loses all three.
   */
  it('still resolves a promoted refusal through a transport that throws on non-2xx', async () => {
    const refusal = { ok: false, error: { status: 428, title: 'Precondition Required' } }
    const fetch = vi.fn(async (_url: string, options?: Record<string, unknown>) => {
      if (options?.ignoreResponseError !== true) throw new Error('FetchError: 428 Precondition Required')
      return refusal
    }) as unknown as (<T>(url: string, options?: Record<string, unknown>) => Promise<T>) & { mock: { calls: unknown[] } }
    const client = new PageBuilderClient('/admin/', fetch)

    await expect(client.definitions('page')).resolves.toEqual(refusal)
    await expect(client.draft('page', '42')).resolves.toEqual(refusal)
    await expect(
      client.command('page', '42', draft, { type: 'remove_block', block_id: 'blk_intro' }, 'operation-123'),
    ).resolves.toEqual(refusal)
    await expect(client.preview('page', '42', 7)).resolves.toEqual(refusal)
    await expect(client.history('page', '42')).resolves.toEqual(refusal)
    await expect(client.revision('page', '42', 5)).resolves.toEqual(refusal)
    await expect(client.restore('page', '42', 5, 7, 'restore-123')).resolves.toEqual(refusal)
  })

  it('requests preview for an exact persisted revision', async () => {
    const fetch = vi.fn().mockResolvedValue({ ok: true, data: {} })
    const client = new PageBuilderClient('/admin/', fetch)

    await client.preview('page', '42', 7)

    expect(fetch).toHaveBeenCalledWith('/admin/_surface/page-builder/page/42/preview', {
      ignoreResponseError: true,
      method: 'POST',
      body: { expected_entity_revision_id: 7 },
    })
  })

  it('loads exact history and restores through a conflict-bound draft command', async () => {
    const fetch = vi.fn().mockResolvedValue({ ok: true, data: {} })
    const client = new PageBuilderClient('/admin/', fetch)

    await client.history('page', '42')
    await client.revision('page', '42', 5)
    await client.restore('page', '42', 5, 7, 'restore-123')

    expect(fetch).toHaveBeenNthCalledWith(1, '/admin/_surface/page-builder/page/42/revisions', { ignoreResponseError: true, cache: 'no-store' })
    expect(fetch).toHaveBeenNthCalledWith(2, '/admin/_surface/page-builder/page/42/revisions/5', { ignoreResponseError: true, cache: 'no-store' })
    expect(fetch).toHaveBeenNthCalledWith(3, '/admin/_surface/page-builder/page/42/restore', {
      ignoreResponseError: true,
      method: 'POST',
      body: {
        target_revision_id: 5,
        expected_current_revision_id: 7,
        idempotency_key: 'restore-123',
      },
    })
  })
})
