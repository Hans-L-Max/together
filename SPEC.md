# SPEC – „Zwei Antworten“ (Arbeitstitel)

> **Kostenlos & Open Source** (AGPL-3.0). Keine Preise, keine Zahlungen, keine Premium-Inhalte. Betrieb ausschließlich selbst gehostet per **Docker Compose**.

Gesprächs- und Fragespiele für Paare und Freunde, bei denen **beide gleichzeitig auf dem eigenen Gerät antworten** und die Antworten erst aufgedeckt werden, wenn beide fertig sind. Dazu ein **Deck-Editor**, mit dem man eigene Fragen-Decks baut („nur das Tool, ohne fertige Fragen“).

> **Für Claude Code:** Lies diese Datei komplett, bevor du Code schreibst. Arbeite die Meilensteine (Abschnitt 13) der Reihe nach ab. Nach jedem Meilenstein: Tests grün, `pint` sauber, kurze Zusammenfassung, dann stoppen und auf Freigabe warten. Bei Unklarheiten in dieser Spec: nachfragen statt raten.

---

## 1. Ziel & Abgrenzung

**Ziel:** Eine schlanke, schnelle Web-App (mobile first), inspiriert vom Konzept „Gesprächsspiele für Leute, die sich schon gut kennen“.

**Ausdrücklich NICHT:**
- Keine Kopie von Texten, Fragen, Bildern, Namen, Logos oder Design einer bestehenden Seite (z. B. boredinary.com). Alle Inhalte, das Branding und das Design sind eigenständig.
- Keine nativen Apps, kein Chat, kein Social Feed.
- Keine Nutzerkonten-Pflicht zum Spielen (außer der Host verlangt für einen Raum/ein Deck eine Anmeldung, siehe 4.5).
- Keine Preise, Käufe, Abos oder Bezahl-Decks. Alle Inhalte sind frei.

## 2. Tech-Stack (Stand Oktober 2026)

| Bereich | Wahl | Hinweis |
|---|---|---|
| Sprache | **PHP 8.5** (aktuell 8.5.x) | 8.6 ist noch RC – nicht verwenden |
| Framework | **Laravel 13** | Mindest-PHP 8.3, wir nutzen 8.5 |
| Frontend | **Livewire 4** + **Blade** + **Alpine.js** | kein separates JS-SPA |
| CSS | **Tailwind CSS 4** via Vite | |
| Echtzeit | **Laravel Reverb** mit **Database-Driver** | kein Redis nötig |
| DB | **PostgreSQL 17** (Docker Compose) / SQLite (Tests, lokale Entwicklung) | |
| Queue | `database` | |
| Auth | **Laravel Fortify** (lokales Login, abschaltbar) + **OIDC** (generischer Provider, z. B. Authentik, Keycloak, Authelia, Zitadel) | siehe Abschnitt 7 |
| Admin | **Filament** (aktuelle, zu Laravel 13 kompatible Version) | nur für Admins |
| Tests | **Pest** (aktuelle Version) | Feature + Unit + Browser-Tests optional |
| Qualität | **Laravel Pint**, **Larastan** (Level 8) | |
| Hosting | **Docker Compose**: Nginx, PHP-FPM 8.5, Queue-Worker, Scheduler, PostgreSQL (später Reverb) | einziger unterstützter Betriebsweg |
| Lokal | Docker Compose oder Laravel Herd | |
| Lizenz | **AGPL-3.0-or-later** | |

Regeln:
- `declare(strict_types=1);` in allen PHP-Dateien.
- Moderne PHP-Features nutzen, wo sinnvoll: readonly-Klassen/Properties, Enums, Property Hooks, asymmetrische Sichtbarkeit, `#[\Override]`, Pipe-Operator `|>` sparsam und nur wo es lesbarer wird.
- Typisierte Rückgabewerte überall. Keine Facades in Domain-Klassen, wo DI möglich ist.
- Versionen in `composer.json` / `package.json` mit `^` auf aktuelle Majors pinnen; vor dem Setup mit `composer show` / `npm view` die echten aktuellen Versionen prüfen.

## 3. Rollen

- **Gast:** spielt ohne Konto, über Raum-Link oder Code.
- **Nutzer (optional, mit Konto):** speichert eigene Decks, sieht Spielverlauf, kann Räume mit Login-Pflicht betreten.
- **Admin:** pflegt offizielle Decks.

Auth: Laravel-Starter-Kit (Livewire-Variante) mit E-Mail + Passwort, **OIDC** (Abschnitt 7) und optional **Magic Link**. Lokales Login und OIDC sind per `.env` einzeln an-/abschaltbar.

## 4. Kernkonzept: Raum & gleichzeitiges Antworten

### 4.1 Ablauf
1. **Host** wählt ein Deck (offiziell oder eigenes) und einen **Modus** (siehe 4.2) → „Raum erstellen“.
2. System erzeugt Raum mit **6-stelligem Code** (ohne verwechselbare Zeichen: kein 0/O/1/I) und Link `/r/{code}`. QR-Code anzeigen.
3. **Gast** öffnet Link, gibt Anzeigenamen ein (max. 24 Zeichen) → beide sehen sich in der Lobby (Echtzeit). Verlangt der Raum eine Anmeldung (4.5), muss sich der Gast vorher anmelden (OIDC oder lokal); der Anzeigename wird dann aus dem Konto vorbelegt.
4. Host startet. Beide sehen **dieselbe Frage gleichzeitig**.
5. Jeder antwortet auf dem eigenen Gerät. Der andere sieht nur **„✓ hat geantwortet“**, nie den Inhalt.
6. Sind beide fertig → **Aufdecken**: Antworten nebeneinander, inkl. Übereinstimmung (bei Auswahlfragen).
7. Optional: „Darüber reden“-Pause, dann „Nächste Frage“ (jeder darf weiterklicken; weiter geht's, wenn beide bereit sind).
8. Ende: Zusammenfassung (Übereinstimmungsquote, Liste der Fragen + Antworten), Button „Als PDF speichern“ (Browser-Druck reicht für v1).

### 4.2 Modi
- **Gleichzeitig ehrlich** (Standard): beide beantworten dieselbe Frage über sich selbst.
- **Errate mich:** A antwortet über sich, B tippt, was A antworten würde; dann Rollen tauschen. Punkte für Treffer.
- **Gesprächskarten:** keine Eingabe, nur Frage anzeigen, beide tippen „Bereit für die nächste“. (Für reine Gesprächsdecks.)
- **Ein-Geräte-Modus (Fallback):** gleicher Ablauf, Gerät wird weitergereicht; Antworten werden bis zum Aufdecken verdeckt („Gib das Handy an X“-Screen).
- **Solo:** eine Person beantwortet ein Deck für sich (Reflexion), Ergebnis als Zusammenfassung.

### 4.3 Teilnehmerzahl
v1: genau **2**. Datenmodell aber für **2–8** auslegen (Gruppenmodus später).

### 4.4 Robustheit
- Reconnect: Teilnehmer wird über signiertes Cookie (`participant_token`) wiedererkannt; Neuladen der Seite verliert nichts.
- Zustand ist **in der DB autoritativ**; Broadcasts sind nur Benachrichtigungen. Bei Ausfall von Reverb: Livewire-Polling (`wire:poll.3s`) als Fallback.
- Räume verfallen nach **24 h Inaktivität**; Antworten von Gastspielen werden dann gelöscht (Scheduler-Job).
- Antworten bis zum Aufdecken niemals an den anderen Client senden (auch nicht im Livewire-Payload!).

### 4.5 Zugang pro Deck/Raum („Login-Pflicht“)
- Jedes Deck hat ein Feld `play_access`: `guests` (Standard – jeder mit Link/Code darf mitspielen, ohne Konto) oder `members` (alle Teilnehmenden müssen angemeldet sein).
- Beim Raum-Erstellen übernimmt der Raum den Wert des Decks. Der Host darf ihn **verschärfen** (`guests` → `members`), aber nicht lockern, wenn das Deck `members` verlangt. Ein „Schnelles Deck“ ohne Konto ist immer `guests`; `members` setzt voraus, dass der Host selbst angemeldet ist.
- Ist im Raum `members` gesetzt, leitet `/r/{code}` nicht angemeldete Besucher zur Anmeldung (bei reinem OIDC-Betrieb direkt zum OIDC-Provider) und danach zurück in den Raum.
- Die Prüfung erfolgt serverseitig beim Beitreten **und** bei jeder Aktion im Raum (Policy), nicht nur in der Oberfläche.

## 5. Fragetypen

| Typ | Eingabe | Aufdecken |
|---|---|---|
| `text` | Freitext (max. 500 Zeichen) | nebeneinander |
| `single_choice` | 2–6 Optionen | Match / kein Match |
| `multi_choice` | 2–8 Optionen | Schnittmenge hervorheben |
| `scale` | 1–5 oder 1–10, mit Beschriftung links/rechts | Abstand anzeigen |
| `this_or_that` | 2 Optionen | Match / kein Match |
| `who_of_us` | „Ich“ / „Du“ / „Beide“ | Match, wenn beide dieselbe Person meinen |
| `prompt` | keine Eingabe (Gesprächskarte) | – |

Jede Frage hat optional: `hint` (kleiner Untertitel), `follow_up` (Anschlussfrage für das Gespräch), `skippable` (bool, Standard true). **Überspringen** ist immer erlaubt und wird beim Aufdecken neutral angezeigt.

## 6. Decks

### 6.1 Deck-Eigenschaften
`title`, `slug`, `description`, `audience` (`couples` | `friends` | `solo` | `any`), `category` (`deep_talk` | `fun` | `intimacy` | `future` | `custom`), `language` (v1: `de`, `en`), `is_adult` (18+), `cover_image`, `status` (`draft` | `published` | `archived`), `owner_id` (null = offiziell), `visibility` (`private` | `unlisted` | `public`), `play_access` (`guests` | `members`, siehe 4.5).

### 6.2 Deck-Editor („nur das Tool“)
- Eingeloggte Nutzer erstellen beliebig viele **eigene Decks**: Fragen anlegen, Typ wählen, per Drag & Drop sortieren (Livewire 4 Sortable / Alpine).
- **Import/Export als JSON** (Schema siehe 6.3) und **Import aus CSV** (Spalten: `type,text,options,hint`).
- Eigene Decks spielbar, auch für Gäste über den Raum-Link (sofern `play_access` = `guests`).
- `unlisted`: per Link teilbar. `public`: v1 deaktiviert (später mit Moderation).
- Ohne Konto: „Schnelles Deck“ – Fragen direkt beim Raum-Erstellen eintippen, wird nicht gespeichert.

### 6.3 JSON-Schema (Import/Export & Seeder)
```json
{
  "schema_version": 1,
  "title": "Sonntagsfragen",
  "description": "Leichte Fragen für den Frühstückstisch",
  "audience": "couples",
  "category": "fun",
  "language": "de",
  "is_adult": false,
  "questions": [
    { "type": "text", "text": "Was war heute dein kleinster schöner Moment?" },
    { "type": "scale", "text": "Wie ausgeschlafen bist du?", "min": 1, "max": 5, "min_label": "Zombie", "max_label": "Topfit" },
    { "type": "this_or_that", "text": "Berge oder Meer?", "options": ["Berge", "Meer"] },
    { "type": "who_of_us", "text": "Wer von uns vergisst öfter den Schlüssel?" }
  ]
}
```
Validierung über eine eigene `DeckSchema`-Klasse; Fehler mit Zeilen-/Index-Angabe zurückgeben.

### 6.4 Start-Inhalte
Claude Code erstellt **4 eigene Beispiel-Decks** mit je 15–25 selbst formulierten Fragen (keine Übernahme fremder Fragen) als JSON in `database/decks/`:
1. „Wie geht's uns eigentlich?“ – Paare, Deep Talk
2. „Errate mich“ – Paare/Freunde, Fun
3. „Was als Nächstes?“ – Paare, Zukunft & Pläne
4. „Näher“ – Paare, Intimität (respektvoll, nicht explizit), 18+

Alle Decks sind frei verfügbar.

## 7. Anmeldung per OIDC

- Generische **OpenID-Connect**-Anbindung (Authorization Code Flow mit **PKCE**, `state` und `nonce`), Konfiguration ausschließlich über `.env`:
  `OIDC_ENABLED`, `OIDC_ISSUER`, `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`, `OIDC_SCOPES` (Standard `openid profile email`), `OIDC_BUTTON_LABEL`.
- Provider-Endpunkte kommen per Discovery (`{issuer}/.well-known/openid-configuration`, gecacht). Das ID-Token wird vollständig geprüft: Signatur über JWKS, `iss`, `aud`, `exp`/`iat`, `nonce`.
- Konten werden über `(oidc_issuer, oidc_subject)` verknüpft, nicht über die E-Mail. Neues OIDC-Konto → Nutzer wird angelegt; E-Mail gilt als verifiziert, wenn der Provider `email_verified=true` liefert. Existiert bereits ein lokales Konto mit derselben E-Mail, wird **nicht** automatisch verknüpft (Schutz vor Kontoübernahme), sondern ein Hinweis angezeigt.
- `AUTH_LOCAL_ENABLED=false` schaltet E-Mail/Passwort-Login und Registrierung ab → OIDC ist dann der einzige Login. `OIDC_AUTO_REDIRECT=true` leitet `/login` direkt zum Provider.
- Access-/Refresh-Tokens werden **nicht** gespeichert (nur Login, keine API-Zugriffe beim Provider).
- Logout meldet in der App ab; RP-initiated Logout beim Provider optional über `end_session_endpoint` (`OIDC_LOGOUT_AT_PROVIDER`).

## 8. Seiten / Routen

| Route | Inhalt |
|---|---|
| `/` | Hero, Deck-Übersicht (Karten), „Eigenes Deck bauen“-CTA |
| `/decks/{slug}` | Deck-Detail, Beispielfragen (3), „Spielen“ |
| `/play/{slug}` | Modus wählen → Raum erstellen |
| `/r/{code}` | Lobby → Spiel → Aufdecken → Ergebnis (eine Livewire-Komponente mit Zuständen) |
| `/join` | Code eingeben |
| `/my/decks` | eigene Decks (Liste, Editor) |
| `/my/decks/{id}/edit` | Deck-Editor |
| `/auth/oidc/redirect`, `/auth/oidc/callback` | OIDC-Login |
| `/about`, `/contact` | statisch |
| `/impressum`, `/datenschutz`, `/agb`, `/widerruf` | Rechtliches (Platzhalter-Texte, klar als Vorlage markiert) |
| `/admin` | Filament |

## 9. Datenmodell (Kurzfassung)

```
users            id, name, email, email_verified_at, password?, oidc_issuer?, oidc_subject?, locale,
                 birthdate_confirmed_adult (bool), is_admin (bool), timestamps
                 unique (oidc_issuer, oidc_subject)
decks            id, owner_id?, title, slug, description, audience, category, language, is_adult,
                 cover_path?, status, visibility, play_access, timestamps, soft deletes
questions        id, deck_id, position, type, text, hint?, follow_up?, config (json: options/min/max/labels), skippable
rooms            id, code (unique), deck_id?, host_participant_id?, mode, play_access,
                 status (lobby|playing|revealing|finished), current_question_index,
                 quick_questions (json, für "schnelles Deck"), last_activity_at, timestamps
participants     id, room_id, user_id?, display_name, token_hash, is_host, last_seen_at, timestamps
answers          id, room_id, participant_id, question_ref, round (für "Errate mich"), value (json),
                 skipped (bool), answered_at
```
- Enums als PHP-Backed-Enums (`DeckCategory`, `QuestionType`, `RoomMode`, `RoomStatus`, `PlayAccess` …).
- Antworten verschlüsselt speichern (`encrypted:array` Cast).
- Raum-Logik in einer Klasse `RoomStateMachine` (Übergänge explizit, ungültige Übergänge werfen Exception), nicht im Livewire-Component.

## 10. Echtzeit-Events (Reverb, Presence Channel `room.{code}`)

`ParticipantJoined`, `ParticipantLeft`, `GameStarted`, `ParticipantAnswered` (nur `participant_id`, **kein Inhalt**), `QuestionRevealed`, `NextQuestion`, `GameFinished`.
Channel-Autorisierung über `participant_token`, nicht über Login.

## 11. Design & UX

- **Mobile first**, große Tap-Flächen, eine Frage pro Screen, ruhige Animationen (Karte dreht sich beim Aufdecken, `prefers-reduced-motion` respektieren).
- Eigenständiges Branding: warme, ruhige Farbpalette, eine Serifen-Schrift für Fragen + eine Sans-Serif für UI (Google Fonts oder lokal gehostet – DSGVO: **lokal hosten**).
- Hell/Dunkel-Modus.
- Barrierefreiheit: WCAG 2.2 AA, Fokus-Reihenfolge, ARIA-Live-Region für „X hat geantwortet“.
- i18n: alle Texte über `lang/de` und `lang/en`; Standard Deutsch.
- 18+-Decks: Hinweis-Screen „Dieses Deck ist für Erwachsene“ mit Bestätigung durch **beide** Teilnehmer vor Start.

## 12. Sicherheit, Datenschutz, Recht (DE/EU)

- DSGVO: Datensparsamkeit, keine Tracker, keine Analytics in v1 (später ggf. Plausible/self-hosted). Kein Cookie-Banner nötig, solange nur technisch notwendige Cookies.
- Gastspiele: Daten nach 24 h Inaktivität löschen. Nutzer können Konto + alle Daten selbst löschen.
- Rate Limiting: Raum-Erstellung (10/h pro IP), Join-Versuche (20/min pro IP, gegen Code-Raten), Antworten.
- CSRF, CSP-Header, `SameSite=Lax`, HTTPS erzwingen.
- Policies: nur Owner bearbeitet eigene Decks; Räume mit `play_access = members` nur für angemeldete Teilnehmende; Antworten nur nach Aufdecken sichtbar.
- OIDC: PKCE, `state`, `nonce`, vollständige ID-Token-Prüfung; keine Speicherung von Provider-Tokens; keine automatische Kontoverknüpfung über E-Mail.
- Rechtstexte als Platzhalter mit deutlichem Hinweis „vor Livegang durch Fachperson prüfen lassen“.

## 13. Meilensteine

1. **Setup:** Laravel 13 + PHP 8.5, Livewire 4, Tailwind 4, Pest, Pint, Larastan, CI (GitHub Actions: Tests, Pint, Larastan). README mit Start-Anleitung. **Docker Compose** (Nginx, PHP-FPM, Queue, Scheduler, PostgreSQL) und **OIDC-Login**.
2. **Domain:** Migrationen, Models, Enums, `DeckSchema`, Seeder mit den 4 JSON-Decks, Unit-Tests.
3. **Spiel ohne Echtzeit:** Raum erstellen/beitreten, Modus „Gleichzeitig ehrlich“, Polling, Aufdecken, Ergebnis. Feature-Tests inkl. „Antwort leakt nicht vor Aufdecken“.
4. **Echtzeit:** Reverb (DB-Driver), Presence Channel, Events, Reconnect, Polling-Fallback.
5. **Weitere Modi:** Errate mich, Gesprächskarten, Ein-Geräte-Modus, Solo.
6. **Konten & Deck-Editor:** Magic Link (optional), eigene Decks, Drag & Drop, JSON/CSV-Import/Export, Schnelles Deck, Login-Pflicht pro Deck/Raum im Editor einstellbar.
7. **Admin:** Filament für offizielle Decks und Nutzer (Admin-Rolle per `is_admin`, Ernennung per Artisan-Befehl).
8. **Feinschliff:** Design, Dark Mode, i18n EN, Barrierefreiheit, Rechtsseiten, Cleanup-Job, Rate Limits, CSP.
9. **Betrieb:** Produktionshärtung des Compose-Setups (Reverb-Dienst, TLS hinter Reverse Proxy, Update-Anleitung), Backup/Restore für PostgreSQL und Uploads, vorgebaute Images per GitHub Actions (GHCR).

## 14. Akzeptanzkriterien (Auswahl)

- Zwei Handys im selben WLAN und über Mobilfunk können einen Raum betreten und ein 20-Fragen-Deck ohne Neuladen durchspielen.
- Kein Client erhält zu irgendeinem Zeitpunkt die Antwort des anderen vor dem Aufdecken (Test prüft Livewire-Payload und Broadcast-Payload).
- Seite neu laden mitten im Spiel → man landet bei derselben Frage, eigene Antwort bleibt erhalten.
- `docker compose up -d` auf einem frischen Server startet eine lauffähige Instanz (inkl. Migrationen).
- Login über einen OIDC-Provider (z. B. Keycloak) funktioniert; ein Raum mit `play_access = members` lässt nicht angemeldete Gäste nicht hinein.
- Lighthouse mobil: Performance ≥ 90, Accessibility ≥ 95.
- `composer test`, `vendor/bin/pint --test`, `vendor/bin/phpstan` laufen grün in CI.

## 15. Später (nicht in v1)

Gruppenmodus (3–8), öffentliche Community-Decks mit Moderation, Tagesfrage per Push/Mail, PWA/Offline, mehrere OIDC-Provider gleichzeitig, KI-gestützte Fragenvorschläge im Editor (Laravel AI SDK).
