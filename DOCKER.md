# Backend Docker Setup

This runs the Laravel backend in a containerized dev environment so every developer uses the same PHP, Composer, Node, and npm versions.

## Files

- `Dockerfile`
- `docker-compose.backend.yml`
- `.env.docker.example`

## First-time setup

1. Copy `.env.docker.example` to `.env.docker`
2. Fill in the real database password and any other app secrets
3. Start the backend container:

```bash
docker compose -f docker-compose.backend.yml up -d --build
```

## App URLs

- Laravel app: `http://localhost:8000`
- Vite dev port reserved: `http://localhost:5173`

## Common commands

Start:

```bash
docker compose -f docker-compose.backend.yml up -d --build
```

Stop:

```bash
docker compose -f docker-compose.backend.yml down
```

Logs:

```bash
docker compose -f docker-compose.backend.yml logs -f
```

Run migrations:

```bash
docker compose -f docker-compose.backend.yml exec backend php artisan migrate
```

Run Vite manually when needed:

```bash
docker compose -f docker-compose.backend.yml exec backend npm run dev -- --host 0.0.0.0
```

## Notes

- Source code is mounted into the container for live development
- `vendor` and `node_modules` stay inside Docker volumes to reduce host compatibility issues
- `.env.docker` should stay local and out of git
