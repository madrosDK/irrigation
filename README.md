# irrigation

IP-Symcon Modul zur Bewässerungssteuerung mit MiFlora-Sensoren, Regenwerten und Aktoren für Ventile / Pumpe.

## Stand der V2.1

Diese Version ist gegenüber der vorherigen Fassung bewusst schlanker:

- **keine HTML-Übersicht**
- **kein Modus `Aus`**
- Betriebsmodi nur noch:
  - Manuell
  - Zeitsteuerung
  - Automatik

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

## Sichtbare Variablen im Frontend
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

## Funktionen
- Manuelle Beregnung start / stop
- Automatikprüfung über Feuchte
- Regensperre über 24h-Regenwert
- Pumpenvorlauf vor Ventilen
- zwei Wochenpläne:
  - Zeitsteuerung
  - Automatik

## Installation
1. Dateien in dein GitHub-Repo bzw. Symcon-Modulverzeichnis kopieren
2. Bibliothek neu laden
3. Modul installieren
4. Instanz anlegen
5. Sensoren und Aktoren auswählen

## Hinweis
Alte Dateien wie `module_alpha.php` oder `module - Kopie.php` sollten entfernt werden.
