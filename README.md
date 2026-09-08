# Casino

Laravel 13 application.

- **Local development:** Laravel Sail (`compose.yaml`)
- **Server (staging/prod):** self-contained Docker images (`compose.prod.yaml`)
- **Deployment:** push to `main` → GitHub Actions → SSH deploy to the server

---

## Local development

Runs on [Laravel Sail](https://laravel.com/docs/sail) (Docker). A `Makefile`
wraps the common commands — run them from **WSL / a Linux shell** (not
PowerShell), from the repo root.

```bash
make setup   # first time only: .env, dependencies, app key, database, assets
make dev     # start everything at once, then open http://localhost
```

`make dev` brings the containers up and runs the queue worker, the log tailer
(`artisan pail`) and the Vite dev server together in one terminal with combined
output. Press `Ctrl+C` to stop them (the containers keep running; `make down`
stops those).

Run `make` on its own to list every target. The most used ones:

| Command        | What it does                                           |
|----------------|-------------------------------------------------------|
| `make dev`     | containers + queue + logs + Vite, all at once          |
| `make up` / `make down` | start / stop the containers                    |
| `make migrate` / `make fresh` | run migrations / rebuild the database   |
| `make test`    | run the test suite                                     |
| `make pint`    | format code                                            |
| `make shell` / `make tinker` | shell / REPL in the app container        |
| `make build`   | build production front-end assets                      |
| `make deploy`  | `git push origin main` → triggers the server deploy    |

Without `make`, the equivalent is `./vendor/bin/sail up -d`, then
`./vendor/bin/sail npm run dev` (plus `sail artisan queue:listen` and
`sail artisan pail` in other terminals).

App: http://localhost

---

## Server deployment

### How it works

`compose.prod.yaml` builds four things from `docker/Dockerfile`:

| Service     | Image        | Role                                             |
|-------------|--------------|--------------------------------------------------|
| `web`       | `nginx`      | Serves `public/`, proxies PHP — published on **:8080** |
| `app`       | `php-fpm`    | Application code + vendor + built assets         |
| `queue`     | `php-fpm`    | `php artisan queue:work`                         |
| `scheduler` | `php-fpm`    | `php artisan schedule:run` every minute          |
| `mysql`     | `mysql:8.4`  | Database (data in the `mysql-data` volume)       |
| `redis`     | `redis:7`    | Cache / locks                                    |

Code is **baked into the image** at build time — no bind-mounted source on the
server. Only `./storage` and `./.env` are mounted (the latter read-only), so
uploads and logs survive rebuilds and the `.env` never enters the image. `.env`
lives on the server only — it is git-ignored.

### Automatic deploy

Every push to `main` (or a manual run from the Actions tab) runs
`.github/workflows/deploy.yml`, which SSHes into the server and:

1. `git reset --hard origin/main`
2. `docker compose -f compose.prod.yaml build`
3. `docker compose -f compose.prod.yaml up -d`
4. `php artisan migrate --force`
5. re-caches config/views and restarts the queue

**Required GitHub repo secrets** (Settings → Secrets and variables → Actions):

| Secret         | Value             |
|----------------|-------------------|
| `SSH_HOST`     | `207.180.253.8`   |
| `SSH_USER`     | `root`            |
| `SSH_PASSWORD` | the root password |
| `SSH_PORT`     | `22`              |

To switch to key-based auth later, add the public key to the server's
`~/.ssh/authorized_keys`, set an `SSH_KEY` secret to the private key (make sure
the pasted value keeps its trailing newline), and change `password:` back to
`key:` in `.github/workflows/deploy.yml`.

### Manual deploy / first run on a fresh server

```bash
git clone https://github.com/arturalagulyan/casino.git /var/www/casino
cd /var/www/casino
cp .env.production.example .env
# edit .env: set APP_KEY (php artisan key:generate --show works locally) and DB passwords
docker compose -f compose.prod.yaml build
docker compose -f compose.prod.yaml up -d
docker compose -f compose.prod.yaml exec -T app php artisan migrate --force
```

### Player frontend (house casino)

The first-party player casino served at the **site root** (`/`; `/admin` is
unaffected). Its front-end assets (`resources/css/frontend.css`,
`resources/js/frontend.js`) are already in `vite.config.js` and get built into
the image by `npm run build` during the Docker build — **no extra build step**.

The only deploy-time work is provisioning the "house" shop the frontend runs
on. Do it **once**, after the first deploy that includes the frontend:

```bash
cd /var/www/casino
docker compose -f compose.prod.yaml exec -T app \
  php artisan frontend:setup --from=<source-shop-slug> --bank=0
```

This creates the **Web Casino** house shop, its API key, a game bank, and
clones every *visible* game (bet ladders, RTP, categories…) from `--from` into
it. It is **idempotent** — re-run it after importing more games to pick them up
(add `--fresh` to drop and re-clone the house catalogue). If `--from` is
omitted it clones from the shop with the most visible games.

Optional branding overrides in the server `.env` (defaults shown):

```
FRONTEND_SHOP_SLUG=web-casino
FRONTEND_SHOP_NAME="Web Casino"
FRONTEND_BRAND="Royal Spin"
```

`.env` changes take effect on the next deploy (which runs `config:cache`), or
run `php artisan config:cache` in the `app` container manually.

**Players:** there is no self-service registration. Staff create player
accounts in `/admin` with role **User** and shop **Web Casino**. Login accepts
username or email.

**Note:** games only render where `storage/app/game-bundles/` is populated on
the server — the launch/token flow is otherwise identical to the seamless
wallet path.

### Useful commands on the server

```bash
cd /var/www/casino
docker compose -f compose.prod.yaml ps
docker compose -f compose.prod.yaml logs -f app
docker compose -f compose.prod.yaml exec app php artisan tinker
docker compose -f compose.prod.yaml exec app php artisan migrate:status
```
