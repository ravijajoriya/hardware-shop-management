import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { compressImage, fitWithin } from '../images'

// jsdom cannot open or draw pictures, so stand in for <img> and <canvas>: the photo "opens"
// at the given size, and the canvas makes a blob of the given type and size.
function fakeBrowser({ width = 4000, height = 3000, opens = true, makes = {} } = {}) {
  const canvases = []

  vi.stubGlobal(
    'Image',
    class {
      set src(url) {
        this.naturalWidth = width
        this.naturalHeight = height
        setTimeout(() => (opens ? this.onload() : this.onerror()))
      }
    },
  )

  const createElement = document.createElement.bind(document)
  vi.spyOn(document, 'createElement').mockImplementation((tag) => {
    if (tag !== 'canvas') {
      return createElement(tag)
    }

    const context = { fillRect: vi.fn(), drawImage: vi.fn(), fillStyle: null }
    const canvas = {
      context,
      getContext: () => context,
      toBlob(callback, type) {
        const made = makes[type] ?? { type, size: 300 }
        callback(new Blob(['x'.repeat(made.size)], { type: made.type }))
      },
    }
    canvases.push(canvas)

    return canvas
  })

  return canvases
}

function photo(name, type, size) {
  return new File(['x'.repeat(size)], name, { type })
}

describe('fitWithin', () => {
  it('shrinks the longest side to the limit and keeps the shape', () => {
    expect(fitWithin(4000, 3000)).toEqual({ width: 1600, height: 1200 })
    expect(fitWithin(3000, 4000)).toEqual({ width: 1200, height: 1600 })
    expect(fitWithin(1000, 10000, 1000)).toEqual({ width: 100, height: 1000 })
  })

  it('never makes a photo bigger', () => {
    expect(fitWithin(800, 600)).toEqual({ width: 800, height: 600 })
  })
})

describe('compressImage', () => {
  beforeEach(() => {
    vi.stubGlobal('URL', { createObjectURL: () => 'blob:photo', revokeObjectURL: vi.fn() })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('turns a big phone photo into a small WebP of at most 1600 pixels', async () => {
    const canvases = fakeBrowser({ width: 4032, height: 3024 })

    const result = await compressImage(photo('IMG_2041.jpg', 'image/jpeg', 4_000_000))

    expect(result.name).toBe('IMG_2041.webp')
    expect(result.type).toBe('image/webp')
    expect(result.size).toBe(300)
    expect(canvases[0]).toMatchObject({ width: 1600, height: 1200 })
    expect(canvases[0].context.drawImage).toHaveBeenCalledWith(expect.anything(), 0, 0, 1600, 1200)
  })

  it('uses JPEG on white where the browser cannot make WebP', async () => {
    const canvases = fakeBrowser({ makes: { 'image/webp': { type: 'image/png', size: 900 } } })

    const result = await compressImage(photo('cut-out.png', 'image/png', 3_000_000))

    expect(result.name).toBe('cut-out.jpg')
    expect(result.type).toBe('image/jpeg')
    expect(canvases[1].context.fillStyle).toBe('#fff')
    expect(canvases[1].context.fillRect).toHaveBeenCalledWith(0, 0, 1600, 1200)
  })

  it('keeps a small photo that would only get bigger', async () => {
    fakeBrowser({
      width: 800,
      height: 600,
      makes: { 'image/webp': { type: 'image/webp', size: 500 } },
    })
    const original = photo('small.jpg', 'image/jpeg', 400)

    expect(await compressImage(original)).toBe(original)
  })

  it('keeps the original when the photo cannot be opened', async () => {
    fakeBrowser({ opens: false })
    const original = photo('odd.jpg', 'image/jpeg', 5000)

    expect(await compressImage(original)).toBe(original)
  })
})
