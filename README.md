# Platz- und Busbelegung

Responsives Buchungssystem für Fußballplätze, Kabinen und Vereinsbus. Öffentliche Besucher sehen bestätigte Buchungen und offene Buchungsanfragen ohne Anmeldung; interne Benutzer melden sich für Anfragen und Verwaltung an.

## Lokal mit Docker starten

1. Lege eine lokale Konfiguration an und ersetze den Setup-Schlüssel durch einen zufälligen Wert mit mindestens 32 Zeichen:

   ```powershell
   Copy-Item .env.example .env
   ```

2. Baue und starte die Anwendung:

   ```powershell
   docker compose up --build -d
   ```

3. Öffne [http://localhost:9000](http://localhost:9000). Beim ersten Start richtet MySQL das Schema mit `database/install.sql` ein und spielt anschließend die Ressourcen aus `database/seed_resources.sql` ein. Wähle im Kalender **Ersten Administrator einrichten** und gib den Wert aus `APP_SETUP_TOKEN` ein.

Die lokale Oberfläche läuft auf Port `9000`; phpMyAdmin ist unter `http://localhost:9001` und Mailpit unter `http://localhost:8025` erreichbar. E-Mails werden lokal abgefangen und nicht an echte Empfänger versendet.

MySQL führt Dateien in `/docker-entrypoint-initdb.d` nur aus, wenn das Datenverzeichnis erstmals initialisiert wird. Bei einem bestehenden Docker-Volume werden `install.sql` und `seed_resources.sql` nicht automatisch erneut ausgeführt. **Das Volume nicht mit `docker compose down -v` löschen**, wenn vorhandene Daten erhalten bleiben sollen.

## Rollen und Funktionen

- **Admin:** Benutzer und Ressourcen über das Untermenü des Buttons **Einstellungen** verwalten sowie Anfragen bearbeiten und bestätigte Termine löschen.
- **Verwalter:** Buchungen direkt bestätigen, Anfragen freigeben oder ablehnen und bestätigte Termine löschen. In der Ansicht „Alle Anfragen“ werden genehmigte Serientermine je Serie als ein Eintrag angezeigt; direkt angelegte Buchungen von Verwaltern und Admins erscheinen dort nicht.
- **Trainer:** Trainings-, Spiel- und Busanfragen über die Schaltfläche in der Anfrageübersicht stellen sowie eigene offene Anfragen im gemeinsamen Buchungsdialog ändern oder löschen. Gelöschte offene Anfragen werden dauerhaft entfernt. Die Anfrageübersicht zeigt standardmäßig offene Anfragen; bei Bedarf kann auf alle Anfragen umgeschaltet werden. Anfragen für Termine, die mehr als drei Monate zurückliegen, werden nicht angezeigt. Längere Listen werden mit jeweils zehn Einträgen pro Seite angezeigt. Ein ausgewählter Eintrag markiert alle zugehörigen Buchungen und Ressourcenspalten im Kalender und bleibt in der Anfrageübersicht hellgelb hervorgehoben. Anfragen können keine Kabinen belegen.
- **Öffentliche Besucher:** bestätigte Belegungen und offene Anfragen für Plätze und Fahrzeuge einschließlich zugeordneter Kabinen ohne Anmeldung ansehen.

Es gibt keine öffentliche Registrierung. Jeder Benutzer hat einen eindeutigen Benutzernamen und meldet sich damit oder mit seiner E-Mail-Adresse an. Ein Admin lädt Benutzer per E-Mail-Einladungslink ein oder legt Benutzer ohne E-Mail-Adresse mit einem Startpasswort direkt an. Admins können Passwörter jederzeit in der Benutzerverwaltung ändern; Passwort-Reset-Links per E-Mail stehen nur Konten mit E-Mail-Adresse zur Verfügung. Benutzer können gelöscht werden; zugehörige Reservierungen und Buchungshistorie bleiben dabei erhalten, werden aber keinem Benutzer mehr zugeordnet. Der letzte aktive Administrator kann nicht gelöscht werden. Öffentliche Kalenderdaten enthalten keine Kontaktdaten, Fahrernamen oder internen Notizen.

Der Startbestand enthält sieben Plätze (`Platz 1 Süd`, `Platz 1 Nord`, `Platz 2`, `Platz 3`, `Platz 4 Ost`, `Platz 4 West`, `Platz 5`), sechs Kabinen (`Kabine 1` bis `Kabine 6`) und einen `Vereinsbus`. Eine Platzbelegung benötigt mindestens einen Platz; optionale Kabinen gehören zur gesamten Buchung und nicht zu einzelnen Plätzen. Verwalter und Admins können Kabinen zusammen mit einer Platzbuchung auswählen; Traineranfragen können keine Kabinen belegen. Eigene Kabinenbuchungen und eine separate Kabinenansicht gibt es nicht mehr. Plätze, Kabinen und Fahrzeuge dürfen mehrfach für denselben oder überlappende Zeiträume gebucht werden. Beim Anlegen, Bearbeiten und Freigeben gibt es keine Konfliktsperre und keine erforderliche Übersteuerung mehr. Bestehende Übersteuerungsprotokolle bleiben erhalten; eine Datenbankmigration ist für diese Änderung nicht erforderlich. Admins können Ressourcen anlegen, umbenennen, deaktivieren, wieder aktivieren und löschen. Den Typ einer bereits verwendeten Ressource kann man nicht ändern. Beim Löschen einer Ressource werden alle Buchungen, die sie verwenden, einschließlich aller weiteren Ressourcenzuordnungen dieser Buchungen, gelöscht. Dabei werden keine E-Mails versendet.

Bei Platzbelegungen wird die Kategorie direkt im Buchungsdialog ausgewählt: Training oder Spiel sowie für Verwalter und Admins externe Belegung. Für alle Kategorien stehen dieselben Angaben zur Verfügung; die Spiel-spezifischen Felder Gegner und Spielort werden nicht abgefragt. Plätze und optionale Kabinen werden über gleich gestaltete Schaltflächen in jeweils drei Spalten ausgewählt.

„Mannschaft / Hinweis“ ist bei allen Buchungsarten beim Anlegen und Bearbeiten verpflichtend und kann mehrzeilig eingegeben werden; leere Angaben oder reine Leerzeichen werden auch serverseitig abgelehnt.

Für Beginn und Ende gibt es ein gemeinsames Datumsfeld sowie getrennte Uhrzeitfelder. Neue Buchungen sind mit 90 Minuten Dauer vorbelegt. Wird das Datum oder die Anfangszeit geändert, verschiebt sich das Ende mit gleicher Dauer; eine manuell geänderte Endzeit legt die neue Dauer fest.

Der Zeitraum oberhalb des Kalenders öffnet eine direkte Datumsauswahl. Derselbe gestaltete Monatskalender ist über die Kalenderschaltflächen neben den Datumsfeldern erreichbar, auch für das Ende einer Serie. Monat und Jahr können direkt gewählt werden; Pfeiltasten, Home/End und PageUp/PageDown unterstützen die Tastaturbedienung. Neue Buchungen übernehmen den im Kalender ausgewählten Tag.

Wenn die Anfrageübersicht angezeigt wird, lässt sich ihre Breite am Trenner zwischen Belegungsplan und Übersicht per Drag-and-drop oder Pfeiltasten ändern. Die Aufteilung und die Auswahl zwischen Platzbelegung und Fahrzeugkalender bleiben im jeweiligen Browserfenster auch nach einer Aktualisierung erhalten.

Anlegen und Bearbeiten verwenden denselben Buchungsdialog. Verwalter und Admins können dort Kategorie, Ressourcen, Kabinen, Titel, Zeiten, interne Notizen und bei Fahrzeugen Ziel, Zweck und Fahrer ändern. Trainer können alle bei einer Anfrage verfügbaren Angaben einschließlich Kategorie, Plätzen, Zeiten, interner Notiz und Wiederholung ändern; Kabinen stehen für Anfragen nicht zur Verfügung. Bei Serien gilt die Auswahl „nur diesen Termin“, „diesen und folgende“ oder „alle Termine“ für sämtliche Änderungen. Eine geänderte Wiederholungsregel erzeugt die Termine innerhalb dieses Umfangs neu und entfernt dort nicht mehr enthaltene Termine; außerhalb des Umfangs bleiben Buchungen und abgelehnte Termine unverändert. Unveränderte Serienregeln erhalten vorhandene Ausnahmen. Ungültige Eingaben führen weiterhin zum vollständigen Zurückrollen der Änderung; zeitliche Überschneidungen sind erlaubt.

Ein Klick auf einen Kalendereintrag zeigt die Buchungsangaben auch ohne Wechsel in den Bearbeitungsdialog: Mannschaft / Hinweis, Kategorie, alle gebuchten Ressourcen, Kabinen, Beginn und Ende mit Datum sowie die Wiederholungsregel mit Intervall, Wochentagen und Serienende. Zeilenumbrüche im Feld Mannschaft / Hinweis bleiben in Kalendern, Details und Anfrageübersichten erhalten. Bei Fahrzeugen werden Ziel und Zweck angezeigt. Fahrer und interne Notizen sind ausschließlich für Verwalter und Admins sichtbar.

Admins können Ressourcen innerhalb ihrer Kategorie per Drag-and-drop in der Ressourcenverwaltung sortieren. Die gespeicherte Reihenfolge wird auch in den Kalendern und Buchungsdialogen verwendet; per Pfeiltasten am Sortiergriff lässt sie sich ebenfalls ändern.

Kalenderansichten:

Das Dropdown in der Headerzeile wechselt zwischen Platzbelegung und Fahrzeugkalender. Ausgewähltes Datum und Tages-/Wochenansicht bleiben beim Wechsel erhalten.

- **Plätze:** Tagesraster mit Platzspalten (standardmäßig 08:00–19:00 Uhr; außerhalb dieses Zeitraums kann der restliche Tag eingeblendet werden) und Wochenansicht mit Tagen als Spalten. Die Spalten teilen die verfügbare Breite gleichmäßig; feste Mindestbreiten werden vermieden, damit horizontaler Bildlauf möglichst entfällt.
- **Kabinen:** Zugeordnete Kabinen erscheinen direkt an den Platzterminen und in deren Buchungsdialog.
- **Fahrzeuge:** Tages- und Wochenansicht, standardmäßig 08:00–19:00 Uhr mit optional einblendbarem restlichen Tag; mehrtägige Einzelbuchungen sind möglich.
- Buchungskategorie und optionale öffentliche Zusatzinfo werden direkt im Kalender angezeigt.
- Gleichzeitige und überlappende Platztermine stehen im Tagesraster nebeneinander; die Platzspalte wird bei Bedarf breiter und der Kalender horizontal scrollbar. Die Wochenansicht listet sämtliche Einträge. Im Fahrzeug-Tagesraster stehen die Fahrzeuge als Spalten mit Namen oben und ihren Buchungen darunter; die Wochenansicht entspricht der Platzbelegung mit Tagen als Spalten und Fahrzeugen als Zeilen.
- Offene Anfragen sind für alle sichtbar, auch ohne Anmeldung. Sie tragen zusätzlich zur unveränderten Kategorie die Kennzeichnung „Anfrage“, eine aufgehellte, weiterhin gut erkennbare Kategoriefarbe und den normalen Kalendereintragsrand. Die Legende zeigt die helle Anfragefarbe für jede Kategorie mit der Beschriftung „Anfrage“. Freigegebene Anfragen wechseln zur normalen Darstellung; abgelehnte und früher wegen Konflikten ausgelassene Anfragen bleiben ausgeblendet. Gelöschte Anfragen werden dauerhaft aus dem System entfernt. Interne Notizen und Fahrernamen bleiben geschützt. Mehrere Plätze eines Buchungstermins erscheinen gemeinsam als eine Buchungsanfrage; Freigabe und Ablehnung aus der Übersicht betreffen alle noch offenen Plätze. Die Freigabe öffnet den Bearbeitungsdialog, in dem die Angaben geprüft und angepasst werden können.
- Verwalter und Admins können bestätigte Termine bearbeiten, die Kabinenbelegung einer Platzbuchung ändern oder Termine löschen. Beim Löschen eines einzelnen Platzes bleibt die Kabinenbelegung erhalten, solange die Buchung weitere Plätze enthält; mit dem letzten Platz wird der Termin samt Kabinen entfernt. Trainer können eigene offene Anfragen ändern oder zurückziehen.
- **Terminarten:** Training (grün), Spiel (blau), externe Belegung (orange) und Fahrzeugbuchung (violett).
- Buchungszeiten verwenden 15-Minuten-Schritte und die Zeitzone `Europe/Berlin`. Platztermine enden am selben Tag; Busbuchungen dürfen mehrtägig sein.
- Platzserien unterstützen tägliche, wöchentliche, monatliche und jährliche Wiederholung. Serien sind auf 5.000 Termine begrenzt.

## Hosting-Konfiguration

Der Webserver muss auf `www/` als DocumentRoot zeigen. `app/`, `database/`, `.env` und `vendor/` gehören außerhalb des öffentlich erreichbaren DocumentRoot. Benötigt werden PHP 8.2 oder neuer mit `mbstring` und `pdo_mysql`, MySQL 8 sowie ausgehender SMTP-Zugriff.

Setze die folgenden Variablen in der geschützten Konfigurationsoberfläche deines Hosters. Die Anwendung liest Umgebungsvariablen; sie lädt absichtlich keine öffentlich erreichbare `.env`-Datei:

| Variable | Beschreibung |
| --- | --- |
| `APP_ENV` | `production` auf dem Hoster; `local` lokal |
| `APP_URL` | Öffentliche Basis-URL, z. B. `https://verein.example` |
| `APP_SETUP_TOKEN` | Zufälliger Setup-Schlüssel mit mindestens 32 Zeichen |
| `DB_HOST`, `DB_PORT` | Datenbankserver und Port |
| `DB_NAME` | Datenbankname, standardmäßig `belegungsplan` |
| `DB_USER`, `DB_PASSWORD` | Zugangsdaten eines eingeschränkten Datenbankbenutzers |
| `MAIL_HOST`, `MAIL_PORT` | SMTP-Server und Port |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | SMTP-Zugangsdaten, falls erforderlich |
| `MAIL_ENCRYPTION` | `tls`, `ssl` oder leer |
| `MAIL_FROM`, `MAIL_FROM_NAME` | Absenderadresse und Anzeigename |

Vor dem Upload der Anwendung können Frontend-Dateien lokal erzeugt werden:

```powershell
npm ci
npm run build
```

Lade anschließend mindestens `app/`, `vendor/`, `www/` einschließlich `www/assets/build/`, `database/install.sql` und `database/seed_resources.sql` hoch. `vendor/` lässt sich mit `composer install --no-dev --optimize-autoloader` erstellen.

## Datenbankinstallation

`database/install.sql` enthält das vollständige Schema einschließlich der Ressourcenkategorien-Tabelle mit den Kategorien „Platz“, „Fahrzeug“ und „Kabine“. `database/seed_resources.sql` fügt anschließend die Startressourcen ein. Die Anwendung führt keine DDL-Änderungen aus.

Für eine neue lokale oder gehostete Datenbank zuerst `database/install.sql` und danach `database/seed_resources.sql` ausführen. Diese Installationsskripte sind für eine leere Datenbank vorgesehen. Bei einer bestehenden Datenbank vor einem Schema-Update eine Sicherung erstellen und das passende Skript in `database/migrations/` einmalig ausführen. `20261023_allow_user_deletion.sql` macht Buchungsreferenzen auf Benutzer nullable, damit sie bei einer Löschung auf `NULL` gesetzt werden können. `20261023_require_usernames.sql` vergibt für bisherige Konten ohne Benutzernamen einen eindeutigen Platzhalternamen und macht das Datenbankfeld verpflichtend. `20261023_cabins_belong_to_occurrences.sql` verschiebt Kabinenzuordnungen von einzelnen Platzzuordnungen auf Buchungstermine; falls eine Kabine innerhalb desselben Termins mehreren Plätzen zugeordnet war, wird sie dabei einmalig übernommen. `20261024_remove_match_details.sql` entfernt Gegner und Spielort aus den Buchungsdaten.

## Checks

`database/migrations/20261024_remove_standalone_cabin_bookings.sql` entfernt bei bestehenden Datenbanken die bisherigen separaten Kabinenbuchungen samt anschließend leeren Buchungsgruppen. Platzbuchungen und ihre Kabinenzuordnungen bleiben erhalten. Vor dem Ausführen eine Sicherung erstellen.

Den Buchungsdialog und die rollenabhängigen Kategorien prüfen:

```powershell
php .\tests\booking-dialog.php
```

Die automatische Anpassung der Buchungszeiten prüfen:

```powershell
node --test .\tests\booking-dates.test.mjs .\tests\date-picker.test.mjs .\tests\event-details.test.mjs .\tests\calendar-layout.test.mjs
```

Nach `docker compose build web` prüft der Integrationstest vollständige Buchungsänderungen einschließlich Serien, Ressourcen, Doppelbuchungen und öffentlich sichtbaren Anfragen. Er legt eine eigene temporäre Testdatenbank an und entfernt sie anschließend; die vorhandene Datenbank bleibt unverändert:

```powershell
.\tests\booking-edit.ps1
```

Nach dem Start der Umgebung prüft der HTTP-Smoke-Test die Startseite, die Ressourcen-API und den öffentlichen Kalender:

```powershell
.\tests\smoke-index.ps1
```

Frontend-Bundle bauen:

```powershell
npm run build
```
