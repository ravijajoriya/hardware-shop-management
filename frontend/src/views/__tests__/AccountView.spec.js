import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import AccountView from '../AccountView.vue'
import { useAuthStore } from '@/stores/auth'

function jsonResponse(status, body) {
  return new Response(JSON.stringify(body), { status })
}

async function fillAndSave(wrapper, current, password, again = password) {
  await wrapper.get('#current-password').setValue(current)
  await wrapper.get('#new-password').setValue(password)
  await wrapper.get('#new-password-again').setValue(again)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

describe('AccountView', () => {
  let fetchMock

  beforeEach(() => {
    localStorage.setItem('admin_token', 'abc123')
    setActivePinia(createPinia())
    useAuthStore().user = { id: 1, name: 'Ravi', email: 'owner@myshop.in' }
    fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    localStorage.clear()
  })

  it('shows who is logged in', () => {
    const wrapper = mount(AccountView)

    expect(wrapper.get('.who').text()).toContain('Ravi')
    expect(wrapper.get('.who').text()).toContain('owner@myshop.in')
  })

  it('changes the password, clears the form and says the other devices were logged out', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { data: { other_devices_logged_out: 2 } }))
    const wrapper = mount(AccountView)

    await fillAndSave(wrapper, 'old-password-1', 'new-password-22')

    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
      current_password: 'old-password-1',
      password: 'new-password-22',
      password_confirmation: 'new-password-22',
    })
    expect(wrapper.get('.alert-success').text()).toBe(
      'Password changed. You were logged out on 2 other devices.',
    )
    expect(wrapper.get('#current-password').element.value).toBe('')
    expect(wrapper.get('#new-password').element.value).toBe('')
  })

  it('shows what is wrong next to each box', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(422, {
        message: 'This is not your current password.',
        errors: {
          current_password: ['This is not your current password.'],
          password: ['Use at least 10 characters.'],
        },
      }),
    )
    const wrapper = mount(AccountView)

    await fillAndSave(wrapper, 'guess', 'short')

    const errors = wrapper.findAll('.field-error').map((error) => error.text())
    expect(errors).toEqual(['This is not your current password.', 'Use at least 10 characters.'])
    expect(wrapper.find('.alert-success').exists()).toBe(false)
    expect(wrapper.find('.alert-error').exists()).toBe(false)
  })

  it('shows the wait message when there were too many tries', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(429, { message: 'Too many requests. Please wait 1 minute and try again.' }),
    )
    const wrapper = mount(AccountView)

    await fillAndSave(wrapper, 'old-password-1', 'new-password-22')

    expect(wrapper.get('.alert-error').text()).toBe(
      'Too many requests. Please wait 1 minute and try again.',
    )
  })
})
