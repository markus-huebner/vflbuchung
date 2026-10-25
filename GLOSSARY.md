# Glossar

- **Vorname und Nachname**: Getrennte Namensangaben eines Benutzers, die gemeinsam als vollständiger Name erscheinen.
- **Benutzername**: Eindeutiger Anmeldename eines Benutzers.
- **Buchungstermin (`booking_occurrence`)**: Ein datierter Termin einer Buchung, optional als Teil einer Serie. Er enthält Zeit, Status und alle für die gesamte Buchung geltenden Kabinenzuordnungen.
- **Platzzuordnung (`booking_resource_assignments`)**: Verknüpfung eines Buchungstermins mit einer Ressource. Eine Platzbuchung benötigt mindestens eine Platzzuordnung.
- **Kabinenzuordnung**: Optionale Zuordnung einer Kabine zu einer Platzbuchung. Sie gilt für die gesamte Platzbuchung und nicht für einen einzelnen Platz; eine eigenständige Kabinenbuchung gibt es nicht.
- **Buchungsserie (`booking_groups`)**: Gemeinsamer Wiederholungs- und Anfragendenkontext der Buchungstermine einer Serie.
