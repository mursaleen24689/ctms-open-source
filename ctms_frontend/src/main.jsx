import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.jsx'

// ============================================================
// main.jsx — CTMS Entry Point
//
// IMPORTANT: This file intentionally does NOT import './index.css'
// or './App.css'. Those files are the default Vite scaffold
// stylesheets, and they set rules like:
//
//   #root { max-width: 1280px; margin: 0 auto; padding: 2rem; }
//
// That single rule is what was causing the entire dashboard to
// render shrunk and centered with blank space on both sides —
// it was fighting against the full-screen grid layout defined
// in Dashboard.jsx and index.html.
//
// All of CTMS's styling lives in:
//   - index.html   (global reset, fonts, animations, scrollbars)
//   - App.jsx       (inline styles for the login screen)
//   - Dashboard.jsx (inline styles for the app shell, via the `S` object)
//
// If you have existing src/index.css or src/App.css files left
// over from the original `npm create vite` scaffold, you can
// safely delete them — they are not used anywhere.
// ============================================================

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
)
