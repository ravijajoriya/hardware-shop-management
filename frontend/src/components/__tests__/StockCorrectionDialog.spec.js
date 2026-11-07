import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import StockCorrectionDialog from '../StockCorrectionDialog.vue'

HTMLDialogElement.prototype.showModal ??= function () {
  this.open = true
}

const metre = { name: 'Metre', short_name: 'm', allow_decimal: true }
const coil = { unit: { name: 'Coil', short_name: 'coil' }, size: 90 }
const variant = { id: 50, name: '1 mm', stock_quantity: 535 }

function jsonResponse(status, body) {
  return new Response(JSON.stringify(body), { status })
}

describe('StockCorrectionDialog', () => {
  let fetchMock

  beforeEach(() => {
    localStorage.setItem('admin_token', 'abc123')
    setActivePinia(createPinia())
    fetchMock = vi.fn().mockResolvedValue(jsonResponse(201, { data: { id: 1 } }))
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    localStorage.clear()
  })

  it('counts a product bought in packs as on the shelf: full coils plus loose metres', async () => {
    const wrapper = mount(StockCorrectionDialog, {
      props: { productName: 'Copper Wire', variant, unit: metre, pack: coil },
    })

    expect(wrapper.text()).toContain('The count shows 5 coils + 85 m.')
    expect(wrapper.get('#correct-packs').element.value).toBe('5')
    expect(wrapper.get('#correct-loose').element.value).toBe('85')

    await wrapper.get('#correct-packs').setValue('4')
    await wrapper.get('#correct-loose').setValue('30')

    // 4 coils and 30 m are 390 m: 145 m fewer than the count showed.
    expect(wrapper.text()).toContain('−145 m from the count shown.')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
      quantity: 390,
      reason: 'counted',
      note: null,
    })
  })

  it('takes one count in the unit for a product sold one way', () => {
    const wrapper = mount(StockCorrectionDialog, {
      props: {
        productName: 'Claw Hammer',
        variant: { id: 7, name: null, stock_quantity: 12 },
        unit: { name: 'Piece', short_name: 'pc', allow_decimal: false },
      },
    })

    expect(wrapper.find('#correct-packs').exists()).toBe(false)
    expect(wrapper.get('#correct-quantity').element.value).toBe('12')
  })
})
