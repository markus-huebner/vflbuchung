# ADR 0001: Kabinen gehören zum Buchungstermin

- Status: Angenommen
- Datum: 2026-10-23

## Kontext

Kabinen waren bisher einzelnen Platzzuordnungen zugewiesen. Bei Buchungen mit mehreren Plätzen war die Kabinenbelegung dadurch mehrdeutig, obwohl eine Kabine für die gesamte Buchung reserviert wird. Zusätzlich werden separate Kabinenbuchungen ohne Platzzuordnung benötigt.

## Entscheidung

Jede Kabinenzuordnung wird am Buchungstermin gespeichert. Eine Platzbuchung benötigt weiterhin mindestens einen Platz; optionale Kabinen werden einmal für den gesamten Buchungstermin ausgewählt. Separate Kabinenbuchungen bleiben direkte Ressourcenzuordnungen einer Kabine ohne platzbezogene Kabinenzuordnung.

## Folgen

- Konfliktprüfung, Kabinenkalender, Bearbeitung, Löschung und Ressourcenlöschung berücksichtigen Kabinenzuordnungen auf Buchungsterminebene.
- Wird eine Platzzuordnung gelöscht, bleiben die Kabinen erhalten, solange weitere Platzzuordnungen bestehen. Mit dem Löschen des Buchungstermins werden auch seine Kabinenzuordnungen entfernt.
- Die Migration übernimmt eine Kabine nur einmal, falls sie im vorherigen platzbezogenen Schema innerhalb desselben Termins mehrfach zugeordnet war.
