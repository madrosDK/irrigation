# irrigation V3 – Master mit Bewässerungskreisen

Diese Version trennt die Bewässerung in ein Übermodul und einzelne Kreise.

## Module

- `IrrigationController` = Master / Übermodul
- `IrrigationZone` = einzelner Bewässerungskreis

## Ablauf

Der Master verwaltet Betriebsmodus, Standarddauer, Pause zwischen den Kreisen und die Sequenz.

Jeder Kreis hat eigene Sensoren, Feuchteschwelle, Regensperre und einen eigenen Ventilaktor.

Im Automatikmodus wird jeder Kreis einzeln geprüft. Muss ein Kreis nicht bewässert werden, wird er übersprungen. Danach kommt der nächste Kreis. Es läuft niemals mehr als ein Kreis gleichzeitig.

## Ordnerstruktur

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

## Einrichtung

1. Master-Instanz `Irrigation Controller` anlegen.
2. Darunter bis zu 10 Instanzen `Irrigation Zone` anlegen.
3. In jeder Zone die Kreisnummer 1 bis 10 setzen.
4. Sensoren und Ventilaktor pro Zone auswählen.
5. Im Master die Sequenz starten oder später Wochenpläne verwenden.

## Debug

Beide Module nutzen `SendDebug()`.
Im IP-Symcon Debugfenster siehst du:

- welche Kreise erkannt werden
- welche Kreise übersprungen werden
- warum ein Kreis bewässern soll oder nicht
- welcher Aktor bzw. welche Bool-Variable geschaltet wird

## Hinweis

Die Wochenpläne werden angelegt und passend zum Betriebsmodus sichtbar geschaltet. Die eigentliche direkte Wochenplan-Auslösung kann in der nächsten Version noch ergänzt werden, sobald der Grundaufbau bei dir sauber läuft.
