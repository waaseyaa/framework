<script setup lang="ts">
import type { ValidationReport } from '~/composables/useCodifiedContext'
import { useLanguage } from '~/composables/useLanguage'

defineProps<{ report: ValidationReport | null }>()

const { t } = useLanguage()

function severityClass(severity: string): string {
  switch (severity) {
    case 'critical': return 'issue--critical'
    case 'high': return 'issue--high'
    case 'medium': return 'issue--medium'
    case 'low': return 'issue--low'
    default: return ''
  }
}

function scoreColor(score: number): string {
  if (score >= 75) return '#16a34a'
  if (score >= 50) return '#ca8a04'
  if (score >= 25) return '#ea580c'
  return '#dc2626'
}
</script>

<template>
  <div v-if="report === null" class="empty">No validation report available.</div>

  <div v-else class="validation-card">
    <div class="score-row">
      <span class="score-label">{{ t('telescope_cc_drift_score') }}</span>
      <span class="score-value" :style="{ color: scoreColor(report.driftScore) }">
        {{ report.driftScore }}
      </span>
    </div>

    <div class="components">
      <div class="component-row">
        <span class="component-label">{{ t('telescope_cc_semantic') }}</span>
        <div class="progress-track">
          <div
            class="progress-bar"
            :style="{ width: `${report.components.semantic_alignment}%`, background: scoreColor(report.components.semantic_alignment) }"
          />
        </div>
        <span class="component-value">{{ report.components.semantic_alignment }}</span>
      </div>
      <div class="component-row">
        <span class="component-label">{{ t('telescope_cc_structural') }}</span>
        <div class="progress-track">
          <div
            class="progress-bar"
            :style="{ width: `${report.components.structural_checks}%`, background: scoreColor(report.components.structural_checks) }"
          />
        </div>
        <span class="component-value">{{ report.components.structural_checks }}</span>
      </div>
      <div class="component-row">
        <span class="component-label">{{ t('telescope_cc_contradictions') }}</span>
        <div class="progress-track">
          <div
            class="progress-bar"
            :style="{ width: `${report.components.contradiction_checks}%`, background: scoreColor(report.components.contradiction_checks) }"
          />
        </div>
        <span class="component-value">{{ report.components.contradiction_checks }}</span>
      </div>
    </div>

    <div v-if="report.issues.length > 0" class="issues">
      <h3>Issues</h3>
      <ul>
        <li
          v-for="(issue, i) in report.issues"
          :key="i"
          class="issue"
          :class="severityClass(issue.severity)"
        >
          <span class="issue-type">{{ issue.type }}</span>
          <span class="issue-msg">{{ issue.message }}</span>
        </li>
      </ul>
    </div>

    <div class="recommendation">
      <strong>Recommendation:</strong> {{ report.recommendation }}
    </div>
  </div>
</template>

<style scoped>
.validation-card { display: flex; flex-direction: column; gap: 1rem; }
.score-row { display: flex; align-items: center; gap: 0.5rem; }
.score-label { font-weight: 600; color: #374151; }
.score-value { font-size: 1.5rem; font-weight: 700; }
.components { display: flex; flex-direction: column; gap: 0.5rem; }
.component-row { display: grid; grid-template-columns: 12rem 1fr 2.5rem; align-items: center; gap: 0.5rem; }
.component-label { font-size: 0.875rem; color: #6b7280; }
.component-value { text-align: right; font-size: 0.875rem; font-weight: 600; }
.progress-track { height: 0.5rem; background: #f3f4f6; border-radius: 0.25rem; overflow: hidden; }
.progress-bar { height: 100%; border-radius: 0.25rem; transition: width 0.3s ease; }
.issues h3 { font-size: 0.9rem; font-weight: 600; margin: 0 0 0.5rem; }
.issues ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.25rem; }
.issue { display: flex; gap: 0.5rem; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.85rem; }
.issue--critical { background: #fee2e2; color: #991b1b; }
.issue--high     { background: #ffedd5; color: #9a3412; }
.issue--medium   { background: #dbeafe; color: #1e40af; }
.issue--low      { background: #dcfce7; color: #166534; }
.issue-type { font-weight: 600; min-width: 6rem; }
.recommendation { font-size: 0.875rem; padding: 0.5rem; background: #f9fafb; border-radius: 0.25rem; border-left: 3px solid #6b7280; }
.empty { color: #9ca3af; }
</style>
