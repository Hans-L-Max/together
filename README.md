# Zwei Antworten

Gesprächs- und Fragespiele für Paare und Freunde: Beide beantworten dieselbe Frage **gleichzeitig auf dem eigenen Gerät**, aufgedeckt wird erst, wenn beide fertig sind. Dazu ein Deck-Editor für eigene Fragen-Decks.

Die vollständige Spezifikation steht in [`SPEC.md`](SPEC.md).

## Tech-Stack

| Bereich  | Wahl                                              |
|----------|---------------------------------------------------|
| Sprache  | PHP 8.5                                           |
| Framework| Laravel 13, Livewire 4, Blade, Alpine.js          |
| CSS      | Tailwind CSS 4 (über Vite), Schriften lokal gehostet (Fraunces, Figtree) |
| Auth     | Laravel Fortify (Livewire-Starter-Kit)            |
| Tests    | Pest 5                                            |
| Qualität | Laravel Pint (`declare_strict_types`), Larastan Level 8 |

## Voraussetzungen

- PHP **8.5** mit den Extensions `intl`, `pdo_sqlite`, `mbstring`, `bcmath`, `zip`
- Composer 2
- Node.js 22+ und npm

Am einfachsten mit [Laravel Herd](https://herd.laravel.com) (PHP 8.5 auswählen) oder mit [Laravel Sail](https://laravel.com/docs/sail) (Docker).

## Lokal starten

### Mit Herd (oder lokalem PHP)

```bash
composer setup
```

```bash
composer dev
```

`composer setup` installiert alle Abhängigkeiten, legt `.env` an, erzeugt den App-Key, führt die Migrationen (SQLite unter `database/database.sqlite`) aus und baut die Assets. `composer dev` startet Webserver, Queue-Worker, Log-Viewer und Vite gleichzeitig. Die App läuft dann unter <http://localhost:8000> (bzw. unter der Herd-Domain).

### Mit Sail (Docker)

```bash
cp .env.example .env
```

```bash
docker run --rm -v "$(pwd)":/var/www/html -w /var/www/html composer:2 composer install --ignore-platform-reqs
```

```bash
./vendor/bin/sail up -d
```

```bash
./vendor/bin/sail artisan key:generate && ./vendor/bin/sail artisan migrate
```

```bash
./vendor/bin/sail npm install && ./vendor/bin/sail npm run dev
```

Für Sail in `.env` `MAIL_HOST=mailpit` und `MAIL_PORT=1025` setzen; Mails landen dann in Mailpit unter <http://localhost:8025>.

## Qualitätssicherung

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

Die GitHub-Actions-Pipeline (`.github/workflows/ci.yml`) führt Pint, Larastan und die Tests mit PHP 8.5 als getrennte Jobs aus.

## Konventionen

- Jede PHP-Datei beginnt mit `declare(strict_types=1);` (wird von Pint erzwungen).
- Typisierte Rückgabewerte überall; Domain-Logik nicht in Livewire-Komponenten.
- Alle UI-Texte laufen über `__()`; Übersetzungen in `lang/de.json` und `lang/en.json`. Standardsprache ist Deutsch.
- Schriften werden über `@fontsource` gebündelt und selbst ausgeliefert (keine externen Font-CDNs, DSGVO).

## Stand

Meilenstein 1 (Setup) ist abgeschlossen. Fortschritt der weiteren Meilensteine siehe Abschnitt 13 in `SPEC.md`.
