/**
 * Compile-time proof that the Admin SPA mirrors the canonical host contract.
 *
 * Exact equality is intentional: ordinary TypeScript assignability permits an
 * optional field to disappear from one side, which is the drift this gate is
 * meant to catch.
 */
import type * as Canonical from '../admin-surface/contract/types'
import type * as Spa from './app/contracts/adminSurface'
import type * as SpaPageBuilder from './app/contracts/pageBuilder'
import type * as SpaRevisions from './app/contracts/revisions'
import type * as SpaSchema from './app/contracts/schema'
import type * as SpaUi from './app/contracts/surface-ui'

type Exact<Left, Right> =
  (<Value>() => Value extends Left ? 1 : 2) extends
    (<Value>() => Value extends Right ? 1 : 2)
    ? (<Value>() => Value extends Right ? 1 : 2) extends
        (<Value>() => Value extends Left ? 1 : 2)
      ? true
      : false
    : false

type Assert<Condition extends true> = Condition

type CoreContractCompatibility = [
  Assert<Exact<Canonical.AdminSurfaceSession, Spa.AdminSurfaceSession>>,
  Assert<Exact<Canonical.AdminSurfaceAccount, Spa.AdminSurfaceAccount>>,
  Assert<Exact<Canonical.AdminSurfaceTenant, Spa.AdminSurfaceTenant>>,
  Assert<Exact<Canonical.AdminSurfaceCatalog, Spa.AdminSurfaceCatalog>>,
  Assert<Exact<Canonical.AdminSurfaceCatalogEntry, Spa.AdminSurfaceCatalogEntry>>,
  Assert<Exact<Canonical.AdminSurfaceReferenceMetadata, Spa.AdminSurfaceReferenceMetadata>>,
  Assert<Exact<Canonical.AdminSurfaceCapabilities, Spa.AdminSurfaceCapabilities>>,
  Assert<Exact<Canonical.AdminSurfaceField, Spa.AdminSurfaceField>>,
  Assert<Exact<Canonical.AdminSurfaceAction, Spa.AdminSurfaceAction>>,
  Assert<Exact<Canonical.AdminSurfaceEntity, Spa.AdminSurfaceEntity>>,
  Assert<Exact<Canonical.AdminSurfaceResult<unknown>, Spa.AdminSurfaceResult<unknown>>>,
  Assert<Exact<Canonical.AdminSurfaceSaveAdvisory, Spa.AdminSurfaceSaveAdvisory>>,
  Assert<Exact<Canonical.AdminSurfaceErrorMeta, Spa.AdminSurfaceErrorMeta>>,
  Assert<Exact<Canonical.AdminSurfaceError, Spa.AdminSurfaceError>>,
  Assert<Exact<Canonical.AdminSurfaceListQuery, Spa.AdminSurfaceListQuery>>,
  Assert<Exact<Canonical.AdminSurfaceListResult, Spa.AdminSurfaceListResult>>,
  Assert<Exact<Canonical.AdminSurfaceCreateRequest, Spa.AdminSurfaceCreateRequest>>,
  Assert<Exact<Canonical.AdminSurfaceUpdateRequest, Spa.AdminSurfaceUpdateRequest>>,
  Assert<Exact<Canonical.AdminSurfaceDeleteRequest, Spa.AdminSurfaceDeleteRequest>>,
  Assert<Exact<Canonical.AdminSurfaceDeleteData, Spa.AdminSurfaceDeleteData>>,
  Assert<Exact<Canonical.AdminSurfaceGenerateSlugRequest, Spa.AdminSurfaceGenerateSlugRequest>>,
  Assert<Exact<Canonical.AdminSurfaceGenerateSlugData, Spa.AdminSurfaceGenerateSlugData>>,
]

type UiContractCompatibility = [
  Assert<Exact<Canonical.AdminSurfaceHeaderLink, SpaUi.AdminSurfaceHeaderLink>>,
  Assert<Exact<Canonical.AdminSurfaceSidebarItem, SpaUi.AdminSurfaceSidebarItem>>,
  Assert<Exact<Canonical.AdminSurfaceUiCustomization, SpaUi.AdminSurfaceUiCustomization>>,
]

type SchemaContractCompatibility = [
  Assert<Exact<Canonical.AdminSurfaceSchemaRequest, SpaSchema.AdminSurfaceSchemaRequest>>,
  Assert<Exact<Canonical.AdminSurfaceSchemaProperty, SpaSchema.SchemaProperty>>,
  Assert<Exact<Canonical.AdminSurfaceFormSection, SpaSchema.FormSection>>,
  Assert<Exact<Canonical.AdminSurfaceEntitySchema, SpaSchema.EntitySchema>>,
]

type RevisionContractCompatibility = [
  Assert<Exact<Canonical.AdminSurfaceHistoryRequest, SpaRevisions.AdminSurfaceHistoryRequest>>,
  Assert<Exact<Canonical.AdminSurfaceRevisionRequest, SpaRevisions.AdminSurfaceRevisionRequest>>,
  Assert<Exact<Canonical.AdminSurfaceRestoreRevisionRequest, SpaRevisions.AdminSurfaceRestoreRevisionRequest>>,
  Assert<Exact<Canonical.AdminSurfaceRevisionEntry, SpaRevisions.AdminSurfaceRevisionEntry>>,
  Assert<Exact<Canonical.AdminSurfaceHistoryData, SpaRevisions.AdminSurfaceHistoryData>>,
  Assert<Exact<Canonical.AdminSurfaceRevisionEntity, SpaRevisions.AdminSurfaceRevisionEntity>>,
  Assert<Exact<Canonical.AdminSurfaceRevisionData, SpaRevisions.AdminSurfaceRevisionData>>,
  Assert<Exact<Canonical.AdminSurfaceRevisionPreviewData, SpaRevisions.AdminSurfaceRevisionPreviewData>>,
  Assert<Exact<Canonical.AdminSurfaceRestoreRevisionData, SpaRevisions.AdminSurfaceRestoreRevisionData>>,
]

type PageBuilderContractCompatibility = [
  Assert<Exact<Canonical.PageBuilderBlockDefinition, SpaPageBuilder.PageBuilderBlockDefinition>>,
  Assert<Exact<Canonical.PageBuilderLayoutDefinition, SpaPageBuilder.PageBuilderLayoutDefinition>>,
  Assert<Exact<Canonical.PageBuilderTemplateDefinition, SpaPageBuilder.PageBuilderTemplateDefinition>>,
  Assert<Exact<Canonical.PageBuilderDefinitions, SpaPageBuilder.PageBuilderDefinitions>>,
  Assert<Exact<Canonical.PageBuilderDefinitionsData, SpaPageBuilder.PageBuilderDefinitionsData>>,
  Assert<Exact<Canonical.PageBuilderBlock, SpaPageBuilder.PageBuilderBlock>>,
  Assert<Exact<Canonical.PageBuilderSection, SpaPageBuilder.PageBuilderSection>>,
  Assert<Exact<Canonical.PageBuilderDocument, SpaPageBuilder.PageBuilderDocument>>,
  Assert<Exact<Canonical.PageBuilderDraft, SpaPageBuilder.PageBuilderDraft>>,
  Assert<Exact<Canonical.PageBuilderPreview, SpaPageBuilder.PageBuilderPreview>>,
  Assert<Exact<Canonical.PageBuilderRevision, SpaPageBuilder.PageBuilderRevision>>,
  Assert<Exact<Canonical.PageBuilderHistoryData, SpaPageBuilder.PageBuilderHistoryData>>,
  Assert<Exact<Canonical.PageBuilderCommand, SpaPageBuilder.PageBuilderCommand>>,
  Assert<Exact<Canonical.PageBuilderCommandRequest, SpaPageBuilder.PageBuilderCommandRequest>>,
  Assert<Exact<Canonical.PageBuilderPreviewRequest, SpaPageBuilder.PageBuilderPreviewRequest>>,
  Assert<Exact<Canonical.PageBuilderRestoreRequest, SpaPageBuilder.PageBuilderRestoreRequest>>,
  Assert<Exact<Canonical.PageBuilderSurfaceError, SpaPageBuilder.PageBuilderSurfaceError>>,
  Assert<Exact<Canonical.PageBuilderSurfaceResult<unknown>, SpaPageBuilder.PageBuilderSurfaceResult<unknown>>>,
]

export type AdminSurfaceContractCompatibility = [
  CoreContractCompatibility,
  UiContractCompatibility,
  SchemaContractCompatibility,
  RevisionContractCompatibility,
  PageBuilderContractCompatibility,
]
