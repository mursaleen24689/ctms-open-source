# Frontend

This React/Vite client talks to the PHP API in `../ctms_server/api`.

## Development

1. Install Node.js and npm versions compatible with `package.json`.
2. Copy `.env.example` to `.env.local` and set `CTMS_PROJECT_PATH` to the path where this repository is served by Apache (for example `/ctms`). Set `CTMS_APACHE_ORIGIN` if Apache is not at `http://localhost`.
3. Install packages with `npm install`.
4. Start the API through Apache/PHP, then run `npm run dev`.

## Production build

Build with `npm run build`. The Vite configuration uses `CTMS_PROJECT_PATH` as the project URL prefix. Serve `dist/` at `<project path>/ctms_frontend/dist/` while keeping `ctms_server/` adjacent to `ctms_frontend/` in the project directory. Set `VITE_CTMS_PROJECT_PATH` during the build if it differs from `/ctms`.

Do not put database, mail, JWT, or other server credentials in frontend environment variables. Any `VITE_` value is compiled into browser-visible JavaScript.
