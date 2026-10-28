# SPEC – „Zwei Antworten“ (Arbeitstitel)

Gesprächs- und Fragespiele für Paare und Freunde, bei denen **beide gleichzeitig auf dem eigenen Gerät antworten** und die Antworten erst aufgedeckt werden, wenn beide fertig sind. Dazu ein **Deck-Editor**, mit dem man eigene Fragen-Decks baut („nur das Tool, ohne fertige Fragen“).

> **Für Claude Code:** Lies diese Datei komplett, bevor du Code schreibst. Arbeite die Meilensteine (Abschnitt 13) der Reihe nach ab. Nach jedem Meilenstein: Tests grün, `pint` sauber, kurze Zusammenfassung, dann stoppen und auf Freigabe warten. Bei Unklarheiten in dieser Spec: nachfragen statt raten.

---

## 1. Ziel & Abgrenzung

**Ziel:** Eine schlanke, schnelle Web-App (mobile first), inspiriert vom Konzept „Gesprächsspiele für Leute, die sich schon gut kennen“.

**Ausdrücklich NICHT:**
- Keine Kopie von Texten, Fragen, Bildern, Namen, Logos oder Design einer bestehenden Seite (z. B. boredinary.com). Alle Inhalte, das Branding und das Design sind eigenständig.
- Keine nativen Apps, kein Chat, kein Social Feed.
- Keine Nutzerkonten-Pflicht zum Spielen.

## 2. Tech-Stack (Stand Oktober 2026)

| Bereich | Wahl | Hinweis |
|---|---|---|
| Sprache | **PHP 8.5** (aktuell 8.5.x) | 8.6 ist noch RC – nicht verwenden |
| Framework | **Laravel 13** | Mindest-PHP 8.3, wir nutzen 8.5 |
| Frontend | **Livewire 4** + **Blade** + **Alpine.js** | kein separates JS-SPA |
| CSS | **Tailwind CSS 4** via Vite | |
| Echtzeit | **Laravel Reverb** mit **Database-Driver** | kein Redis nötig |
| DB | SQLite (Dev) / MySQL 8 oder PostgreSQL 16+ (Prod) | |
| Queue | `database` | |
| Zahlungen | **Stripe Checkout** (Einmalkauf) über **Laravel Cashier (Stripe)** | Webhooks |
| Admin | **Filament** (aktuelle, zu Laravel 13 kompatible Version) | nur für Admins |
| Tests | **Pest** (aktuelle Version) | Feature + Unit + Browser-Tests optional |
| Qualität | **Laravel Pint**, **Larastan** (Level 8) | |
| Lokal | **Laravel Herd** oder **Sail** | |

Regeln:
- `declare(strict_types=1);` in allen PHP-Dateien.
- Moderne PHP-Features nutzen, wo sinnvoll: readonly-Klassen/Properties, Enums, Property Hooks, asymmetrische Sichtbarkeit, `#[\Override]`, Pipe-Operator `|>` sparsam und nur wo es lesbarer wird.
- Typisierte Rückgabewerte überall. Keine Facades in Domain-Klassen, wo DI möglich ist.
- Versionen in `composer.json` / `package.json` mit `^` auf aktuelle Majors pinnen; vor dem Setup mit `composer show` / `npm view` die echten aktuellen Versionen prüfen.

## 3. Rollen

- **Gast:** spielt ohne Konto, über Raum-Link oder Code.
- **Nutzer (optional, mit Konto):** speichert eigene Decks, kauft Premium-Decks, sieht Spielverlauf.
- **Admin:** pflegt offizielle Decks, Preise, sieht Bestellungen.

Auth: Laravel-Starter-Kit (Livewire-Variante) mit E-Mail + Passwort und **Magic Link**. E-Mail-Verifizierung Pflicht für Käufe.

## 4. Kernkonzept: Raum & gleichzeitiges Antworten

### 4.1 Ablauf
1. **Host** wählt ein Deck (offiziell, gekauft oder eigenes) und einen **Modus** (siehe 4.2) → „Raum erstellen“.
2. System erzeugt Raum mit **6-stelligem Code** (ohne verwechselbare Zeichen: kein 0/O/1/I) und Link `/r/{code}`. QR-Code anzeigen.
3. **Gast** öffnet Link, gibt Anzeigenamen ein (max. 24 Zeichen) → beide sehen sich in der Lobby (Echtzeit).
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
`title`, `slug`, `description`, `audience` (`couples` | `friends` | `solo` | `any`), `category` (`deep_talk` | `fun` | `intimacy` | `future` | `custom`), `language` (v1: `de`, `en`), `is_adult` (18+), `price_cents` (0 = kostenlos), `sale_price_cents` (nullable), `cover_image`, `status` (`draft` | `published` | `archived`), `owner_id` (null = offiziell), `visibility` (`private` | `unlisted` | `public`).

### 6.2 Deck-Editor („nur das Tool“)
- Eingeloggte Nutzer erstellen beliebig viele **eigene Decks**: Fragen anlegen, Typ wählen, per Drag & Drop sortieren (Livewire 4 Sortable / Alpine).
- **Import/Export als JSON** (Schema siehe 6.3) und **Import aus CSV** (Spalten: `type,text,options,hint`).
- Eigene Decks kostenlos spielbar, auch für Gäste über den Raum-Link.
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
1. „Wie geht's uns eigentlich?“ – Paare, Deep Talk, kostenlos
2. „Errate mich“ – Paare/Freunde, Fun, kostenlos
3. „Was als Nächstes?“ – Paare, Zukunft & Pläne, kostenpflichtig
4. „Näher“ – Paare, Intimität (respektvoll, nicht explizit), 18+, kostenpflichtig

## 7. Zahlungen

- Einmalkauf pro Deck über **Stripe Checkout** (kein Abo in v1). Preise und Rabattpreis kommen aus der DB, nicht aus dem Frontend.
- Kauf erfordert Konto + verifizierte E-Mail. Freischaltung **nur über Webhook** (`checkout.session.completed`), idempotent (Event-ID speichern).
- Bei gekauften Decks reicht es, wenn **der Host** das Deck besitzt; der Gast spielt mit.
- Rückerstattung: Admin löst in Filament aus → Stripe-Refund + Entzug des Zugangs.
- Rechnungen: Stripe-Rechnung/Beleg per Mail. Preise inkl. MwSt. anzeigen; Stripe Tax aktivierbar per Config.

## 8. Seiten / Routen

| Route | Inhalt |
|---|---|
| `/` | Hero, Deck-Übersicht (Karten), „Eigenes Deck bauen“-CTA |
| `/decks/{slug}` | Deck-Detail, Beispielfragen (3), Preis, „Spielen“/„Kaufen“ |
| `/play/{slug}` | Modus wählen → Raum erstellen |
| `/r/{code}` | Lobby → Spiel → Aufdecken → Ergebnis (eine Livewire-Komponente mit Zuständen) |
| `/join` | Code eingeben |
| `/my/decks` | eigene Decks (Liste, Editor) |
| `/my/decks/{id}/edit` | Deck-Editor |
| `/my/purchases` | gekaufte Decks |
| `/about`, `/contact` | statisch |
| `/impressum`, `/datenschutz`, `/agb`, `/widerruf` | Rechtliches (Platzhalter-Texte, klar als Vorlage markiert) |
| `/admin` | Filament |

## 9. Datenmodell (Kurzfassung)

```
users            id, name, email, email_verified_at, password, locale, birthdate_confirmed_adult (bool), timestamps
decks            id, owner_id?, title, slug, description, audience, category, language, is_adult,
                 price_cents, sale_price_cents?, cover_path?, status, visibility, timestamps, soft deletes
questions        id, deck_id, position, type, text, hint?, follow_up?, config (json: options/min/max/labels), skippable
purchases        id, user_id, deck_id, stripe_checkout_id, stripe_event_id (unique), amount_cents, currency,
                 status (paid|refunded), timestamps
rooms            id, code (unique), deck_id?, host_participant_id?, mode, status (lobby|playing|revealing|finished),
                 current_question_index, quick_questions (json, für "schnelles Deck"), last_activity_at, timestamps
participants     id, room_id, user_id?, display_name, token_hash, is_host, last_seen_at, timestamps
answers          id, room_id, participant_id, question_ref, round (für "Errate mich"), value (json),
                 skipped (bool), answered_at
```
- Enums als PHP-Backed-Enums (`DeckCategory`, `QuestionType`, `RoomMode`, `RoomStatus` …).
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
- Policies: nur Owner bearbeitet eigene Decks; nur Käufer/Host startet Premium-Decks; Antworten nur nach Aufdecken sichtbar.
- Rechtstexte als Platzhalter mit deutlichem Hinweis „vor Livegang durch Fachperson prüfen lassen“.

## 13. Meilensteine

1. **Setup:** Laravel 13 + PHP 8.5, Livewire 4, Tailwind 4, Pest, Pint, Larastan, CI (GitHub Actions: Tests, Pint, Larastan). README mit Start-Anleitung.
2. **Domain:** Migrationen, Models, Enums, `DeckSchema`, Seeder mit den 4 JSON-Decks, Unit-Tests.
3. **Spiel ohne Echtzeit:** Raum erstellen/beitreten, Modus „Gleichzeitig ehrlich“, Polling, Aufdecken, Ergebnis. Feature-Tests inkl. „Antwort leakt nicht vor Aufdecken“.
4. **Echtzeit:** Reverb (DB-Driver), Presence Channel, Events, Reconnect, Polling-Fallback.
5. **Weitere Modi:** Errate mich, Gesprächskarten, Ein-Geräte-Modus, Solo.
6. **Konten & Deck-Editor:** Auth, eigene Decks, Drag & Drop, JSON/CSV-Import/Export, Schnelles Deck.
7. **Zahlungen:** Stripe Checkout, Webhook, Freischaltung, Refund in Filament.
8. **Feinschliff:** Design, Dark Mode, i18n EN, Barrierefreiheit, Rechtsseiten, Cleanup-Job, Rate Limits, CSP.
9. **Deployment:** `.env.example`, Anleitung für Laravel Forge / Ploi / Docker (Nginx + PHP-FPM 8.5 + Supervisor für Queue & Reverb), Backup-Hinweis.

## 14. Akzeptanzkriterien (Auswahl)

- Zwei Handys im selben WLAN und über Mobilfunk können einen Raum betreten und ein 20-Fragen-Deck ohne Neuladen durchspielen.
- Kein Client erhält zu irgendeinem Zeitpunkt die Antwort des anderen vor dem Aufdecken (Test prüft Livewire-Payload und Broadcast-Payload).
- Seite neu laden mitten im Spiel → man landet bei derselben Frage, eigene Antwort bleibt erhalten.
- Kauf mit Stripe-Testkarte schaltet das Deck erst nach Webhook frei; doppelter Webhook erzeugt keinen zweiten Kauf.
- Lighthouse mobil: Performance ≥ 90, Accessibility ≥ 95.
- `composer test`, `vendor/bin/pint --test`, `vendor/bin/phpstan` laufen grün in CI.

## 15. Später (nicht in v1)

Gruppenmodus (3–8), öffentliche Community-Decks mit Moderation, Tagesfrage per Push/Mail, PWA/Offline, Abo-Modell, KI-gestützte Fragenvorschläge im Editor (Laravel AI SDK).
