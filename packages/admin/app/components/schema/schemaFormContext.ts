import type { ComputedRef, InjectionKey, Ref } from 'vue'

export type SchemaFormContext = {
  formData: Ref<Record<string, any>>
  isEditMode: ComputedRef<boolean> | Ref<boolean>
  entityType: string
  bundleKey: ComputedRef<string | null>
  selectedBundle: ComputedRef<string | null>
}

export const schemaFormContextKey: InjectionKey<SchemaFormContext> = Symbol('schemaFormContext')
