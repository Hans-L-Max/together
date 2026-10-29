# Zwei Antworten

Gesprächs- und Fragespiele für Paare und Freunde: Beide beantworten dieselbe Frage **gleichzeitig auf dem eigenen Gerät**, aufgedeckt wird erst, wenn beide fertig sind. Dazu ein Deck-Editor für eigene Fragen-Decks.

**Kostenlos und Open Source** ([AGPL-3.0-or-later](LICENSE)), selbst gehostet per **Docker Compose**.

Die vollständige Spezifikation steht in [`SPEC.md`](SPEC.md).

## Tech-Stack

| Bereich  | Wahl |
|----------|------|
| Sprache  | PHP 8.5 |
| Framework| Laravel 13, Livewire 4, Blade, Alpine.js |
| CSS      | Tailwind CSS 4 (Vite), Schriften lokal gehostet (Fraunces, Figtree) |
| Auth     | Laravel Fortify (lokal, abschaltbar) + OpenID Connect |
| Betrieb  | Docker Compose: Nginx, PHP-FPM, Queue-Worker, Scheduler, PostgreSQL 17 |
| Tests    | Pest 5 |
| Qualität | Laravel Pint (`declare_strict_types`), Larastan Level 8 |

## Betrieb mit Docker Compose

Voraussetzung: Docker mit Compose-Plugin (`docker compose version`).

```bash
git clone https://github.com/Hans-L-Max/together.git
```

```bash
cd together && cp .env.example .env
```

In `.env` mindestens setzen:

- `APP_URL` – die öffentliche Adresse, z. B. `https://together.example.org`
- `DB_PASSWORD` – ein langes, zufälliges Passwort für die Datenbank
- `APP_KEY` – erzeugen mit:

```bash
docker compose build && docker compose run --rm --no-deps app php artisan key:generate --show
```

Dann starten:

```bash
docker compose up -d
```

Die App läuft auf <http://localhost:8080> (Port über `APP_PORT` änderbar). Migrationen laufen beim Start des `app`-Containers automatisch.

### Dienste

| Dienst      | Aufgabe |
|-------------|---------|
| `web`       | Nginx, liefert statische Dateien aus und reicht PHP an `app` weiter (Port 8080) |
| `app`       | PHP-FPM mit der Anwendung, führt beim Start die Migrationen aus |
| `queue`     | Queue-Worker (`php artisan queue:work`) |
| `scheduler` | Geplante Aufgaben (`php artisan schedule:work`) |
| `db`        | PostgreSQL 17 |

Daten liegen in den Volumes `together_db-data` (Datenbank) und `together_storage` (Uploads, Logs).

### HTTPS

Die App selbst spricht nur HTTP. Für den Livebetrieb einen Reverse Proxy mit TLS davorsetzen (Caddy, Traefik, Nginx Proxy Manager …) und `APP_URL` auf die `https://`-Adresse setzen. Proxys aus privaten Netzen (Docker-Standard) gelten als vertrauenswürdig; abweichend über `TRUSTED_PROXIES` einstellen.

Beispiel mit Caddy auf demselben Server:

```caddyfile
together.example.org {
    reverse_proxy 127.0.0.1:8080
}
```

### Aktualisieren

```bash
git pull && docker compose up -d --build
```

### Logs

```bash
docker compose logs -f app queue
```

## Anmeldung (OIDC)

Neben E-Mail und Passwort kann sich jede Instanz an einen OpenID-Connect-Anbieter hängen (Authentik, Keycloak, Authelia, Zitadel, Pocket ID, …).

1. Beim Anbieter einen **Confidential Client** (Authorization Code Flow) anlegen.
2. Als Redirect-URI eintragen: `<APP_URL>/auth/oidc/callback`
3. Optional als Post-Logout-Redirect-URI: `<APP_URL>/`
4. In `.env`:

```dotenv
OIDC_ENABLED=true
OIDC_ISSUER=https://auth.example.org/realms/together
OIDC_CLIENT_ID=together
OIDC_CLIENT_SECRET=...
OIDC_BUTTON_LABEL="Mit Vereinskonto anmelden"
```

`OIDC_ISSUER` ist die Adresse, unter der `/.well-known/openid-configuration` liegt (bei Keycloak `…/realms/<realm>`, bei Authentik `…/application/o/<slug>/`).

| Variable | Wirkung |
|----------|---------|
| `AUTH_LOCAL_ENABLED=false` | E-Mail/Passwort-Login, Registrierung und Passwort-Reset abschalten → nur noch OIDC |
| `OIDC_AUTO_REDIRECT=true` | `/login` leitet direkt zum Anbieter weiter (nur mit `AUTH_LOCAL_ENABLED=false`) |
| `OIDC_LOGOUT_AT_PROVIDER=true` | Beim Abmelden auch die Sitzung beim Anbieter beenden |
| `OIDC_SCOPES` | Standard `openid profile email`; die E-Mail-Adresse wird benötigt |

Sicherheit: Authorization Code Flow mit PKCE, `state` und `nonce`; das ID-Token wird über die JWKS des Anbieters geprüft (Signatur, `iss`, `aud`, Ablauf). Konten werden über Anbieter + Subject verknüpft, **nie automatisch über die E-Mail-Adresse**. Provider-Tokens werden nicht gespeichert.

Ob ein Deck oder Raum eine Anmeldung voraussetzt, wird pro Deck/Raum festgelegt (siehe `SPEC.md`, Abschnitt 4.5).

## Entwicklung

Für die Entwicklung reicht lokales PHP 8.5 (z. B. [Laravel Herd](https://herd.laravel.com)) mit SQLite – oder der Docker-Stack.

```bash
composer setup
```

```bash
composer dev
```

`composer setup` installiert alle Abhängigkeiten, legt eine Entwicklungs-`.env` an (SQLite, `APP_ENV=local`), erzeugt den App-Key, migriert und baut die Assets. `composer dev` startet Webserver, Queue-Worker, Log-Viewer und Vite; die App läuft unter <http://localhost:8000>.

### Qualitätssicherung

Alles auf einmal (Pint-Check, Larastan, Tests):

```bash
composer test
```

Einzeln:

```bash
vendor/bin/pest
```

```bash
vendor/bin/pint --test
```

```bash
vendor/bin/phpstan analyse --memory-limit=1G
```

Code-Style automatisch korrigieren:

```bash
composer lint
```

Die GitHub-Actions-Pipeline (`.github/workflows/ci.yml`) prüft Pint, Larastan und die Tests mit PHP 8.5, baut die Docker-Images und startet den kompletten Stack einmal zur Probe.

### Konventionen

- Jede PHP-Datei beginnt mit `declare(strict_types=1);` (wird von Pint erzwungen).
- Typisierte Rückgabewerte überall; Domain-Logik nicht in Livewire-Komponenten.
- Alle UI-Texte laufen über `__()`; Übersetzungen in `lang/de.json` und `lang/en.json`. Standardsprache ist Deutsch.
- Keine externen Dienste im Browser (keine Font-CDNs, keine Tracker).

## Lizenz

[GNU Affero General Public License v3.0 oder neuer](LICENSE). Wer eine veränderte Version als Webdienst betreibt, muss den Quellcode dieser Version den Nutzenden zugänglich machen.
