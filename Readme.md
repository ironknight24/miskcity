# miskcityportal

Drupal 11 stack for local development using Docker Compose (works on **macOS** and **Windows** with Docker Desktop).

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Compose V2 plugin included as `docker compose`)
- **Windows**: Enable WSL2 and use the WSL2 backend in Docker Desktop; clone the repo on the Linux filesystem (e.g. `\\wsl$\...`) for better file I/O. Ensure the project folder is allowed under Docker **Settings → Resources → File sharing**.
- **macOS**: Apple Silicon (ARM) and Intel are supported by the `drupal` base image (multi-arch).
- **RAM**: At least ~4 GB free for the default stack; add ~2 GB more if you enable the SonarQube profile.

## Quick start

1. Copy environment template (optional; required only for SonarQube scanning):

   ```bash
   cp .env.example .env
   ```

   Edit `.env` and set `SONAR_TOKEN` if you use the `sonar` profile. Never commit `.env`.

2. Build and start the **default** stack (PHP-FPM, Nginx, MySQL, phpMyAdmin):

   ```bash
   docker compose build
   docker compose up -d
   ```

3. Open the site:

   | Service     | URL                         |
   | ----------- | --------------------------- |
   | Drupal site | http://localhost:8080       |
   | phpMyAdmin  | http://localhost:8081       |

4. Install PHP dependencies from the project root (host or container):

   ```bash
   docker compose exec web bash -lc "cd /opt/drupal && composer install"
   ```

   Complete Drupal installation (database import, `drush site:install`, or your usual workflow) using the DB service:

   - Host: `db` (from containers) or `127.0.0.1` from the host with port published if you add one
   - Database: `drupal`
   - User / password: `drupal` / `drupal` (see `docker-compose.yml`)

## Optional: SonarQube profile

Analysis stack (SonarQube + PostgreSQL + scanner) is **not** started by default.

```bash
docker compose --profile sonar up -d
```

- SonarQube UI: http://localhost:9100  
- After PHPUnit coverage exists under `coverage/`, run the scanner:

  ```bash
  docker compose --profile sonar run --rm sonar_scanner
  ```

Set `SONAR_TOKEN` in `.env` (create a token under **My Account → Security** in SonarQube). If a token was ever committed to the repository, **revoke it** in SonarQube and use a new one locally only.

## Development commands

Use **`docker compose`** (with a space), not the legacy `docker-compose` binary.

### Drupal / Drush

```bash
docker compose exec web bash -lc "cd /opt/drupal && vendor/bin/drush cr"
docker compose exec web bash -lc "cd /opt/drupal && vendor/bin/drush <command>"
```

`bash -lc` keeps working directory and quoting consistent on Windows, macOS, and Linux.

### PHPUnit and coverage

Create the output directory if it is missing:

```bash
mkdir -p coverage
```

```bash
docker compose exec web bash -c "cd /opt/drupal && XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-clover coverage/clover.xml -c phpunit.xml.dist"
```

Coverage output is read by SonarQube via `sonar-project.properties` (`coverage/clover.xml`).

## Ports reference

| Port | Service              | Default stack |
| ---- | -------------------- | ------------- |
| 8080 | Nginx → Drupal       | yes           |
| 8081 | phpMyAdmin           | yes           |
| 9100 | SonarQube            | `sonar` profile only |
| 5434 | PostgreSQL (Sonar)   | `sonar` profile only |

## Configuration notes

- **CORS** in [`nginx/default.conf`](nginx/default.conf) allows `http://localhost:8080`. If you use another origin or port, update the `Access-Control-Allow-Origin` value there.
- **Permissions**: Writable dirs such as `web/sites/default/files` may need ownership fixes inside the container after bind-mounting, e.g. `docker compose exec web chown -R www-data:www-data web/sites/default/files` (adjust paths as needed).

## Security

- Do not commit secrets. Use `.env` locally (gitignored).
- Rotate any SonarQube token that was previously stored in version control.
