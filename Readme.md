# miskcityportal

Drupal 11 stack for local development using Docker Compose (works on **macOS** and **Windows** with Docker Desktop).

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Compose V2 plugin included as `docker compose`)
- **Windows**: Enable WSL2 and use the WSL2 backend in Docker Desktop; clone the repo on the Linux filesystem (e.g. `\\wsl$\...`) for better file I/O. Ensure the project folder is allowed under Docker **Settings → Resources → File sharing**.
- **macOS**: Apple Silicon (ARM) and Intel are supported by the `drupal` base image (multi-arch).
- **RAM**: At least ~4 GB free for the default stack; add ~2 GB more if you enable the SonarQube profile.
- **PHP in Docker**: The `web` image uses **PHP 8.4** (`Dockerfile.web`) so it matches the Composer platform requirement (`composer.lock`). If you run `composer` on the host, use PHP 8.4+ or rely on Composer inside the `web` container only.

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

   The MySQL service allows up to **60 seconds** on first boot (empty volume) before health checks count as failures—this helps slower disks (e.g. some Windows setups).

3. Install PHP dependencies (required before Drush/Drupal can run). From the project root, using the same PHP version as production:

   ```bash
   docker compose exec web bash -lc "cd /opt/drupal && composer install"
   ```

4. Open the UI:

   | Service     | URL                         |
   | ----------- | --------------------------- |
   | Drupal site | http://localhost:8080       |
   | phpMyAdmin  | http://localhost:8081       |

5. **Install Drupal** (once per machine; pick one):

   - **Drush (recommended, same on Windows/macOS/Linux):**

     ```bash
     docker compose exec web bash -lc 'cd /opt/drupal && vendor/bin/drush site:install standard \
       --db-url="mysql://drupal:drupal@db/drupal" \
       --account-name=admin \
       --account-pass=CHOOSE_A_STRONG_PASSWORD \
       --site-name="Miskcity Portal" \
       -y'
     ```

   - **Or** use the web installer at http://localhost:8080/core/install.php and use these database settings from *inside* Docker (what Drupal sees):

     - Host: `db`
     - Database name: `drupal`
     - Username / password: `drupal` / `drupal`

   `settings.php` and `files/` are created under `web/sites/default/` and stay local (gitignored where appropriate). To reset and reinstall, empty the MySQL volume (`docker compose down -v`) and remove generated `settings.php` / `files` only if you know you need a clean slate.

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

`bash -lc` keeps working directory and quoting consistent on Windows, macOS, and Linux. On **Windows**, use **Git Bash** or **WSL** for the same shell behavior; native `cmd.exe` quoting differs.

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
