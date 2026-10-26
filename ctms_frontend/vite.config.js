import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

const projectPath = (process.env.CTMS_PROJECT_PATH || '/ctms').replace(/\/+$/, '')
const apacheOrigin = process.env.CTMS_APACHE_ORIGIN || 'http://localhost'

export default defineConfig(({ command }) => ({
  plugins: [react()],
  base: command === 'build' ? `${projectPath}/ctms_frontend/dist/` : '/',
  build: {
    // Keep the bundle compatible with common Chromium versions on Windows.
    target: 'chrome109',
  },
  server: {
    proxy: {
      '/ctms_api': {
        target: apacheOrigin,
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/ctms_api/, `${projectPath}/ctms_server/api`),
      },
      '/ctms_assets': {
        target: apacheOrigin,
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/ctms_assets/, `${projectPath}/ctms_server/assets`),
      },
    },
  },
}))
