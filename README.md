# irrigation

IP-Symcon Modul zur Bewässerungssteuerung mit MiFlora-Sensoren, Regenwerten und Aktoren für Ventile / Pumpe.

## Stand der V2

Diese Version bringt eine saubere GitHub- und Modulstruktur mit:

- vollständigem `library.json`
- Modulordner `IrrigationController`
- funktionierendem `form.json`
- sauberer `module.php`
- sichtbaren Status- und Spiegelvariablen im Objektbaum / Frontend
- HTML-Übersicht als kompakte Tabelle
- Automatiklogik mit Feuchteschwelle
- Regensperre über 24h-Regenwert
- Pumpenvorlauf vor den Ventilen
- manuellen Buttons im Konfigurationsformular
- zwei Wochenplänen:
  - `Zeitsteuerung`
  - `Automatik`

## Ordnerstruktur

```text
irrigation/
├── library.json
├── README.md
└── IrrigationController/
    ├── form.json
    ├── module.json
    └── module.php
```

## Funktionen

### Betriebsmodi
- **Aus**
- **Manuell**
- **Zeitsteuerung**
- **Automatik**

### Sensoren
- MiFlora Sensor 1
- MiFlora Sensor 2
- Regenmenge letzte 24h

### Aktoren
- Ventilaktor 1
- Ventilaktor 2
- Pumpenaktor

### Sichtbare Variablen im Frontend
- Betriebsmodus
- Beregnungsdauer
- Feuchteschwelle
- Regensperre
- Beregnung aktiv
- Pumpe aktiv
- Zone 1 aktiv
- Zone 2 aktiv
- Sensor 1 Wert
- Sensor 2 Wert
- Regen letzte 24 h
- Berechnete Feuchte
- Automatikentscheidung
- Letzte Aktion
- Übersicht (HTML-Tabelle)

## Automatiklogik

Die Automatik prüft:

1. Ist der Modus auf **Automatik**?
2. Ist eine Regensperre aktiv?
3. Liegt die Feuchte unter der eingestellten Schwelle?

Dann wird die Beregnung gestartet.

### Feuchtebewertung
- Mit **einem** Sensor: dieser Wert wird verwendet
- Mit **zwei** Sensoren:
  - Standard: der niedrigere Wert entscheidet
  - optional: Mittelwertbildung

## Hinweise zu den Wochenplänen

Die Wochenpläne werden als Objekte unter der Instanz angelegt und je nach Modus sichtbar / aktiv geschaltet.

- **Zeitsteuerung**: für Startzeiten im Modus `Zeitsteuerung`
- **Automatik**: für Prüfzeiten im Modus `Automatik`

Die Wochenpläne können anschließend in IP-Symcon bearbeitet werden.

## Installation

1. Repository in den Symcon-Modulordner kopieren
2. In IP-Symcon auf **Kerninstanzen → Module** gehen
3. Bibliotheken neu laden
4. `Irrigation Controller` installieren
5. Instanz anlegen
6. Sensoren und Aktoren im Konfigurationsformular auswählen

## Empfehlung für das bestehende Repo

Im bisherigen Repo liegen im Modulordner zusätzlich mehrere alte PHP-Dateien. Für die V2 sollte nur noch Folgendes verwendet werden:

- `library.json`
- `README.md`
- `IrrigationController/module.json`
- `IrrigationController/form.json`
- `IrrigationController/module.php`

Alte Dateien wie:
- `module_alpha.php`
- `module - Kopie.php`

sollten entfernt werden.

## Bekannte Grenze

Die Wochenpläne werden sauber angelegt und eingeblendet, die eigentliche zeitliche Pflege erfolgt aber in IP-Symcon. Falls du danach noch eine V3 willst, kann man zusätzlich echte zonenweise Startlogik, Regenhistorie, Sperrzeiten, Telegram-Meldungen und Fehlerüberwachung ergänzen.
