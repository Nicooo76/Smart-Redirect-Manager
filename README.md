# Smart Redirect Manager

WordPress-Plugin für professionelles Redirect-Management mit automatischer URL-Überwachung, 404-Logging, Conditional Redirects, Hit-Tracking, WP-CLI und REST API.

**Autor:** Sven Gauditz  
**Lizenz:** MIT  
**Mindestanforderungen:** WordPress 5.0, PHP 7.4

---

## Inhaltsverzeichnis

- [Funktionen im Überblick](#funktionen-im-überblick)
- [Installation](#installation)
- [Admin-Bereich](#admin-bereich)
- [Redirects verwalten](#redirects-verwalten)
- [Automatische Weiterleitungen bei URL-Änderungen](#automatische-weiterleitungen-bei-url-änderungen)
- [404-Fehler Logging](#404-fehler-logging)
- [Conditional Redirects (Bedingungen)](#conditional-redirects-bedingungen)
- [Gruppen](#gruppen)
- [Automatische 410 Gone](#automatische-410-gone)
- [Automatische Redirect-Bereinigung](#automatische-redirect-bereinigung)
- [E-Mail-Benachrichtigungen](#e-mail-benachrichtigungen)
- [Statistiken & Hit-Tracking](#statistiken--hit-tracking)
- [Tools](#tools)
- [Import & Export](#import--export)
- [Migration von Redirection](#migration-von-redirection)
- [REST API](#rest-api)
- [WP-CLI](#wp-cli)
- [Einstellungen (Übersicht)](#einstellungen-übersicht)
- [Entwicklung & Version](#entwicklung--version)

---

## Funktionen im Überblick

| Bereich | Beschreibung |
|--------|--------------|
| **Redirects** | Manuelle und automatische Weiterleitungen (301, 302, 307, 308, 410), Regex-Unterstützung, Ablaufdatum, Notizen |
| **URL-Monitor** | Erkennt Permalink-Änderungen bei Beiträgen, Seiten und Custom Post Types und legt automatisch 301-Weiterleitungen an |
| **404-Log** | Protokolliert 404-Anfragen mit optionaler Gruppierung und DSGVO-konformer IP-Anonymisierung |
| **Bedingungen** | Pro Redirect: Bedingungen (User-Agent, Referrer, Login-Status, Rolle, Gerät, Sprache, Cookie, Query, IP, Server, Methode, Zeit, Wochentag) |
| **Gruppen** | Redirects in Gruppen/Tags organisieren, farbig markieren |
| **410 Gone** | Automatische 410-Antworten für dauerhaft gelöschte Beiträge/Seiten/CPTs |
| **Auto-Cleanup** | Zeitgesteuerte Bereinigung alter, ungenutzter Redirects (deaktivieren, löschen oder in 410 umwandeln) |
| **Benachrichtigungen** | E-Mail bei neuen 404-Spitzen oder täglicher/wöchentlicher Zusammenfassung |
| **Statistiken** | Hit-Tracking pro Redirect, Tages-/Wochenstatistik, Dashboard-Widget |
| **Tools** | URL-Tester, Ketten/Loops-Erkennung, URL-Validierung, Duplikat-Suche, Server-Regeln (Apache/Nginx) exportieren |
| **Import/Export** | CSV-Import und -Export von Redirects und 404-Log |
| **Migration** | Import aus dem Redirection-Plugin (Redirects, Gruppen, 404-Log, Hit-Statistiken) |
| **REST API** | CRUD für Redirects, Statistiken und 404-Log unter `srm/v1` |
| **WP-CLI** | Befehle für Liste, Hinzufügen, Löschen, Import, Export, Statistiken, Cleanup, Ketten |

---

## Installation

1. Plugin-Ordner in `wp-content/plugins/smart-redirect-manager/` legen (oder als ZIP über **Plugins → Installieren** hochladen).
2. Plugin unter **Plugins** aktivieren.
3. Tabellen und Standardeinstellungen werden bei Aktivierung angelegt; Cron-Jobs für tägliche Bereinigung und Log-Cleanup werden registriert.

---

## Admin-Bereich

Nach der Aktivierung erscheint im WordPress-Menü der Eintrag **Redirects** mit folgenden Unterpunkten:

- **Alle Redirects** – Liste, Filter, Suche, Bulk-Aktionen, neue Weiterleitung, Bearbeiten
- **404-Fehler** – 404-Log mit Auflösen (Redirect anlegen), Löschen, Export
- **Import / Export** – CSV-Import/Export für Redirects und 404-Log
- **Tools** – URL-Tester, Ketten & Loops, URL-Validierung, Duplikate, Server-Regeln, Gruppen
- **Einstellungen** – alle Plugin-Optionen
- **Migration** – nur sichtbar, wenn das Redirection-Plugin installiert ist

Am unteren Rand jeder Plugin-Seite erscheint der Hinweis **„Mit ❤️ erstellt von Sven Gauditz“** mit Links zu Homepage und GitHub.

---

## Redirects verwalten

- **Neue Weiterleitung:** Quell-URL, Ziel-URL, HTTP-Status (301, 302, 307, 308, 410), Option „Quell-URL als Regex“, Aktiv, Gruppe, Ablaufdatum, Notizen.
- **Bearbeiten:** Wie Neu, plus Typ (Manuell, Auto Beitrag, Auto Taxonomie, 410, Aus 404, Import, Migration, CLI), Hits, Letzter Hit.
- **Regex:** Quell-URL als regulärer Ausdruck; Backreferences (z. B. `$1`) in der Ziel-URL möglich. Integrierter Regex-Tester.
- **Bedingungen:** Pro Redirect beliebig viele Bedingungen (siehe [Conditional Redirects](#conditional-redirects-bedingungen)); alle müssen erfüllt sein (AND).
- **Bulk-Aktionen:** Aktivieren, Deaktivieren, Löschen für ausgewählte Redirects.
- **Filter:** Nach Status (Aktiv/Inaktiv), Typ (Manuell, Auto Beitrag, …), Suche in Quell-/Ziel-URL.

---

## Automatische Weiterleitungen bei URL-Änderungen

Wenn sich die **Permalink-URL** eines Beitrags, einer Seite oder eines **Custom Post Types** (z. B. Campingplätze, Events) ändert:

1. Die **alte URL** wird vor dem Speichern erfasst (inkl. Cache-Fallback und REST/Block-Editor).
2. Nach dem Speichern wird automatisch eine **Weiterleitung** (Standard: 301) von der alten zur neuen URL angelegt.
3. Es erscheint ein **Admin-Hinweis**: „Es wurde automatisch eine 301-Weiterleitung von … nach … eingerichtet.“
4. **Redirect-Ketten** werden aufgelöst: Bestehende Redirects, die auf die alte URL zeigen, werden auf die neue Ziel-URL umgestellt.

**Einstellungen (Einstellungen → Automatische Redirects):**

- Option **„Automatische Weiterleitungen bei URL-Änderungen erstellen“** ein-/ausschalten.
- **Überwachte Inhaltstypen:** Beiträge, Seiten und alle öffentlich abrufbaren **Custom Post Types** (einzeln anwählbar).
- **Standard HTTP-Status-Code** für automatische Redirects (301, 302, 307, 308).

Unterstützt werden klassischer Editor, Block-Editor (Gutenberg) und REST-API-Saves für alle ausgewählten Post-Typen.

---

## 404-Fehler Logging

- **404-Fehler protokollieren:** Alle Frontend-Anfragen, die zu einer 404-Seite führen, werden geloggt (sofern aktiviert).
- **Gleiche 404-URLs zusammenfassen:** Ein Eintrag pro URL, Zähler für Mehrfachaufrufe (gruppiert).
- **Log-Aufbewahrungsdauer:** Einträge älter als X Tage werden per Cron gelöscht (DSGVO-freundlich).
- **IP-Anonymisierung:** Letztes Oktett der IP wird auf 0 gesetzt (DSGVO).
- **Ausgenommene Pfade:** Bestimmte Pfade (z. B. `/wp-admin`, `/wp-login.php`) werden nicht geloggt.

In der Ansicht **404-Fehler** können Einträge als „gelöst“ markiert oder direkt in einen **Redirect** umgewandelt werden (Ziel-URL angeben).

---

## Conditional Redirects (Bedingungen)

Pro Redirect können **Bedingungen** definiert werden. Der Redirect wird nur ausgeführt, wenn **alle** Bedingungen erfüllt sind.

Unterstützte **Bedingungstypen** (Auswahl):

- **user_agent** – Browser/Client (Operatoren: enthält, enthält nicht, gleich, …)
- **referrer** – HTTP Referer
- **login_status** – eingeloggt / ausgeloggt
- **user_role** – WordPress-Rolle (z. B. Administrator, Abonnent)
- **device_type** – z. B. Mobile, Desktop
- **language** – Sprache (z. B. aus Accept-Language)
- **cookie** – Vorhandensein/Wert eines Cookies
- **query_param** – Query-Parameter (z. B. `?ref=xyz`)
- **ip_range** – IP-Bereich
- **server_name** – Host/Server-Name
- **request_method** – GET, POST, …
- **time_range** – Zeitfenster
- **day_of_week** – Wochentag

Operatoren z. B.: equals, contains, not_contains, regex, …

---

## Gruppen

- **Gruppen** dienen zur Ordnung von Redirects (z. B. „Marketing“, „SEO“, „Alte Blog-URLs“).
- Pro Gruppe: Name, optionale Beschreibung, Farbe (für Badge in der Liste).
- Beim Anlegen/Bearbeiten eines Redirects kann eine Gruppe zugewiesen werden.
- Unter **Tools → Gruppen** können Gruppen angelegt, bearbeitet und gelöscht werden.

---

## Automatische 410 Gone

- **Einstellung:** „Automatisch 410 (Gone) für gelöschte Inhalte senden“.
- Wenn ein **veröffentlichter** Beitrag, eine Seite oder ein überwachter **Custom Post Type** **dauerhaft gelöscht** wird (nicht nur in den Papierkorb), wird automatisch ein **410-Gone-Redirect** für die ehemalige URL angelegt.
- Suchmaschinen erhalten so das Signal „Inhalt absichtlich entfernt“; 410-URLs werden in der Regel schneller aus dem Index entfernt als 404.

---

## Automatische Redirect-Bereinigung

- **Cron-Job** (täglich): Sucht Redirects, die
  - ein konfiguriertes **Mindestalter** (z. B. 365 Tage) erreicht haben und
  - seit einer konfigurierten **Leerlaufzeit** (z. B. 90 Tage) keinen Aufruf mehr hatten und
  - kein Ablaufdatum haben.
- **Aktion** pro Treffer (wählbar): **Deaktivieren**, **Endgültig löschen** oder **In 410 (Gone) umwandeln**.
- Bestimmte **Quelltypen** (z. B. Manuell, Auto, Import) können von der Bereinigung **ausgenommen** werden.

Hinweis: Nach einer Bereinigung erscheint optional ein Admin-Hinweis mit der Anzahl behandelter Redirects.

---

## E-Mail-Benachrichtigungen

- **E-Mail bei 404-Spitze:** Wenn an einem Tag mehr als X 404-Anfragen (Schwellwert einstellbar) auftreten, kann eine E-Mail gesendet werden.
- **Zusammenfassung:** Täglich oder wöchentlich eine E-Mail mit Übersicht (z. B. neue 404, Hits, Redirect-Statistik).
- E-Mail-Adresse und Frequenz in den Einstellungen konfigurierbar.

---

## Statistiken & Hit-Tracking

- **Hit-Tracking:** Pro Redirect werden Aufrufe gezählt (einmal pro Tag pro Redirect aggregiert), sofern aktiviert.
- **Übersicht:** Aktive Redirects, Hits heute, Hits letzte 7 Tage, offene 404-Anzahl, Top-404-URLs.
- **Dashboard-Widget:** Zeigt diese Kennzahlen direkt im WordPress-Dashboard.
- **REST API:** Endpoints für Statistiken und tägliche Hits (z. B. für eigene Dashboards).

---

## Tools

Unter **Redirects → Tools** (Tab-Navigation):

| Tab | Funktion |
|-----|----------|
| **URL-Tester** | URL eingeben → prüft, ob ein Redirect greift (exact/regex), zeigt Bedingungen und Redirect-Kette. |
| **Ketten & Loops** | Zeigt Redirect-Ketten (A → B → C) und erkennt Loops. |
| **URL-Validierung** | Prüft Ziel-URLs auf Erreichbarkeit (HTTP-Status). |
| **Duplikate** | Findet Redirects mit gleicher Quell-URL. |
| **Server-Regeln** | Exportiert aktive Redirects als **Apache** (RewriteRule) oder **Nginx** (return/rewrite) Konfiguration. |
| **Gruppen** | Gruppen verwalten (Anlegen, Bearbeiten, Löschen). |

---

## Import & Export

- **Export Redirects:** CSV-Download aller Redirects (Quell-URL, Ziel-URL, Status, Regex, Aktiv, Typ, Hits, Notizen, Ablauf, Erstellt).
- **Export 404-Log:** CSV-Download der 404-Einträge.
- **Import Redirects:** CSV-Upload (Semikolon-getrennt); Duplikate können übersprungen oder aktualisiert werden. Format-Beispiel und Spaltenbeschreibung auf der Import-Seite.

---

## Migration von Redirection

Falls das Plugin **Redirection** installiert ist (aktiv oder inaktiv), erscheint **Redirects → Migration**.

- **Vorschau:** Anzahl Redirects, Gruppen, 404-Log-Einträge, Hit-Log-Einträge sowie eine kleine Redirect-Vorschau.
- **Migration ausführen:** Redirects, Gruppen (als SRM-Gruppen), optional 404-Log und Hit-Statistiken werden übernommen. Alte Redirection-IDs werden auf neue SRM-IDs gemappt; Hit-Statistiken beziehen sich auf `redirection_id` in `redirection_logs`.
- Optional: Redirection-Plugin nach der Migration deaktivieren.

---

## REST API

Namespace: **`srm/v1`**

- **GET** `/redirects` – Redirects auflisten (paginiert, filterbar nach Status, Typ, Suche).
- **POST** `/redirects` – Redirect anlegen.
- **GET** `/redirects/<id>` – Einzelnen Redirect abrufen.
- **PUT/PATCH** `/redirects/<id>` – Redirect aktualisieren.
- **DELETE** `/redirects/<id>` – Redirect löschen.
- **GET** `/stats` – Statistik-Übersicht (aktive Redirects, Hits heute/7 Tage, offene 404, …).
- **GET** `/stats/daily` – Tägliche Hits über X Tage.
- **GET** `/404` – 404-Log abrufen (paginiert, filterbar).

Berechtigung: Nutzer mit `manage_options` (Administrator).

---

## WP-CLI

Befehle unter **`wp srm`** (sofern WP-CLI und Plugin aktiv):

| Befehl | Kurzbeschreibung |
|--------|------------------|
| `wp srm list` | Redirects auflisten (Filter: status, type, search; Format: table, csv, json). |
| `wp srm add <source> <target>` | Redirect hinzufügen (Optionen: --code, --regex, --notes). |
| `wp srm delete <id>` | Redirect löschen. |
| `wp srm overview` | Übersicht (Anzahl Redirects, Hits heute/7 Tage, 404). |
| `wp srm export redirects [--file=…]` | Redirects als CSV exportieren. |
| `wp srm export 404 [--file=…]` | 404-Log als CSV exportieren. |
| `wp srm import <file>` | Redirects aus CSV importieren. |
| `wp srm cleanup` | Automatische Bereinigung einmalig ausführen. |
| `wp srm chains` | Redirect-Ketten anzeigen. |

Weitere Optionen siehe `wp help srm <subcommand>`.

---

## Einstellungen (Übersicht)

| Bereich | Optionen (Auswahl) |
|---------|--------------------|
| **Automatische Redirects** | An/Aus, überwachte Post-Typen, Standard-Statuscode (301/302/307/308). |
| **404-Logging** | 404 protokollieren, Gruppierung, Aufbewahrungsdauer (Tage). |
| **Gelöschte Inhalte** | Automatisch 410 für gelöschte Inhalte. |
| **Auto-Cleanup** | An/Aus, Mindestalter (Tage), Leerlaufzeit (Tage), Aktion (deaktivieren/löschen/410), ausgenommene Typen. |
| **E-Mail** | Benachrichtigungen an/aus, E-Mail-Adresse, Frequenz, 404-Spike-Schwellwert. |
| **Statistiken** | Hit-Tracking an/aus. |
| **Performance** | Redirect-Cache-TTL (Sekunden), ausgenommene Pfade (kein Redirect, kein 404-Log). |
| **Datenbank** | Anzeige Tabellennamen und Zeilenanzahl (Redirects, 404-Log, Hits, Gruppen, Bedingungen). |

---

## Entwicklung & Version

- **Versionsnummer** wird im Haupt-Plugin-Header und in der Konstante `SRM_VERSION` in `smart-redirect-manager.php` geführt.
- **Bei jeder Änderung am Plugin die Versionsnummer einmal erhöhen** (z. B. 1.0.1 → 1.0.2 oder 1.1.0), damit Releases und Updates eindeutig zugeordnet werden können.

---

## Support & Lizenz

- **Autor:** Sven Gauditz  
- **Homepage:** [gauditz.com](https://gauditz.com)  
- **GitHub:** [github.com/Nicooo76](https://github.com/Nicooo76)  
- **Lizenz:** MIT (siehe `LICENSE`)
