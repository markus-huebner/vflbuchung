# Platz- und Busbelegung

Responsives Buchungssystem für Fußballplätze, Kabinen und Vereinsbus. Öffentliche Besucher sehen bestätigte Buchungen und offene Buchungsanfragen ohne Anmeldung; interne Benutzer melden sich für Anfragen und Verwaltung an.

Eine ausführliche Anleitung zur Bedienung für öffentliche Besucher, Trainer, Verwalter und Admins findest du in der [Bedienungsanleitung](docs/bedienungsanleitung/README.md).

## Lokal mit Docker starten

1. Optional: Lege eine lokale Konfiguration an, um die Docker-Standardwerte zu überschreiben:

   ```powershell
   Copy-Item .env.example .env
   ```

2. Baue und starte die Anwendung:

   ```powershell
   docker compose up --build -d
   ```

   Den Webcontainer kannst du später mit folgendem Befehl neu starten:

   ```powershell
   docker compose restart web
   ```

3. Öffne [http://localhost:8100](http://localhost:8100). Wenn die Datenbank nicht erreichbar ist, öffnet sich zuerst ein Verbindungsassistent. Er erfasst Anwendungs- und Mail-Absenderdaten sowie Host, Port, Datenbankname und Zugangsdaten eines bereits angelegten MySQL-Datenbankbenutzers. Ein Root-Passwort wird nicht abgefragt. Nach einem erfolgreichen Verbindungstest kannst du die Angaben prüfen oder korrigieren und anschließend geschützt speichern. Die Einstellungen bleiben in einem separaten Docker-Volume erhalten. Bei einer leeren Datenbank öffnet sich danach die Einrichtung von Schema und erstem Administratorkonto; dafür ist kein Setup-Schlüssel erforderlich. Ein erkanntes aktuelles Datenbankschema wird nicht automatisch verändert; bei vorhandenen Anwendungstabellen kannst du die Daten behalten oder nach ausdrücklicher Bestätigung alle Anwendungstabellen neu anlegen lassen.

Die lokale Oberfläche läuft auf Port `8100`; phpMyAdmin ist unter `http://localhost:8101` und Mailpit unter `http://localhost:8103` erreichbar. MySQL ist auf Host-Port `8102` erreichbar. E-Mails werden lokal abgefangen und nicht an echte Empfänger versendet.

Die Entwicklungsumgebung auf `8100` erhält lokale Vorgaben ausschließlich aus `docker-compose.yml`: `APP_ENV=local`, URL `http://localhost:8100`, Datenbank `db:3306` mit `belegungsplan`/`webuser` und Mailpit auf Port `1025`. Diese Werte sind nur für die lokale Entwicklung gedacht und werden nicht in das Docker-Image oder die Anwendung eingebaut.

Die separate Umgebung unter `D:\projects\vflbuchung_www` läuft auf `http://localhost:8200` als Hoster-Nachbildung: Ihr Webcontainer bekommt keine `APP_*`-, `DB_*`- oder `MAIL_*`-Variablen aus Docker Compose. Das Host-Verzeichnis `D:\projects\vflbuchung_www\www\config` ist direkt eingebunden; die Anwendung liest und der Einrichtungsassistent schreibt dort die Datei `.env`. Änderungen an dieser Datei werden beim nächsten Request eingelesen. Die Anwendung wird ansonsten aus dem Image statt über Quellcode-Bind-Mounts bereitgestellt. Codeänderungen dort erfordern `docker compose up --build -d web`. MySQL (`8202`), phpMyAdmin (`8201`) und Mailpit (`8203`) bleiben getrennte Hilfsdienste; Schema und Ressourcen werden bei neuen Datenbank-Volumes nicht automatisch importiert. Bestehende Datenbanken bleiben unverändert.

Die 8200-Kopie enthält nur Laufzeitdateien und die nötige Docker-Konfiguration. Entwicklung, Tests, Frontend-Builds und Composer-Installation bleiben in diesem Projekt. Für Aktualisierungen werden PHP-Anwendung, fertige Bundles, Bilder, SQL-Dateien und `vendor` übernommen; `www/config` und Laufzeitdaten bleiben erhalten. Das Dockerfile der 8200-Kopie baut keine npm- oder Composer-Abhängigkeiten mehr.

MySQL führt Dateien in `/docker-entrypoint-initdb.d` nur aus, wenn das Datenverzeichnis erstmals initialisiert wird. Bei einem bestehenden Docker-Volume werden `install.sql` und `seed_resources.sql` nicht automatisch erneut ausgeführt. **Das Volume nicht mit `docker compose down -v` löschen**, wenn vorhandene Daten erhalten bleiben sollen.

Die Einstellungen werden nur nach erfolgreicher Prüfung von Datenbank und konfiguriertem Mail-Server sowie ausdrücklicher Bestätigung in `www/config/.env` gespeichert. Die SMTP-Prüfung verwendet die angegebene Verschlüsselung und Anmeldung, versendet aber keine Test-E-Mail; ohne SMTP-Host wird sie ausdrücklich übersprungen. Vor dem Speichern werden beide Verbindungen erneut geprüft. Bei ungültigen Angaben oder Verbindungsfehlern bleiben die bisherigen gespeicherten Einstellungen unverändert und die eingegebenen Werte einschließlich der Kennwörter für die Korrektur in der Sitzung erhalten. Kennwörter werden nicht im Formular ausgegeben; ein leeres Kennwortfeld verwendet den bisherigen Wert weiter. Das Root-Passwort wird nicht gespeichert. In der lokalen Docker-Umgebung bleibt die Datei im separaten Volume `setup_config` erhalten; wird die Verbindung später wieder unterbrochen, startet der Verbindungsassistent erneut.

## Rollen und Funktionen

- **Admin:** Benutzer, Ressourcen und Buchungsarten über das Benutzermenü am Kopfsymbol neben dem Namen verwalten sowie Anfragen bearbeiten und bestätigte Termine löschen.
- **Verwalter:** Buchungen direkt bestätigen, Anfragen freigeben oder ablehnen und bestätigte Termine löschen. In der Ansicht „Alle Anfragen“ werden genehmigte Serientermine je Serie als ein Eintrag angezeigt; direkt angelegte Buchungen von Verwaltern und Admins erscheinen dort nicht.
- **Trainer:** Trainings-, Spiel- und Busanfragen über die Schaltfläche in der Anfrageübersicht stellen sowie eigene offene Anfragen im gemeinsamen Buchungsdialog ändern oder löschen. Gelöschte offene Anfragen werden dauerhaft entfernt. Die Anfrageübersicht zeigt standardmäßig offene Anfragen; bei Bedarf kann auf alle Anfragen umgeschaltet werden. Anfragen für Termine, die mehr als drei Monate zurückliegen, werden nicht angezeigt. Längere Listen werden mit jeweils zehn Einträgen pro Seite angezeigt. Ein ausgewählter Eintrag markiert alle zugehörigen Buchungen und Ressourcenspalten im Kalender und bleibt in der Anfrageübersicht hellgelb hervorgehoben. Anfragen können keine Kabinen belegen.
- **Öffentliche Besucher:** bestätigte Belegungen und offene Anfragen für Plätze und Fahrzeuge einschließlich zugeordneter Kabinen ohne Anmeldung ansehen.

Es gibt keine öffentliche Registrierung. Jeder Benutzer hat einen eindeutigen Benutzernamen und meldet sich damit oder mit seiner E-Mail-Adresse an. Über **Mein Profil** kann jeder angemeldete Benutzer den eigenen Vor- und Nachnamen sowie die E-Mail-Adresse bearbeiten; der Benutzername ist dort nicht änderbar. Ein Admin lädt Benutzer per E-Mail-Einladungslink ein oder legt Benutzer ohne E-Mail-Adresse mit einem Startpasswort direkt an. Admins können Passwörter jederzeit in der Benutzerverwaltung ändern; Passwort-Reset-Links per E-Mail stehen nur Konten mit E-Mail-Adresse zur Verfügung. Benutzer können gelöscht werden; zugehörige Reservierungen und Buchungshistorie bleiben dabei erhalten, werden aber keinem Benutzer mehr zugeordnet. Der letzte aktive Administrator kann nicht gelöscht werden. Öffentliche Kalenderdaten enthalten keine Kontaktdaten, Fahrernamen oder internen Notizen.

Der Startbestand enthält sieben Plätze (`Platz 1 Süd`, `Platz 1 Nord`, `Platz 2`, `Platz 3`, `Platz 4 Ost`, `Platz 4 West`, `Platz 5`), sechs Kabinen (`Kabine 1` bis `Kabine 6`) und einen `Vereinsbus`. Eine Platzbelegung benötigt mindestens einen Platz; optionale Kabinen gehören zur gesamten Buchung und nicht zu einzelnen Plätzen. Verwalter und Admins können Kabinen zusammen mit einer Platzbuchung auswählen; Traineranfragen können keine Kabinen belegen. Eigene Kabinenbuchungen und eine separate Kabinenansicht gibt es nicht mehr. Plätze, Kabinen und Fahrzeuge dürfen mehrfach für denselben oder überlappende Zeiträume gebucht werden. Beim Anlegen, Bearbeiten und Freigeben gibt es keine Konfliktsperre und keine erforderliche Übersteuerung mehr. Bestehende Übersteuerungsprotokolle bleiben erhalten; eine Datenbankmigration ist für diese Änderung nicht erforderlich. Admins können Ressourcen anlegen, umbenennen, deaktivieren, wieder aktivieren und löschen. Den Typ einer bereits verwendeten Ressource kann man nicht ändern. Beim Löschen einer Ressource werden alle Buchungen, die sie verwenden, einschließlich aller weiteren Ressourcenzuordnungen dieser Buchungen, gelöscht. Dabei werden keine E-Mails versendet.

Bei Platzbelegungen wird die Kategorie direkt im Buchungsdialog ausgewählt. Die Standardkategorien sind Training, Spiel und externe Belegung; Admins können weitere Kategorien hinzufügen und Namen sowie Kalenderfarben im Bearbeitungsdialog aus zehn Grundfarben mit jeweils vier Helligkeitsstufen wählen. Die Farbpalette läuft im Raster von links nach rechts durch die Farbtöne und von oben nach unten von dunkel nach hell. Pro Kategorie lässt sich festlegen, ob Trainer Anfragen stellen dürfen; Training und Spiel sind dafür standardmäßig freigegeben, externe Belegung nicht. Die Freigabe wird auch serverseitig geprüft. Die Schriftfarbe der Kalendereinträge passt sich für einen gut lesbaren Kontrast automatisch an. Ungenutzte Kategorien werden endgültig gelöscht, bei vorhandenen Terminen dagegen archiviert und für neue Buchungen gesperrt, damit die Historie erhalten bleibt. Für alle Kategorien stehen dieselben Angaben zur Verfügung; die Spiel-spezifischen Felder Gegner und Spielort werden nicht abgefragt. Plätze und optionale Kabinen werden über gleich gestaltete Schaltflächen in jeweils drei Spalten ausgewählt.

„Mannschaft / Hinweis“ ist bei allen Buchungsarten beim Anlegen und Bearbeiten verpflichtend und kann mehrzeilig eingegeben werden; leere Angaben oder reine Leerzeichen werden auch serverseitig abgelehnt.

Für Beginn und Ende gibt es ein gemeinsames Datumsfeld sowie getrennte Uhrzeitfelder. Neue Buchungen sind mit 90 Minuten Dauer vorbelegt. Wird das Datum oder die Anfangszeit geändert, verschiebt sich das Ende mit gleicher Dauer; eine manuell geänderte Endzeit legt die neue Dauer fest.

Der Zeitraum oberhalb des Kalenders öffnet eine direkte Datumsauswahl. Derselbe gestaltete Monatskalender ist über die Kalenderschaltflächen neben den Datumsfeldern erreichbar, auch für das Ende einer Serie. Monat und Jahr können direkt gewählt werden; Pfeiltasten, Home/End und PageUp/PageDown unterstützen die Tastaturbedienung. Neue Buchungen übernehmen den im Kalender ausgewählten Tag.

Wenn die Anfrageübersicht angezeigt wird, lässt sich ihre Breite am Trenner zwischen Belegungsplan und Übersicht per Drag-and-drop oder Pfeiltasten ändern. Die Aufteilung und die Auswahl zwischen Platzbelegung und Fahrzeugkalender bleiben im jeweiligen Browserfenster auch nach einer Aktualisierung erhalten.

Anlegen und Bearbeiten verwenden denselben Buchungsdialog. Verwalter und Admins können dort Kategorie, Ressourcen, Kabinen, Titel, Zeiten, interne Notizen und bei Fahrzeugen Ziel, Zweck und Fahrer ändern. Trainer können alle bei einer Anfrage verfügbaren Angaben einschließlich Kategorie, Plätzen, Zeiten, interner Notiz und Wiederholung ändern; Kabinen stehen für Anfragen nicht zur Verfügung. Bei Serien gilt die Auswahl „nur diesen Termin“, „diesen und folgende“ oder „alle Termine“ für sämtliche Änderungen. Eine geänderte Wiederholungsregel erzeugt die Termine innerhalb dieses Umfangs neu und entfernt dort nicht mehr enthaltene Termine; außerhalb des Umfangs bleiben Buchungen und abgelehnte Termine unverändert. Unveränderte Serienregeln erhalten vorhandene Ausnahmen. Ungültige Eingaben führen weiterhin zum vollständigen Zurückrollen der Änderung; zeitliche Überschneidungen sind erlaubt.

Nach erfolgreichem Speichern einer Buchung oder Anfrage schließt sich der Bearbeitungsdialog automatisch. Bei Speicherfehlern bleibt er zur Korrektur geöffnet; Hinweise zu Mail- oder Aktualisierungsfehlern bleiben sichtbar.

Ein Klick auf einen Kalendereintrag zeigt die Buchungsangaben auch ohne Wechsel in den Bearbeitungsdialog: Mannschaft / Hinweis, Kategorie, die zusammengefasste Liste „Gebuchte Plätze“, Kabinen, Beginn und Ende mit Datum sowie die Wiederholungsregel mit Intervall, Wochentagen und Serienende. Ein zusätzliches Feld „Ressource“ wird nicht angezeigt. Zeilenumbrüche im Feld Mannschaft / Hinweis bleiben in Kalendern, Details und Anfrageübersichten erhalten. Bei Fahrzeugen werden Ziel und Zweck angezeigt. Fahrer und interne Notizen sind ausschließlich für Verwalter und Admins sichtbar.

Admins können Ressourcen innerhalb ihrer Kategorie per Drag-and-drop in der Ressourcenverwaltung sortieren. Die gespeicherte Reihenfolge wird auch in den Kalendern und Buchungsdialogen verwendet; per Pfeiltasten am Sortiergriff lässt sie sich ebenfalls ändern.

Kalenderansichten:

Das Burger-Menü rechts oben am Belegungsplan enthält Kalender- und Ansichtseinstellungen: Radiobuttons wählen Platzbelegung oder Fahrzeugkalender sowie Tag oder Woche; „Ganzen Tag anzeigen“ bleibt eine Checkbox. Nach einer Auswahl schließt sich das Menü automatisch. Ausgewähltes Datum und Tages-/Wochenansicht bleiben beim Kalenderwechsel erhalten.

Auf Handy und Desktop stehen dieselben beiden Kalendermenüs zur Verfügung: **+** öffnet für angemeldete Benutzer die Auswahl zum Anlegen einer Platz- oder Fahrzeugbuchung beziehungsweise einer Traineranfrage; das **Burger-Menü** enthält Kalender- und Ansichtseinstellungen und schließt sich nach einer Auswahl. „Ganzen Tag anzeigen“ ist nur in der Tagesansicht aktiv. Es ist immer nur eines der beiden Kalendermenüs geöffnet. Die Kopfzeile zeigt angemeldet das Kopfsymbol und daneben den Namen als normalen Text außerhalb des Buttons; das Symbol öffnet für Admins zuerst „Ressourcen“, „Benutzerverwaltung“ und „Buchungsarten“, dann eine Trennlinie, „Mein Profil“ und „Abmelden“. Ohne Anmeldung erscheint nur „Anmelden“ und kein Anlegen-Menü.

- **Plätze:** Tagesraster mit Platzspalten (standardmäßig 09:00–21:00 Uhr; außerhalb dieses Zeitraums kann der restliche Tag eingeblendet werden) und Wochenansicht mit Tagen als Spalten. Die Spalten teilen die verfügbare Breite gleichmäßig; feste Mindestbreiten werden vermieden, damit horizontaler Bildlauf möglichst entfällt. Stundenzeilen sind 51,2 statt 64 Pixel hoch; Eintragspositionen und Mindesthöhen verwenden denselben Maßstab.
- **Kabinen:** Zugeordnete Kabinen erscheinen direkt an den Platzterminen und in deren Buchungsdialog.
- **Fahrzeuge:** Tages- und Wochenansicht, standardmäßig 09:00–21:00 Uhr mit optional einblendbarem restlichen Tag; mehrtägige Einzelbuchungen sind möglich.
- Buchungskategorie und optionale öffentliche Zusatzinfo werden direkt im Kalender angezeigt.
- Gleichzeitige und überlappende Platztermine stehen im Tagesraster nebeneinander; die Platzspalte wird bei Bedarf breiter und der Kalender horizontal scrollbar. Die Wochenansicht listet sämtliche Einträge. Im Fahrzeug-Tagesraster stehen die Fahrzeuge als Spalten mit Namen oben und ihren Buchungen darunter; die Wochenansicht entspricht der Platzbelegung mit Tagen als Spalten und Fahrzeugen als Zeilen.
- Bei weniger als 110 Pixel verfügbarer Inhaltsbreite werden Kategorie und Serienkennzeichnung ausgeblendet; bei höhenbegrenzten Tageskarten gilt dies auch unter 52 Pixel Inhaltshöhe. Größere Karten zeigen beide Angaben wieder; Tooltip, zugängliche Beschreibung und Buchungsdetails behalten die Informationen. Wochenkarten passen ihre Höhe weiterhin an ihren Inhalt an.
- Offene Anfragen sind für alle sichtbar, auch ohne Anmeldung. Sie tragen zusätzlich zur unveränderten Kategorie die Kennzeichnung „Anfrage“, eine aufgehellte, weiterhin gut erkennbare Kategoriefarbe und den normalen Kalendereintragsrand. Die Legende zeigt die helle Anfragefarbe für jede Kategorie mit der Beschriftung „Anfrage“. Freigegebene Anfragen wechseln zur normalen Darstellung; abgelehnte und früher wegen Konflikten ausgelassene Anfragen bleiben ausgeblendet. Gelöschte Anfragen werden dauerhaft aus dem System entfernt. Interne Notizen und Fahrernamen bleiben geschützt. Mehrere Plätze eines Buchungstermins erscheinen gemeinsam als eine Buchungsanfrage; Freigabe und Ablehnung aus der Übersicht betreffen alle noch offenen Plätze. Die Freigabe öffnet den Bearbeitungsdialog, in dem die Angaben geprüft und angepasst werden können.
- Verwalter und Admins können bestätigte Termine bearbeiten, die Kabinenbelegung einer Platzbuchung ändern oder Termine löschen. Beim Löschen eines einzelnen Platzes bleibt die Kabinenbelegung erhalten, solange die Buchung weitere Plätze enthält; mit dem letzten Platz wird der Termin samt Kabinen entfernt. Trainer können eigene offene Anfragen ändern oder zurückziehen.
- **Terminarten:** Training (grün), Spiel (blau), externe Belegung (orange) und Fahrzeugbuchung (violett).
- Buchungszeiten verwenden 15-Minuten-Schritte und die Zeitzone `Europe/Berlin`. Platztermine enden am selben Tag; Busbuchungen dürfen mehrtägig sein.
- Platzserien unterstützen tägliche, wöchentliche, monatliche und jährliche Wiederholung. Serien sind auf 5.000 Termine begrenzt.

## Hosting-Konfiguration

### Übliche Webserver

Der Webserver muss auf `www/` als DocumentRoot zeigen. Alle Laufzeitdateien einschließlich `app/`, `config/`, `database/` und `vendor/` liegen innerhalb von `www/`; `.htaccess` sperrt den direkten Zugriff auf diese Verzeichnisse. In der Produktion liegt die aktive `.env`-Datei unter `www/config/.env`. Die Beispiele `.env.example` und `.env.production.example` liegen im Projektstamm und gehören nicht nach `www/config/`.

Die Anwendung liest die aktive `.env` aus `www/config/.env`. Es gibt keine automatischen Konfigurationsvorgaben: Fehlen Werte oder erforderliche Angaben, startet der Einrichtungsassistent. Er erfasst Anwendungsumgebung (`APP_ENV`), URL, sämtliche SMTP- und Absenderangaben sowie die Datenbankverbindung und speichert die geprüften Einstellungen dort. Das Verzeichnis `www/config/` muss dafür vom PHP-Prozess beschreibbar sein. Ohne SMTP können `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` und `MAIL_ENCRYPTION` ausdrücklich leer gespeichert werden. Mit SMTP muss der Port angegeben werden; es wird weder ein Port noch eine Verschlüsselung automatisch ergänzt.

Nur die lokale Entwicklungsumgebung auf `8100` ergänzt fehlende Werte durch Docker-Compose-Vorgaben; die `.env` im Projektstamm kann diese überschreiben (Vorlage: `.env.example`). Die Anwendung selbst und das Dockerfile enthalten keine Konfigurationsvorgaben. Auf `8200` und beim Webhoster werden alle Anwendungseinstellungen ausdrücklich im Assistenten oder in `www/config/.env` gesetzt. Gespeicherte Assistenten-Einstellungen haben Vorrang vor Prozessvariablen, danach folgen `www/config/.env` und die optionale `www/config/hosting.php`. Eine Produktionsumgebung ohne `www/config/.env` startet ebenfalls den Assistenten.

| Variable | Beschreibung |
| --- | --- |
| `APP_ENV` | `production` auf dem Hoster; `local` lokal |
| `APP_URL` | Öffentliche Basis-URL, z. B. `https://verein.example` |
| `DB_HOST`, `DB_PORT` | Datenbankserver und Port |
| `DB_NAME` | Explizit festzulegender Datenbankname |
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

Erzeuge die Abhängigkeiten mit `composer install --working-dir=www --no-dev --optimize-autoloader` und lade anschließend den Inhalt von `www/` einschließlich `www/assets/build/` und `www/vendor/` in das DocumentRoot hoch. Lege die aktive Produktionskonfiguration als `www/config/.env` ab. Die Beispiel-Dateien bleiben im Repository-Stamm und werden nicht nach `www/config/` kopiert.

### InfinityFree (kostenloses Hosting)

Bei InfinityFree ist `htdocs/` das festgelegte Webverzeichnis. Das Paket enthält alle Laufzeitdateien und die aktive `.env` unter `htdocs/config/.env`; der direkte HTTP-Zugriff auf `config/` ist gesperrt. Starte den Assistenten bevorzugt in Git Bash aus dem Projektverzeichnis. In WSL müssen Node/npm, Composer und die Frontend-Abhängigkeiten ebenfalls in der WSL-Umgebung verfügbar sein:

```bash
bash scripts/deploy-infinityfree.sh
```

Er führt durch Datenbankeinrichtung, Upload und phpMyAdmin-Import. Zugangsdaten werden lokal erfasst; FTP-Zugangsdaten gehören ausschließlich in FileZilla. Teile sie nicht im Chat und lade die lokale Datei `.infinityfree-deploy/.deploy-state` niemals hoch.

Für InfinityFree muss die Website über HTTPS erreichbar sein. Die kostenlose Hosting-Datenbank lässt keine Verbindungen von deinem lokalen Rechner zu; importiere `www/database/install.sql` und danach `www/database/seed_resources.sql` über phpMyAdmin. E-Mails benötigen einen externen SMTP-Anbieter; ohne SMTP-Konfiguration funktionieren Einladungen und Passwort-Reset nicht.

## Datenbankinstallation

`www/database/install.sql` enthält das vollständige Schema einschließlich der Ressourcenkategorien-Tabelle mit den Kategorien „Platz“, „Fahrzeug“ und „Kabine“. `www/database/seed_resources.sql` fügt anschließend die Startressourcen ein. Der Web-Assistent kann diese Skripte beim ersten Einrichten ausführen. Bei vorhandenen Tabellen ergänzt die Option „Daten behalten“ fehlende Tabellen und Standardressourcen und aktualisiert das Buchungskategorieschema additiv, ohne Anwendungstabellen zu löschen. Die Option „Anwendungstabellen neu anlegen“ löscht alle Benutzer, Buchungen und Ressourcen in den Anwendungstabellen und ist nur mit einer zusätzlichen Bestätigung verfügbar; andere, nicht zur Anwendung gehörende Datenbanktabellen werden nicht gelöscht.

Alternativ zum Web-Assistenten können für eine neue lokale oder gehostete Datenbank zuerst `www/database/install.sql` und danach `www/database/seed_resources.sql` manuell ausgeführt werden. Beim Start wird ein unvollständiges Anwendungsschema erkannt und nicht automatisch verändert. Im Einrichtungsassistenten ergänzt „Daten behalten“ fehlende Tabellen und Schemaänderungen; nach ausdrücklicher Bestätigung können stattdessen alle Anwendungstabellen gelöscht und mit dem aktuellen Schema neu angelegt werden. Eine separate Sammlung von Migrationsskripten gibt es nicht; additive Schemaänderungen sind im Installationsskript enthalten.

## Checks

Die Ersteinrichtung und beide Datenbankoptionen in einer eigens angelegten Testdatenbank prüfen:

```powershell
.\tests\database-setup.ps1
```

Der Test löscht ausschließlich die Datenbank `vflbuchung_setup_test` und entfernt sie danach wieder. Die Anwendungsdatenbank bleibt unverändert.

Fehlgeschlagene Verbindungsprüfungen, erhaltene Setup-Eingaben und die SMTP-Anmeldung prüfen:

```powershell
php .\tests\database-connection-setup.php
php .\tests\mail-setup.php
```

Der Mail-Test benötigt eine erreichbare konfigurierte Datenbank (nur Verbindungsprüfung, keine Datenänderungen) und startet einen temporären lokalen SMTP-Testserver. Er prüft Anmeldung, falsche Zugangsdaten, Verschlüsselung und die erneute SMTP-Prüfung vor dem Speichern, ohne E-Mails zu versenden oder Einstellungen zu überschreiben.

Den Buchungsdialog und die rollenabhängigen Kategorien prüfen:

```powershell
php .\tests\booking-dialog.php
```

Die automatische Anpassung der Buchungszeiten prüfen:

```powershell
node --test .\tests\booking-dates.test.mjs .\tests\date-picker.test.mjs .\tests\event-details.test.mjs .\tests\calendar-layout.test.mjs .\tests\calendar-actions.test.mjs .\tests\calendar-navigation.test.mjs
```

Mit laufender Umgebung auf `8100` und installiertem Google Chrome prüft der Browser-Test alle Rollen bei 320 und 390 Pixel Handybreite sowie den Wechsel zur Desktop-Ansicht. Er verwendet gerenderte Rollen-Fixtures, prüft das Burger-Menü mit Radiobuttons und Tages-Checkbox sowie das rollenabhängige Benutzermenü und verändert keine Buchungen:

```powershell
node .\tests\calendar-menu-browser.mjs
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
