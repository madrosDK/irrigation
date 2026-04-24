# irrigation V3.1 – Master mit Pumpe und bis zu 10 Bewässerungskreisen

Diese Version trennt die Bewässerung in:

- **Irrigation Controller** = Master / Übermodul
- **Irrigation Zone** = einzelner Bewässerungskreis

## Neu in V3.1

- Pumpe sitzt im Hauptmodul
- Pumpe kann Shelly oder xComfort sein
- jeder Kreis hat **2 Aktoren / Ventile**
- pro Kreis wählbar:
  - niedrigster Feuchtigkeitswert
  - Durchschnitt der Feuchtesensoren
- Kreise laufen strikt nacheinander
- es läuft niemals mehr als ein Kreis gleichzeitig
- Automatik überspringt Kreise ohne Bewässerungsbedarf

## Ablauf

1. Master startet Sequenz
2. Master schaltet Pumpe EIN
3. Master wartet Pumpenvorlauf
4. aktueller Kreis schaltet Aktor 1 und Aktor 2 EIN
5. nach Ablauf der Kreiszeit werden beide Kreisaktoren AUS geschaltet
6. Master wartet Pause zwischen Kreisen
7. nächster Kreis wird gestartet
8. am Ende wird die Pumpe AUS geschaltet

Die Pumpe bleibt während der gesamten Sequenz eingeschaltet und wird erst am Ende bzw. bei Stop ausgeschaltet.

## Struktur

```text
irrigation/
├── library.json
├── README.md
├── IrrigationController/
│   ├── form.json
│   ├── module.json
│   └── module.php
└── IrrigationZone/
    ├── form.json
    ├── module.json
    └── module.php
```

## Objektbaum

Die Kreis-Instanzen müssen unter der Master-Instanz liegen:

```text
Bewässerung Master
├── Kreis 1 Rasen
├── Kreis 2 Hochbeet
├── Kreis 3 Hecke
```

## Debug

Beide Module verwenden `SendDebug()`.

Im IP-Symcon Debugfenster siehst du:
- Queue / Reihenfolge
- übersprungene Kreise
- Feuchteentscheidung
- Pumpenschaltung
- Aktorenschaltung
- gefundene schaltbare Bool-Variable bei Shelly/xComfort
