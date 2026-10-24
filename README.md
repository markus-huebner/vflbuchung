# Vereinsbelegung

Responsives Buchungssystem für Fußballplätze, Kabinen und Vereinsbus. Öffentliche Besucher sehen bestätigte Buchungen ohne Anmeldung; interne Benutzer melden sich für Anfragen und Verwaltung an.

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

- **Admin:** Benutzer und Ressourcen verwalten, Anfragen bearbeiten, bestätigte Termine stornieren und bei Bedarf eine Doppelbelegung nach Warnung erzwingen.
- **Verwalter:** Buchungen direkt bestätigen, Anfragen freigeben oder ablehnen und bestätigte Termine stornieren.
- **Trainer:** Trainings-, Spiel- und Busanfragen stellen sowie eigene offene Anfragen ändern oder zurückziehen.
- **Öffentliche Besucher:** bestätigte Platz-, Kabinen- und Busbelegungen ohne Anmeldung ansehen.

Es gibt keine öffentliche Registrierung. Jeder Benutzer hat einen eindeutigen Benutzernamen und meldet sich damit oder mit seiner E-Mail-Adresse an. Ein Admin lädt Benutzer per E-Mail-Einladungslink ein oder legt Benutzer ohne E-Mail-Adresse mit einem Startpasswort direkt an. Admins können Passwörter jederzeit in der Benutzerverwaltung ändern; Passwort-Reset-Links per E-Mail stehen nur Konten mit E-Mail-Adresse zur Verfügung. Benutzer können gelöscht werden; zugehörige Reservierungen und Buchungshistorie bleiben dabei erhalten, werden aber keinem Benutzer mehr zugeordnet. Der letzte aktive Administrator kann nicht gelöscht werden. Öffentliche Kalenderdaten enthalten keine Kontaktdaten, Fahrernamen oder internen Notizen.

Der Startbestand enthält sieben Plätze (`Platz 1 Süd`, `Platz 1 Nord`, `Platz 2`, `Platz 3`, `Platz 4 Ost`, `Platz 4 West`, `Platz 5`), sechs Kabinen (`Kabine 1` bis `Kabine 6`) und einen `Vereinsbus`. Die Buchungsmaske trennt Platzbelegungen von separaten Kabinenbuchungen: Eine Platzbelegung benötigt mindestens einen Platz; optionale Kabinen gehören zur gesamten Buchung und nicht zu einzelnen Plätzen. Bei separaten Kabinenbuchungen ist kein Platz erforderlich. Beide Buchungsarten teilen dieselbe Konfliktprüfung. Admins können Ressourcen anlegen, umbenennen, deaktivieren, wieder aktivieren und löschen. Den Typ einer bereits verwendeten Ressource kann man nicht ändern. Beim Löschen einer Ressource werden alle Buchungen, die sie verwenden, einschließlich aller weiteren Ressourcenzuordnungen dieser Buchungen, gelöscht. Dabei werden keine E-Mails versendet.

Admins können Ressourcen innerhalb ihrer Kategorie per Drag-and-drop in der Ressourcenverwaltung sortieren. Die gespeicherte Reihenfolge wird auch in den Kalendern und Buchungsdialogen verwendet; per Pfeiltasten am Sortiergriff lässt sie sich ebenfalls ändern.

Kalenderansichten:

- **Plätze:** Tagesraster mit Platzspalten (standardmäßig 08:00–20:00 Uhr; außerhalb dieses Zeitraums kann der restliche Tag eingeblendet werden) und Wochenansicht mit Tagen als Spalten.
- **Kabinen:** Tages- und Wochenansicht zeigt sowohl separat gebuchte Kabinen als auch die Kabinenbelegung an Platzterminen.
- **Fahrzeuge:** Tages- und Wochenansicht, standardmäßig 08:00–20:00 Uhr mit optional einblendbarem restlichen Tag; mehrtägige Einzelbuchungen sind möglich.
- Buchungskategorie und optionale öffentliche Zusatzinfo werden direkt im Kalender angezeigt.
- Verwalter und Admins können bestätigte Termine bearbeiten, die Kabinenbelegung einer Platzbuchung ändern oder Termine stornieren. Beim Stornieren eines einzelnen Platzes bleibt die Kabinenbelegung erhalten, solange die Buchung weitere Plätze enthält; mit dem letzten Platz wird der Termin samt Kabinen entfernt. Trainer können eigene offene Anfragen ändern oder zurückziehen.
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

Für eine neue lokale oder gehostete Datenbank zuerst `database/install.sql` und danach `database/seed_resources.sql` ausführen. Diese Installationsskripte sind für eine leere Datenbank vorgesehen. Bei einer bestehenden Datenbank vor einem Schema-Update eine Sicherung erstellen und das passende Skript in `database/migrations/` einmalig ausführen. `20261023_allow_user_deletion.sql` macht Buchungsreferenzen auf Benutzer nullable, damit sie bei einer Löschung auf `NULL` gesetzt werden können. `20261023_require_usernames.sql` vergibt für bisherige Konten ohne Benutzernamen einen eindeutigen Platzhalternamen und macht das Datenbankfeld verpflichtend. `20261023_cabins_belong_to_occurrences.sql` verschiebt Kabinenzuordnungen von einzelnen Platzzuordnungen auf Buchungstermine; falls eine Kabine innerhalb desselben Termins mehreren Plätzen zugeordnet war, wird sie dabei einmalig übernommen.

## Checks

Nach dem Start der Umgebung prüft der HTTP-Smoke-Test die Startseite, die Ressourcen-API und den öffentlichen Kalender:

```powershell
.\tests\smoke-index.ps1
```

Frontend-Bundle bauen:

```powershell
npm run build
```
