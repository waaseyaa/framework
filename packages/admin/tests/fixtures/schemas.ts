// packages/admin/tests/fixtures/schemas.ts
import type { EntitySchema } from '~/composables/useSchema'

export const userSchema: EntitySchema = {
  $schema: 'http://json-schema.org/draft-07/schema#',
  title: 'User',
  description: 'A user account',
  type: 'object',
  'x-entity-type': 'user',
  'x-translatable': false,
  'x-revisionable': false,
  properties: {
    uid: {
      type: 'integer',
      readOnly: true,
      'x-weight': -10,
      'x-label': 'ID',
    },
    name: {
      type: 'string',
      'x-widget': 'text',
      'x-label': 'Username',
      'x-weight': 0,
      'x-required': true,
    },
    email: {
      type: 'string',
      format: 'email',
      'x-widget': 'email',
      'x-label': 'Email',
      'x-weight': 1,
      readOnly: true,
      'x-access-restricted': true,
    },
    status: {
      type: 'string',
      'x-widget': 'select',
      'x-label': 'Status',
      'x-weight': 2,
      enum: ['active', 'blocked'],
      'x-enum-labels': { active: 'Active', blocked: 'Blocked' },
    },
  },
  required: ['name'],
}

export const noteSchema: EntitySchema = {
  $schema: 'http://json-schema.org/draft-07/schema#',
  title: 'Note',
  description: 'A note entity',
  type: 'object',
  'x-entity-type': 'note',
  'x-translatable': false,
  'x-revisionable': false,
  properties: {
    id: {
      type: 'integer',
      readOnly: true,
      'x-widget': 'hidden',
      'x-weight': -10,
      'x-label': 'ID',
    },
    title: {
      type: 'string',
      'x-widget': 'text',
      'x-label': 'Title',
      'x-weight': 0,
      'x-required': true,
    },
    body: {
      type: 'string',
      'x-widget': 'textarea',
      'x-label': 'Body',
      'x-weight': 1,
    },
  },
  required: ['title'],
}
