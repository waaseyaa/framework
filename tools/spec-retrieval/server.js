import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  ListToolsRequestSchema,
  CallToolRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_SPECS_DIR = path.resolve(__dirname, '../../docs/specs');

// ---------------------------------------------------------------------------
// Core functions (exported for testing)
// ---------------------------------------------------------------------------

/**
 * Parse a markdown spec file into {name, description, file}.
 * Name comes from the first `# Title` line.
 * Description comes from the first paragraph under `## Overview`, or the
 * first non-heading paragraph if there is no Overview section.
 */
function parseSpec(content, filename) {
  const lines = content.split('\n');

  // Extract name from first H1.
  let name = filename.replace(/\.md$/, '');
  for (const line of lines) {
    if (line.startsWith('# ')) {
      name = line.slice(2).trim();
      break;
    }
  }

  // Extract description: prefer ## Overview section, else first paragraph.
  let description = '';
  let inOverview = false;

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];

    if (/^## Overview/i.test(line)) {
      inOverview = true;
      continue;
    }

    if (inOverview) {
      if (line.startsWith('## ')) {
        // Left the Overview section.
        break;
      }
      const trimmed = line.trim();
      if (trimmed && !trimmed.startsWith('#')) {
        description = trimmed;
        break;
      }
      continue;
    }
  }

  // Fallback: first non-empty, non-heading line after the title.
  if (!description) {
    let pastTitle = false;
    for (const line of lines) {
      if (line.startsWith('# ')) {
        pastTitle = true;
        continue;
      }
      if (pastTitle) {
        const trimmed = line.trim();
        if (trimmed && !trimmed.startsWith('#')) {
          description = trimmed;
          break;
        }
      }
    }
  }

  return { name, description, file: filename };
}

/**
 * List all specs in the given directory.
 */
export async function listSpecs(specsDir = DEFAULT_SPECS_DIR) {
  let entries;
  try {
    entries = await fs.readdir(specsDir);
  } catch {
    return [];
  }

  const mdFiles = entries.filter((f) => f.endsWith('.md')).sort();
  const results = [];

  for (const file of mdFiles) {
    const content = await fs.readFile(path.join(specsDir, file), 'utf-8');
    results.push(parseSpec(content, file));
  }

  return results;
}

/**
 * Get the full markdown content of a spec by name (filename without extension).
 */
export async function getSpec(name, specsDir = DEFAULT_SPECS_DIR) {
  const filename = name.endsWith('.md') ? name : `${name}.md`;
  const filePath = path.join(specsDir, filename);

  try {
    return await fs.readFile(filePath, 'utf-8');
  } catch {
    throw new Error(`Spec not found: ${name}`);
  }
}

/**
 * Search across all specs for sections matching a query (case-insensitive
 * substring). Returns an array of {file, section, content} objects.
 */
export async function searchSpecs(query, specsDir = DEFAULT_SPECS_DIR) {
  let entries;
  try {
    entries = await fs.readdir(specsDir);
  } catch {
    return [];
  }

  const mdFiles = entries.filter((f) => f.endsWith('.md')).sort();
  const lowerQuery = query.toLowerCase();
  const results = [];

  for (const file of mdFiles) {
    const content = await fs.readFile(path.join(specsDir, file), 'utf-8');
    const sections = splitIntoSections(content);

    for (const section of sections) {
      if (section.content.toLowerCase().includes(lowerQuery)) {
        results.push({
          file,
          section: section.heading,
          content: section.content,
        });
      }
    }
  }

  return results;
}

/**
 * Split markdown content into sections by headings.
 * Each section includes its heading and all content until the next heading
 * of equal or higher level.
 */
function splitIntoSections(content) {
  const lines = content.split('\n');
  const sections = [];
  let currentHeading = '(top)';
  let currentLines = [];

  for (const line of lines) {
    if (/^#{1,6}\s/.test(line)) {
      // Save previous section if it has content.
      if (currentLines.length > 0) {
        sections.push({
          heading: currentHeading,
          content: currentLines.join('\n').trim(),
        });
      }
      currentHeading = line.replace(/^#+\s*/, '').trim();
      currentLines = [line];
    } else {
      currentLines.push(line);
    }
  }

  // Save last section.
  if (currentLines.length > 0) {
    sections.push({
      heading: currentHeading,
      content: currentLines.join('\n').trim(),
    });
  }

  return sections;
}

// ---------------------------------------------------------------------------
// MCP Server (only starts when run directly, not when imported for tests)
// ---------------------------------------------------------------------------

const isMainModule =
  process.argv[1] &&
  path.resolve(process.argv[1]) ===
    path.resolve(fileURLToPath(import.meta.url));

if (isMainModule) {
  const server = new Server(
    { name: 'waaseyaa-specs', version: '1.0.0' },
    { capabilities: { tools: {} } }
  );

  server.setRequestHandler(ListToolsRequestSchema, async () => ({
    tools: [
      {
        name: 'waaseyaa_list_specs',
        description:
          'List all Waaseyaa subsystem specs. Returns an array of {name, description, file} objects.',
        inputSchema: {
          type: 'object',
          properties: {},
          required: [],
        },
      },
      {
        name: 'waaseyaa_get_spec',
        description:
          'Get the full markdown content of a named Waaseyaa subsystem spec.',
        inputSchema: {
          type: 'object',
          properties: {
            name: {
              type: 'string',
              description:
                'Spec name (filename without .md extension, e.g. "mcp-endpoint")',
            },
          },
          required: ['name'],
        },
      },
      {
        name: 'waaseyaa_search_specs',
        description:
          'Search across all Waaseyaa specs for sections matching a keyword. Returns matching sections with headings and content.',
        inputSchema: {
          type: 'object',
          properties: {
            query: {
              type: 'string',
              description: 'Search query (case-insensitive substring match)',
            },
          },
          required: ['query'],
        },
      },
    ],
  }));

  server.setRequestHandler(CallToolRequestSchema, async (request) => {
    const { name, arguments: args } = request.params;

    switch (name) {
      case 'waaseyaa_list_specs': {
        const specs = await listSpecs();
        return {
          content: [{ type: 'text', text: JSON.stringify(specs, null, 2) }],
        };
      }

      case 'waaseyaa_get_spec': {
        if (!args?.name) {
          return {
            content: [
              { type: 'text', text: 'Error: missing required parameter "name"' },
            ],
            isError: true,
          };
        }
        try {
          const content = await getSpec(args.name);
          return { content: [{ type: 'text', text: content }] };
        } catch (err) {
          return {
            content: [{ type: 'text', text: `Error: ${err.message}` }],
            isError: true,
          };
        }
      }

      case 'waaseyaa_search_specs': {
        if (!args?.query) {
          return {
            content: [
              {
                type: 'text',
                text: 'Error: missing required parameter "query"',
              },
            ],
            isError: true,
          };
        }
        const results = await searchSpecs(args.query);
        return {
          content: [{ type: 'text', text: JSON.stringify(results, null, 2) }],
        };
      }

      default:
        return {
          content: [{ type: 'text', text: `Unknown tool: ${name}` }],
          isError: true,
        };
    }
  });

  const transport = new StdioServerTransport();
  await server.connect(transport);
}
