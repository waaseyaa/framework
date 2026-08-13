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

    expect(fetch).toHaveBeenNthCalledWith(1, '/admin/_surface/page-builder/page/definitions')
    expect(fetch).toHaveBeenNthCalledWith(2, '/admin/_surface/page-builder/page/42', {
      cache: 'no-store',
    })
  })

  it('binds every edit to the observed revision, document, and idempotency key', async () => {
    const fetch = vi.fn().mockResolvedValue({ ok: true, data: draft })
    const client = new PageBuilderClient('/admin/', fetch)
    const command = { type: 'remove_block', block_id: 'blk_intro' } as const

    await client.command('page', '42', draft, command, 'operation-123')

    expect(fetch).toHaveBeenCalledWith('/admin/_surface/page-builder/page/42/commands', {
      method: 'POST',
      body: {
        expected_entity_revision_id: 7,
        expected_document_fingerprint: 'a'.repeat(64),
        idempotency_key: 'operation-123',
        command,
      },
    })
  })

  it('requests preview for an exact persisted revision', async () => {
    const fetch = vi.fn().mockResolvedValue({ ok: true, data: {} })
    const client = new PageBuilderClient('/admin/', fetch)

    await client.preview('page', '42', 7)

    expect(fetch).toHaveBeenCalledWith('/admin/_surface/page-builder/page/42/preview', {
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

    expect(fetch).toHaveBeenNthCalledWith(1, '/admin/_surface/page-builder/page/42/revisions', { cache: 'no-store' })
    expect(fetch).toHaveBeenNthCalledWith(2, '/admin/_surface/page-builder/page/42/revisions/5', { cache: 'no-store' })
    expect(fetch).toHaveBeenNthCalledWith(3, '/admin/_surface/page-builder/page/42/restore', {
      method: 'POST',
      body: {
        target_revision_id: 5,
        expected_current_revision_id: 7,
        idempotency_key: 'restore-123',
      },
    })
  })
})
