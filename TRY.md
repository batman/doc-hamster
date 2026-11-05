# Try DocHamster in 2 minutes (Docker)

You need only Docker. No PHP, no Composer, no database, no hosting.

```sh
git clone https://github.com/batman/doc-hamster
cd doc-hamster
docker compose up -d --build
```

First boot provisions itself (`.env`, dependencies, app key, tables, demo
owner) and takes a few minutes. Then open in your browser:

**http://localhost:8080 — log in as `owner` / `hamster-demo-01`**

What this is: a frictionless way to *test* DocHamster. It is not the product
install path — real installations use `install.php` on hosting (see README).

Useful commands:

- `docker compose logs -f app` — watch the first-boot provisioning
- `docker compose down` — stop (demo data is kept)
- `docker compose down -v` — full reset (wipes the demo database; next `up` reprovisions)
- Custom demo login: `TRY_USER=me TRY_PASSWORD=a-long-demo-password docker compose up -d --build`

Files: `Dockerfile`, `docker-compose.yml`, `.dockerignore`, `docker/entrypoint.sh`.
