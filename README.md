# irrigation V3.3

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
