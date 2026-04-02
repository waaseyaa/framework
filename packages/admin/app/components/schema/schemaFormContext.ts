import type { ComputedRef, InjectionKey, Ref } from 'vue'

export type SchemaFormContext = {
  formData: Ref<Record<string, any>>
  isEditMode: ComputedRef<boolean> | Ref<boolean>
}

export const schemaFormContextKey: InjectionKey<SchemaFormContext> = Symbol('schemaFormContext')
