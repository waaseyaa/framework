import { createServer, get } from 'node:http'
import { once } from 'node:events'
import { describe, expect, it } from 'vitest'

describe('Nuxt DOM passive resource boundary', () => {
  it('retains normal DOM interaction without fetching markup-controlled iframe or stylesheet URLs', async () => {
    const hits: string[] = []
    const server = createServer((request, response) => {
      hits.push(request.url ?? '')
      response.setHeader('Content-Type', request.url?.endsWith('.css') ? 'text/css' : 'text/html')
      response.end('local fixture')
    })
    server.listen(0, '127.0.0.1')
    await once(server, 'listening')
    const address = server.address()
    if (!address || typeof address === 'string') throw new Error('Missing loopback fixture address')
    const origin = `http://127.0.0.1:${address.port}`
    const stylesheet = document.createElement('link')
    const frame = document.createElement('iframe')
    const button = document.createElement('button')
    const browser = window as unknown as { happyDOM: { waitUntilComplete: () => Promise<void> } }
    try {
      await new Promise<void>((resolve, reject) => {
        get(`${origin}/control`, (response) => {
          response.resume()
          response.once('end', resolve)
        }).once('error', reject)
      })
      expect(hits).toEqual(['/control'])
      hits.length = 0
      stylesheet.rel = 'stylesheet'
      stylesheet.href = `${origin}/passive.css`
      frame.src = `${origin}/passive-frame`
      document.head.append(stylesheet)
      document.body.append(frame, button)
      let clicked = false
      button.addEventListener('click', () => { clicked = true })
      button.click()
      await browser.happyDOM.waitUntilComplete()
      expect(clicked).toBe(true)
      expect(hits).toEqual([])
    } finally {
      stylesheet.remove()
      frame.remove()
      button.remove()
      server.closeAllConnections()
      await new Promise<void>((resolve) => { server.close(() => resolve()) })
    }
  })
})
