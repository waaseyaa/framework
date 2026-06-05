<script setup lang="ts">
const props = defineProps<{
  schema: Record<string, unknown>
}>()

const { t } = useLanguage()

function renderNode(node: unknown, depth = 0): string {
  if (node === null) return 'null'
  if (typeof node !== 'object') return String(node)
  return JSON.stringify(node, null, 2)
}

function isObject(val: unknown): val is Record<string, unknown> {
  return typeof val === 'object' && val !== null && !Array.isArray(val)
}

function schemaProperties(node: Record<string, unknown>): [string, unknown][] {
  if (isObject(node.properties)) {
    return Object.entries(node.properties as Record<string, unknown>)
  }
  return []
}
</script>

<template>
  <div class="input-schema-viewer">
    <details class="border rounded p-3">
      <summary class="cursor-pointer font-medium text-sm select-none">
        {{ t('mcp_tool_detail_input_schema') }}
      </summary>
      <div class="mt-3 pl-2 text-sm font-mono">
        <template v-if="schemaProperties(props.schema).length > 0">
          <details
            v-for="[propName, propSchema] in schemaProperties(props.schema)"
            :key="propName"
            class="border-l pl-3 mb-1"
          >
            <summary class="cursor-pointer select-none text-teal-700">{{ propName }}</summary>
            <pre class="mt-1 text-xs text-gray-700 whitespace-pre-wrap">{{ renderNode(propSchema) }}</pre>
          </details>
        </template>
        <pre v-else class="text-xs text-gray-700 whitespace-pre-wrap">{{ renderNode(props.schema) }}</pre>
      </div>
    </details>
  </div>
</template>
