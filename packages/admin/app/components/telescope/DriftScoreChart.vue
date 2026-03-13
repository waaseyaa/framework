<script setup lang="ts">
const props = defineProps<{ score: number }>()

const barColor = computed(() => {
  if (props.score >= 75) return '#16a34a'
  if (props.score >= 50) return '#ca8a04'
  if (props.score >= 25) return '#ea580c'
  return '#dc2626'
})

const barWidth = computed(() => `${Math.max(0, Math.min(100, props.score))}%`)
</script>

<template>
  <div class="drift-chart">
    <div class="drift-track">
      <div
        class="drift-bar"
        :style="{ width: barWidth, background: barColor }"
        role="progressbar"
        :aria-valuenow="score"
        aria-valuemin="0"
        aria-valuemax="100"
      />
    </div>
    <span class="drift-label" :style="{ color: barColor }">{{ score }}</span>
  </div>
</template>

<style scoped>
.drift-chart {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
.drift-track {
  flex: 1;
  height: 1rem;
  background: #f3f4f6;
  border-radius: 0.5rem;
  overflow: hidden;
}
.drift-bar {
  height: 100%;
  border-radius: 0.5rem;
  transition: width 0.3s ease;
}
.drift-label {
  font-size: 1.25rem;
  font-weight: 700;
  min-width: 2.5rem;
  text-align: right;
}
</style>
