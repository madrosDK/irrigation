# irrigation V3.4

Fix-Version zur Master-/Kreis-Struktur.

## Wichtigste Änderungen

- Pumpe kann jetzt als Instanz oder direkt als boolesche Schaltvariable gewählt werden.
- Direkte Schaltvariable wird bevorzugt.
- Alte V3.1-Property `Pump` bleibt als Kompatibilität erhalten.
- Kreise können per Button direkt unter der Master-Instanz angelegt werden.
- Jeder Kreis kann zwei Aktoren verwenden.
- Jeder Kreis kann Aktor-Instanz oder direkte Bool-Schaltvariable verwenden.
- Automatik pro Kreis:
  - niedrigster Feuchtigkeitswert
  - Durchschnitt

## Wenn das Hauptmodul "Keine Kreise oder keine Pumpe konfiguriert" meldet

1. In der Master-Instanz eine Pumpe auswählen:
   - bevorzugt: `Pumpen-Schaltvariable Bool`
   - alternativ: `Pumpenaktor Instanz`

2. Danach im Master auf **Neuen Kreis unter dieser Instanz anlegen** klicken.

3. In jedem Kreis mindestens einen Aktor konfigurieren:
   - bevorzugt: direkte Bool-Schaltvariable
   - alternativ: Instanz

## Warum zwei Auswahlfelder?

Shelly und xComfort unterscheiden sich je nach Modul darin, ob zuverlässig über die Instanz oder über eine darunterliegende Status-/Schaltvariable geschaltet wird.

Die direkte Bool-Variable ist am zuverlässigsten.

## Objektbaum

```text
Bewässerung Master
├── Kreis 1
├── Kreis 2
└── Kreis 3
```


## Änderung V3.3

- Pro Kreis gibt es nur noch **Aktor 1** und **Aktor 2**.
- Beide Aktoren sind optional.
- Ein Aktor kann Shelly oder xComfort sein.
- Für jeden Aktor kann entweder die Instanz oder direkt die Bool-Schaltvariable gewählt werden.
- Die direkte Bool-Schaltvariable wird bevorzugt.
- Geschaltet wird immer über `RequestAction()` auf die Bool-Schaltvariable.
- Es gibt keinen `SetValue()`-Fallback mehr, da dadurch bei Shelly/xComfort oft nur der Variablenwert geändert wird, aber der Aktor nicht wirklich schaltet.


## Änderung V3.4

- Im Formular werden bei Pumpe und Kreisen nur noch Instanzen ausgewählt.
- Keine separate Bool-Schaltvariable mehr im Formular.
- Das Modul sucht selbstständig unter der Instanz die passende schaltbare Bool-Variable.
- Geschaltet wird weiterhin per `RequestAction()` auf diese Bool-Variable.
- Die Standarddauer im Master wurde entfernt.
- Jeder Kreis besitzt seine eigene Beregnungsdauer.
- Neu: Pumpe kann vor Ende des letzten Kreises um eine einstellbare Sekundenanzahl abgeschaltet werden.
- Neu angelegte Kreise erhalten Position `900 + Kreisnummer`, damit sie im Objektbaum unten stehen.


## Fix V3.4.1

- Fehlende Kompatibilitäts-Properties `Actuator1Variable` und `Actuator2Variable` wieder registriert.
- Alte Property-Namen aus V3.1-V3.3 bleiben intern kompatibel, erscheinen aber nicht im Formular.
- Kreis-Anlage robuster gemacht: Wenn `IPS_CreateInstance()` fehlschlägt, wird nicht mehr mit Instanz `0` weitergearbeitet.
