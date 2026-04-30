# IP-Symcon Irrigation v5.0 Build 1

Neue Architektur:

- `IrrigationController` = Master / globale Bewässerungssteuerung
- `IrrigationArea` = Bewässerungszone mit eigenem Betriebsmodus und eigener Kreis-Sequenz
- `IrrigationZone` = einzelner Bewässerungskreis mit Aktoren, Laufzeit und Sensorik

Struktur:

```text
IrrigationController/
IrrigationArea/
IrrigationZone/
library.json
README.md
```

Wichtig: ZIP direkt nach `C:\ProgramData\Symcon\modules\irrigation\` entpacken. Kein zusätzlicher Unterordner.

Status: erster v5-Umbauentwurf. Bitte in Testsystem prüfen, bevor bestehende v4-Installation ersetzt wird.
