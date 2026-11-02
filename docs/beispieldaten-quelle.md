# Quelle der Beispieldaten

Stand des Abrufs: **02.11.2026**. Der SQL-Snapshot in `www/database/seed_example_bookings.sql` ersetzt die bisherigen synthetischen Beispieldaten durch öffentlich sichtbare, datierte Ressourcenbelegungen von [belegungsplan.vflkaufering.de](https://belegungsplan.vflkaufering.de/). Es wurden ausschließlich öffentliche GET-Ansichten gelesen; Anmeldungen und schreibende Zugriffe auf die Quellwebsite fanden nicht statt.

## Umfang und Primärquellen

| Monat | Veröffentlichte Ressourcenbelegungen | Monatsansicht |
|---|---:|---|
| Oktober 2026 | 207 | [Quelle](https://belegungsplan.vflkaufering.de/index.php?view=month&view_all=1&page_date=2026-10-01&area=1&room=2) |
| November 2026 | 156 | [Quelle](https://belegungsplan.vflkaufering.de/index.php?view=month&view_all=1&page_date=2026-11-01&area=1&room=2) |
| Dezember 2026 | 10 | [Quelle](https://belegungsplan.vflkaufering.de/index.php?view=month&view_all=1&page_date=2026-12-01&area=1&room=2) |
| Januar 2027 | 0 | [Quelle](https://belegungsplan.vflkaufering.de/index.php?view=month&view_all=1&page_date=2027-01-01&area=1&room=2) |
| Februar 2027 | 36 | [Quelle](https://belegungsplan.vflkaufering.de/index.php?view=month&view_all=1&page_date=2027-02-01&area=1&room=2) |
| März 2027 | 28 | [Quelle](https://belegungsplan.vflkaufering.de/index.php?view=month&view_all=1&page_date=2027-03-01&area=1&room=2) |
| Gesamt | **437** | |

Die Monatsansichten veröffentlichen Datum, Platz, Originaltitel, Typcode und Serienmarker in `#month_main`. Die Ressourcen-IDs lauten: 2 = Platz 1 Süd, 1 = Platz 1 Nord, 3 = Platz 2, 4 = Platz 3, 5 = Platz 4 West, 7 = Platz 4 Ost, 6 = Platz 5. Diese Zuordnung steht in `data-room` und den Überschriften der [öffentlichen Tagesansicht](https://belegungsplan.vflkaufering.de/index.php?view=day&view_all=1&page_date=2026-10-01&area=1&room=2).

Ein Eintrag pro Platz bleibt ein eigener Ressourcenbelegungsdatensatz. Gleich betitelte Belegungen auf mehreren Plätzen werden nicht automatisch zusammengelegt: Zum Beispiel hat U15 am 01.10.2026 auf Süd die öffentlich sichtbare Buchungs-ID 11019 und auf Nord die ID 11347 ([Tagesansicht](https://belegungsplan.vflkaufering.de/index.php?view=day&view_all=1&page_date=2026-10-01&area=1&room=2)). Deshalb bedeuten 437 Ressourcenbelegungen nicht zwingend 437 unterschiedliche sportliche Veranstaltungen.

Im Januar sind keine Einträge sichtbar; im März endet die sichtbare Belegung am **13.03.2027**. Diese Lücken werden auf ausdrücklichen Nutzerwunsch unverändert übernommen. Serienmarker werden nur dokumentiert, nicht als Grundlage für zusätzliche Wiederholungen verwendet. Eine vollständige Saisonplanung oder allgemeine Winterpause lässt sich aus den Ansichten nicht belegen.

## Buchungsarten

Das eingebundene [Original-Stylesheet](https://belegungsplan.vflkaufering.de/css/mrbs.css.php?v=1713803880) definiert `.A {background: #ffff99}`, `.B {background: #99cccc}` und `.C {background: #ff8d33}`. Die ausgeschriebenen Bedeutungen wurden vom Nutzer bestätigt: **Gelb = Training, Blau = Spiel, Orange = Extern**.

| Quellcode | Quellfarbe | Lokale Kategorie | Anzahl |
|---|---|---|---:|
| A | Hellgelb `#ffff99` | `training` | 311 |
| B | Blaugrün `#99cccc` | `match` | 92 |
| C | Orange `#ff8d33` | `external` | 26 |
| A, Titel exakt `TuL` | Hellgelb `#ffff99` | `external` (Nutzerzuordnung) | 8 |

Die acht Einträge mit dem exakten Titel `TuL` bleiben auf dem in der Quelle ausgewiesenen Platz 5 und werden lokal als `external` statt `training` geführt. Die [öffentliche Tagesansicht vom 06.10.2026](https://belegungsplan.vflkaufering.de/index.php?view=day&view_all=1&page_date=2026-10-06&area=1&room=2) zeigt `TuL` in `data-room="6"`; die Überschrift dieser Spalte lautet `Platz 5`. Der Quelltyp `A` ist im Snapshot dokumentiert, aber nicht in den Buchungsnotizen gespeichert. Der zusammengesetzte Originaltitel `U17w/TuL` wird davon nicht erfasst und bleibt `training`.

Die lokale Kalenderfarbpalette bleibt unverändert. Quellcode, Monats-URL und ursprünglicher Serienmarker sind im Snapshot und dieser Dokumentation festgehalten, nicht in den internen Buchungsnotizen. Originaltitel wie `Senioren 1`, `U12/2`, `U17w/TuL` und Spielgegner werden unverändert im Feld **Mannschaft / Hinweis** übernommen.

## Zeitgenauigkeit und Grenzen

Die Quelle zeigt ein **30-Minuten-Raster von 09:00 bis 21:30 Uhr**, belegt durch `data-resolution="1800"`, die Zeitachse und 25 sichtbare Zeitintervalle der [Tagesansicht](https://belegungsplan.vflkaufering.de/index.php?view=day&view_all=1&page_date=2026-10-01&area=1&room=2). Die Monatszellen enthalten belegte und freie Abschnitte mit entsprechendem `flex`-Gewicht; die importierten Grenzen sind daraus abgeleitete sichtbare Rasterzeiten.

Die Termine `U19 SG 5-Seen-Land` am [09.10.2026](https://belegungsplan.vflkaufering.de/index.php?view=day&view_all=1&page_date=2026-10-09&area=1&room=2) und `U19 TSV Gilching/Argelsried` am [23.10.2026](https://belegungsplan.vflkaufering.de/index.php?view=day&view_all=1&page_date=2026-10-23&area=1&room=2) reichen bis an die sichtbare Grenze 21:30. Weitere Einträge beginnen um 09:00. Ein intern früherer Beginn oder späteres Ende dieser Randtermine kann nicht ausgeschlossen werden.

[Buchungsdetails](https://belegungsplan.vflkaufering.de/view_entry.php?view=day&year=2026&month=10&day=1&area=1&id=11019) verlangen eine Anmeldung. Daher werden weder minutengenaue interne Zeitstempel noch vollständige Wiederholungsregeln behauptet. In den geprüften Ansichten gab es keinen öffentlich verlinkten Volltag-Umschalter.

Die veröffentlichten Unix-Zeitachsen der [Oktober-](https://belegungsplan.vflkaufering.de/index.php?view=month&view_all=1&page_date=2026-10-01&area=1&room=2) und [Märzansicht](https://belegungsplan.vflkaufering.de/index.php?view=month&view_all=1&page_date=2027-03-01&area=1&room=2) sind mit den Sommer-/Winterzeit-Offsets von `Europe/Berlin` vereinbar. Der IANA-Zonenname selbst ist keine nachgewiesene Quellkonfiguration, sondern die bestehende Projektkonvention für die UTC-Speicherung.

## Übernahmeentscheidungen

Die bestätigte Vorgabe ist die Übernahme der veröffentlichten Belegungen einschließlich Originalzeiten und Lücken. Die vorherigen synthetischen Regeln zu festen Trainingsstarts, 90-/150-Minuten-Dauern, zwei Trainingsterminen pro Mannschaft, Wochentagen, Platz-2-Reservierung, Winterpausen und zusätzlichen DFB-/TuL-Terminen werden deshalb nicht auf die Quelle angewandt. Es werden ausschließlich deren veröffentlichte datierte Vorkommen übernommen.

Der Import wird ausdrücklich im Einrichtungsassistenten ausgewählt oder nach der Einrichtung manuell über phpMyAdmin ausgeführt. Er ist nur bei leerem Buchungsbestand erlaubt: Sobald `booking_groups` oder `booking_occurrences` Einträge enthalten, bricht er ohne Datenänderungen ab. Auch ein zweiter Import wird abgewiesen. Es werden keine Importzuordnungen und keine Quellschlüssel in der Datenbank gespeichert. Die Kennzeichnungen im SQL-Snapshot dienen nur der temporären Importplanung und der Quellenprüfung. Jede importierte Buchung hat leere interne Notizen; Quellen, Typcodes und Einschränkungen stehen in dieser Dokumentation statt in den Buchungsnotizen. Bereits vorhandene Buchungen werden weder aktualisiert noch entfernt.
