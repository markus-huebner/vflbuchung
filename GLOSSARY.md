# Glossar

- **Buchungstermin (`booking_occurrence`)**: Ein datierter Termin einer Buchung, optional als Teil einer Serie. Er enthält Zeit, Status und alle für die gesamte Buchung geltenden Kabinenzuordnungen.
- **Platzzuordnung (`booking_resource_assignments`)**: Verknüpfung eines Buchungstermins mit einer Ressource. Eine Platzbuchung benötigt mindestens eine Platzzuordnung.
- **Kabinenzuordnung (`booking_cabin_assignments`)**: Verknüpfung eines Buchungstermins mit einer Kabine. Sie gilt für die gesamte Platzbuchung und nicht für einen einzelnen Platz.
- **Separate Kabinenbuchung**: Buchungstermin mit einer oder mehreren direkt gebuchten Kabinen und ohne Platzzuordnung. Diese Buchungsart bleibt unterstützt.
- **Buchungsserie (`booking_groups`)**: Gemeinsamer Wiederholungs- und Anfragendenkontext der Buchungstermine einer Serie.
