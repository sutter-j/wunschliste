# Beispieldaten

Eine fertige Wunschliste zum Ausprobieren: «Weihnachtswunschliste 2026» mit drei Tags
(Gemeinsam, Mia, Elio) und fünf Wünschen, einer davon schon reserviert.
Die Bilder sind selbst gezeichnete Illustrationen, keine Produktfotos.

Einspielen (überschreibt vorhandene Daten):

```
cp beispiel/*.json data/
cp beispiel/bilder/* uploads/
```

Die Liste hat kein Gäste-Passwort, sie ist also direkt offen. Im Admin unter
«Wunschlisten» lässt sich eines setzen.

Wieder loswerden: die Dateien in `data/` löschen, beim nächsten Aufruf legt die Seite
eine leere Liste an.
