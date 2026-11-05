import process from 'node:process'
import { fileURLToPath, URL } from 'node:url'

import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'

// The browser may only run the app's own scripts and only talk to the app and its API, so
// even text slipped into a page can't run code or send the login token anywhere. Put into
// the built index.html, which works on any host; not while developing, where Vite needs a
// live-reload connection.
function contentSecurityPolicy(apiUrl) {
  const api = new URL(apiUrl).origin
  const policy = [
    "default-src 'self'",
    "script-src 'self'",
    "style-src 'self' 'unsafe-inline'",
    // Product photos come from the API's address; previews of chosen photos are blob: URLs.
    `img-src 'self' data: blob: ${api}`,
    `connect-src 'self' ${api}`,
    "font-src 'self'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
  ].join('; ')

  return {
    name: 'content-security-policy',
    apply: 'build',
    transformIndexHtml: () => [
      {
        tag: 'meta',
        attrs: { 'http-equiv': 'Content-Security-Policy', content: policy },
        injectTo: 'head-prepend',
      },
    ],
  }
}

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_')

  return {
    plugins: [vue(), contentSecurityPolicy(env.VITE_API_URL ?? 'http://localhost:8000/api')],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
  }
})
