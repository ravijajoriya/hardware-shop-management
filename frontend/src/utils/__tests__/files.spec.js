import { describe, it, expect, afterEach, vi } from 'vitest'

import { canShareFile, downloadFile } from '../files'

const pdf = new File(['%PDF-1.7'], 'INV-2026-0007.pdf', { type: 'application/pdf' })

function allowSharing(canShare) {
  Object.defineProperty(navigator, 'share', { value: vi.fn(), configurable: true })
  Object.defineProperty(navigator, 'canShare', { value: vi.fn(canShare), configurable: true })
}

describe('canShareFile', () => {
  afterEach(() => {
    delete navigator.share
    delete navigator.canShare
  })

  it('is false where the browser has no share menu', () => {
    expect(canShareFile(pdf)).toBe(false)
  })

  it('asks the browser whether it can share this file', () => {
    allowSharing(() => true)

    expect(canShareFile(pdf)).toBe(true)
    expect(navigator.canShare).toHaveBeenCalledWith({ files: [pdf] })
  })

  it('is false when the browser can share, but not files', () => {
    allowSharing(() => false)

    expect(canShareFile(pdf)).toBe(false)
  })
})

describe('downloadFile', () => {
  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
    delete URL.createObjectURL
    delete URL.revokeObjectURL
  })

  it('saves the file under its own name and frees its address afterwards', () => {
    vi.useFakeTimers()
    URL.createObjectURL = vi.fn(() => 'blob:bill')
    URL.revokeObjectURL = vi.fn()
    const clicked = []
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
      clicked.push({ href: this.href, download: this.download, attached: this.isConnected })
    })

    downloadFile(pdf)

    expect(URL.createObjectURL).toHaveBeenCalledWith(pdf)
    expect(clicked).toEqual([{ href: 'blob:bill', download: 'INV-2026-0007.pdf', attached: true }])
    expect(document.querySelector('a[download]')).toBeNull()

    vi.advanceTimersByTime(10_000)

    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:bill')
  })
})
