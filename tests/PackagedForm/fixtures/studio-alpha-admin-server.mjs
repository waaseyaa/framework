// Serves the ARTIFACT-INSTALLED admin bundle as its own process (#2789).
//
// `waaseyaa/admin-surface` ships a prerendered Nuxt build — `index.html`,
// per-route documents and `_nuxt/*` chunks — and no Nitro server entry, so
// "run the installed admin package" means serving exactly those installed
// bytes over HTTP. This process reads only from the consumer's
// `vendor/waaseyaa/admin-surface/dist`; it never opens the checkout, never
// rewrites a served byte, and holds no fixture, stub or mock.
//
// It forwards the host API prefixes to the artifact-installed backend instead
// of answering them, so the browser keeps ONE origin and the session cookie
// the real backend sets is the session cookie the real backend reads. That
// forwarding is harness plumbing standing in for the same-origin composition
// the shipped host performs itself; it substitutes no source and no payload.
//
// Usage: node studio-alpha-admin-server.mjs DIST_DIR PORT BACKEND_ORIGIN

import { createServer, request as httpRequest } from 'node:http'
import { readFile, stat } from 'node:fs/promises'
import { join, normalize, extname } from 'node:path'

const [distDir, portArgument, backendOrigin] = process.argv.slice(2)
if (!distDir || !portArgument || !backendOrigin) {
  console.error('usage: studio-alpha-admin-server.mjs DIST_DIR PORT BACKEND_ORIGIN')
  process.exit(2)
}
const port = Number(portArgument)
const backend = new URL(backendOrigin)

/** Every prefix the host owns. Anything else is an installed bundle byte. */
const BACKEND_PREFIXES = ['/api/', '/admin/_surface', '/auth/', '/_waaseyaa', '/sse']

const CONTENT_TYPES = new Map(Object.entries({
  '.html': 'text/html; charset=UTF-8',
  '.js': 'application/javascript',
  '.mjs': 'application/javascript',
  '.css': 'text/css',
  '.json': 'application/json',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.map': 'application/json',
  '.txt': 'text/plain; charset=UTF-8',
}))

function isBackendPath(pathname) {
  return BACKEND_PREFIXES.some((prefix) => pathname === prefix.replace(/\/$/, '') || pathname.startsWith(prefix))
}

function forward(clientRequest, clientResponse) {
  const proxied = httpRequest(
    {
      hostname: backend.hostname,
      port: backend.port,
      path: clientRequest.url,
      method: clientRequest.method,
      headers: { ...clientRequest.headers, host: backend.host },
    },
    (backendResponse) => {
      clientResponse.writeHead(backendResponse.statusCode ?? 502, backendResponse.headers)
      backendResponse.pipe(clientResponse)
    },
  )
  proxied.on('error', (error) => {
    console.error(`[admin-server] backend forward failed for ${clientRequest.url}: ${error.message}`)
    clientResponse.writeHead(502, { 'Content-Type': 'text/plain' })
    clientResponse.end('backend unavailable')
  })
  clientRequest.pipe(proxied)
}

async function resolveInstalledFile(pathname) {
  // `/admin/foo` and `/foo` address the same installed document: the bundle is
  // prerendered at the site root, and the host mounts it under /admin.
  const withoutMount = pathname.replace(/^\/admin(?=\/|$)/, '') || '/'
  const candidates = withoutMount.endsWith('/')
    ? [`${withoutMount}index.html`, '/index.html']
    : [withoutMount, `${withoutMount}/index.html`, `${withoutMount}.html`, '/index.html']

  for (const candidate of candidates) {
    const resolved = join(distDir, normalize(candidate).replace(/^(\.\.[/\\])+/, ''))
    if (!resolved.startsWith(distDir)) {
      continue
    }
    try {
      const stats = await stat(resolved)
      if (stats.isFile()) {
        return resolved
      }
    } catch {
      // Try the next candidate.
    }
  }

  return null
}

const server = createServer(async (clientRequest, clientResponse) => {
  const pathname = new URL(clientRequest.url ?? '/', `http://127.0.0.1:${port}`).pathname
  if (isBackendPath(pathname)) {
    forward(clientRequest, clientResponse)

    return
  }

  const file = await resolveInstalledFile(pathname)
  if (file === null) {
    clientResponse.writeHead(404, { 'Content-Type': 'text/plain' })
    clientResponse.end('not found in the installed admin bundle')

    return
  }
  clientResponse.writeHead(200, {
    'Content-Type': CONTENT_TYPES.get(extname(file)) ?? 'application/octet-stream',
  })
  clientResponse.end(await readFile(file))
})

server.listen(port, '127.0.0.1', () => {
  console.log(`[admin-server] serving installed bundle ${distDir} on 127.0.0.1:${port} -> ${backend.origin}`)
})

for (const signal of ['SIGTERM', 'SIGINT']) {
  process.on(signal, () => {
    server.close(() => process.exit(0))
  })
}
