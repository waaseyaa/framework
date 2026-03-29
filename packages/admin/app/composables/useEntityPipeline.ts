import { useEntity } from './useEntity'
import type { ListQuery } from '../contracts/transport'

export type CardDensity = 'compact' | 'standard' | 'detailed'

export interface BoardConfig {
  stages: string[]
  transitions: Record<string, string[]>
  [key: string]: unknown
}

export interface PipelineCard {
  id: string
  label: string
  stage: string
  attributes: Record<string, unknown>
}

export interface PipelineFilters {
  [field: string]: { operator: string; value: string }
}

export function useEntityPipeline() {
  const { list, runAction } = useEntity()

  const cards = ref<Map<string, PipelineCard>>(new Map())
  const columns = ref<Map<string, string[]>>(new Map())
  const config = ref<BoardConfig | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const activeFilters = ref<PipelineFilters>({})

  async function loadBoard(entityType: string) {
    loading.value = true
    error.value = null
    try {
      const boardConfig = await runAction(entityType, 'board-config') as BoardConfig
      config.value = boardConfig

      const query: ListQuery = {
        sort: '-stage_changed_at',
        page: { offset: 0, limit: 500 },
        filter: activeFilters.value,
      }
      const result = await list(entityType, query)

      const newCards = new Map<string, PipelineCard>()
      const newColumns = new Map<string, string[]>()

      for (const stage of boardConfig.stages) {
        newColumns.set(stage, [])
      }

      for (const entity of result.data) {
        const card: PipelineCard = {
          id: entity.id,
          label: entity.attributes.label ?? entity.attributes.title ?? entity.id,
          stage: entity.attributes.stage ?? boardConfig.stages[0] ?? '',
          attributes: entity.attributes,
        }
        newCards.set(card.id, card)

        const stageIds = newColumns.get(card.stage)
        if (stageIds) {
          stageIds.push(card.id)
        } else {
          newColumns.set(card.stage, [card.id])
        }
      }

      cards.value = newCards
      columns.value = newColumns
    } catch (e: any) {
      error.value = e?.message ?? 'Failed to load pipeline'
    } finally {
      loading.value = false
    }
  }

  async function moveCard(entityType: string, cardId: string, toStage: string) {
    const card = cards.value.get(cardId)
    if (!card) return

    const fromStage = card.stage

    // Optimistic update
    card.stage = toStage
    card.attributes.stage = toStage

    const fromIds = columns.value.get(fromStage)
    if (fromIds) {
      const idx = fromIds.indexOf(cardId)
      if (idx !== -1) fromIds.splice(idx, 1)
    }

    const toIds = columns.value.get(toStage)
    if (toIds) {
      toIds.push(cardId)
    } else {
      columns.value.set(toStage, [cardId])
    }

    // Trigger reactivity
    cards.value = new Map(cards.value)
    columns.value = new Map(columns.value)

    try {
      await runAction(entityType, 'transition-stage', { id: cardId, stage: toStage })
    } catch {
      // Rollback on failure
      card.stage = fromStage
      card.attributes.stage = fromStage

      const rollbackTo = columns.value.get(toStage)
      if (rollbackTo) {
        const idx = rollbackTo.indexOf(cardId)
        if (idx !== -1) rollbackTo.splice(idx, 1)
      }

      const rollbackFrom = columns.value.get(fromStage)
      if (rollbackFrom) {
        rollbackFrom.push(cardId)
      } else {
        columns.value.set(fromStage, [cardId])
      }

      cards.value = new Map(cards.value)
      columns.value = new Map(columns.value)
    }
  }

  async function applyFilters(entityType: string, filters: PipelineFilters) {
    activeFilters.value = filters
    await loadBoard(entityType)
  }

  async function runCardAction(entityType: string, action: string, payload?: Record<string, unknown>) {
    return runAction(entityType, action, payload)
  }

  return {
    cards,
    columns,
    config,
    loading,
    error,
    loadBoard,
    moveCard,
    applyFilters,
    runCardAction,
  }
}
