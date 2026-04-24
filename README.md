# irrigation V3.2

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
