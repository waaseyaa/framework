import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { readdir, readFile } from "node:fs/promises";
import { join, basename } from "node:path";

const SPECS_DIR = join(import.meta.dirname, "../docs/specs");
const MAX_SEARCH_MATCHES_PER_SPEC = 10;

let specsCache = null;

async function loadSpecs() {
  if (specsCache) return specsCache;
  const files = await readdir(SPECS_DIR);
  const mdFiles = files.filter((f) => f.endsWith(".md"));
  specsCache = await Promise.all(
    mdFiles.map(async (file) => {
      const name = basename(file, ".md");
      const content = await readFile(join(SPECS_DIR, file), "utf-8");
      const firstLine = content.split("\n").find((l) => l.startsWith("# "));
      const description = firstLine ? firstLine.replace(/^#\s+/, "") : name;
      return { name, description, file, content };
    })
  );
  return specsCache;
}

const server = new McpServer({
  name: "waaseyaa",
  version: "1.0.0",
});

server.registerTool(
  "waaseyaa_list_specs",
  {
    description:
      "List all Waaseyaa framework subsystem specification documents. Use this to discover which specs are available before retrieving one. Returns spec names, descriptions, and file paths.",
  },
  async () => {
    const specs = await loadSpecs();
    const lines = ["# Available Waaseyaa Framework Specs", ""];
    lines.push("| Name | Description | File |");
    lines.push("|------|-------------|------|");
    for (const s of specs) {
      lines.push(`| ${s.name} | ${s.description} | docs/specs/${s.file} |`);
    }
    lines.push("", `Use \`waaseyaa_get_spec\` with a name from the table above to retrieve full content.`);
    return { content: [{ type: "text", text: lines.join("\n") }] };
  }
);

server.registerTool(
  "waaseyaa_get_spec",
  {
    description:
      "Retrieve the full content of a Waaseyaa framework subsystem spec by name. Use this when you need detailed interface signatures, data flows, file maps, or edge cases for a specific subsystem. Use waaseyaa_list_specs to see all available specs.",
    inputSchema: {
      name: z.string().describe("Spec name without .md extension. Use waaseyaa_list_specs to see available names."),
    },
  },
  async ({ name }) => {
    const specs = await loadSpecs();
    const spec = specs.find((s) => s.name === name);
    if (!spec) {
      const available = specs.map((s) => s.name).join(", ");
      return {
        content: [{ type: "text", text: `Spec '${name}' not found. Available specs: ${available}` }],
        isError: true,
      };
    }
    return { content: [{ type: "text", text: spec.content }] };
  }
);

server.registerTool(
  "waaseyaa_search_specs",
  {
    description:
      "Search across all Waaseyaa framework specs by keyword. Use this when you need to find where a specific class, method, config key, or concept is documented across subsystems. Returns matching lines with surrounding context.",
    inputSchema: {
      query: z.string().describe("Keyword or phrase to search for, e.g. 'EntityStorage', 'access-control', 'ServiceProvider'"),
      max_results: z.number().optional().describe("Maximum matches per spec (default: 10)"),
    },
  },
  async ({ query, max_results }) => {
    const limit = max_results ?? MAX_SEARCH_MATCHES_PER_SPEC;
    const specs = await loadSpecs();
    const q = query.toLowerCase();
    const resultLines = [`# Search results for "${query}"`, ""];

    let totalMatches = 0;
    for (const spec of specs) {
      const lines = spec.content.split("\n");
      const matches = [];
      for (let i = 0; i < lines.length && matches.length < limit; i++) {
        if (lines[i].toLowerCase().includes(q)) {
          const start = Math.max(0, i - 2);
          const end = Math.min(lines.length, i + 3);
          matches.push({ line: i + 1, context: lines.slice(start, end).join("\n") });
        }
      }
      if (matches.length > 0) {
        totalMatches += matches.length;
        resultLines.push(`## ${spec.name} (docs/specs/${spec.file})`);
        for (const m of matches) {
          resultLines.push(`### Line ${m.line}`, "```", m.context, "```", "");
        }
      }
    }

    if (totalMatches === 0) {
      return { content: [{ type: "text", text: `No matches found for "${query}" across ${specs.length} specs.` }] };
    }
    resultLines.push(`---`, `${totalMatches} matches across ${specs.length} specs.`);
    return { content: [{ type: "text", text: resultLines.join("\n") }] };
  }
);

const transport = new StdioServerTransport();
await server.connect(transport);
