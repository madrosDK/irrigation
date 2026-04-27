# irrigation V3.5

Diese Version basiert für das Anlegen neuer Kreise wieder auf der V3.2-Logik.

## Wichtig

- Pumpe wird im Formular nur noch als Instanz ausgewählt.
- Aktor 1 und Aktor 2 werden im Kreis nur noch als Instanzen ausgewählt.
- Das Modul sucht selbstständig unter Shelly/xComfort die passende schaltbare Bool-Variable.
- Geschaltet wird per `RequestAction()` auf diese Bool-Variable.
- Keine separate Bool-Schaltvariable im Formular.
- Keine Standarddauer im Master.
- Jeder Kreis hat seine eigene Beregnungsdauer.
- Pumpe kann vor Ende des letzten Kreises um X Sekunden abgeschaltet werden.

## Kreis anlegen

Die Funktion `CreateZone()` ist wieder auf der V3.2-Basis:

```php
$zoneID = IPS_CreateInstance(self::MODULE_ID_ZONE);
IPS_SetParent($zoneID, $this->InstanceID);
IPS_SetName($zoneID, 'Kreis ' . $number);
IPS_SetProperty($zoneID, 'ZoneNumber', $number);
IPS_ApplyChanges($zoneID);
```

## Hinweis nach Update

Nach dem Kopieren:
1. Module neu laden
2. Master-Instanz öffnen
3. Änderungen übernehmen
4. Neue Kreise testweise anlegen

Falls noch alte, falsch platzierte Kreise im Root liegen, diese bitte löschen.


## Änderung V3.6 – Wochenplan-Logik

Die Wochenpläne lösen jetzt direkt die Sequenz aus.

### Zeitsteuerung

Wenn der Wochenplan **Zeitsteuerung** auf **Ein** schaltet:

- nur wenn Betriebsmodus = `Zeitsteuerung`
- nur der Schaltpunkt `Ein` wird ausgewertet
- `Aus` wird ignoriert
- alle aktiven Kreise werden nacheinander bewässert
- jeder Kreis läuft für seine eigene eingestellte Beregnungsdauer

### Automatik

Wenn der Wochenplan **Automatik** auf **Ein** schaltet:

- nur wenn Betriebsmodus = `Automatik`
- nur der Schaltpunkt `Ein` wird ausgewertet
- `Aus` wird ignoriert
- aktive Kreise werden einzeln geprüft
- Kreise mit `Automatik sagt nein` werden übersprungen
- Kreise mit Bewässerungsbedarf laufen nacheinander

### Laufende Sequenz

Wenn bereits eine Sequenz läuft und ein neuer Schaltpunkt kommt, wird dieser ignoriert.


## Fix V3.7 – Wochenplan-Auslösung

Die Wochenpläne hängen jetzt unter eigenen Trigger-Variablen:

- `Zeitsteuerung Trigger`
- `Automatik Trigger`

Der Wochenplan schaltet diese Variable auf `true`.
Dadurch läuft die Auswertung zuverlässig über `RequestAction()`.

Wichtig:
- Nur `Ein` startet eine Sequenz.
- `Aus` wird ignoriert.
- Nach `Ein` setzt das Modul den Trigger automatisch wieder auf `false`, damit der nächste Ein-Schaltpunkt wieder auslöst.


## Fix V3.7.1

- Fehlende Methoden `HandleScheduleTimer()` und `HandleScheduleAuto()` ergänzt.
- Fehler `Call to undefined method IrrigationController::HandleScheduleTimer()` behoben.


## Änderung V3.8 – keine Trigger-Schalter mehr

Die sichtbaren Trigger-Variablen wurden entfernt.

Die Wochenpläne liegen wieder direkt unter der Master-Instanz:

- `Zeitsteuerung`
- `Automatik`

Wenn der jeweilige Wochenplan auf **Ein** schaltet, startet automatisch die passende Sequenz:

- Modus `Zeitsteuerung` + Wochenplan `Zeitsteuerung` = alle aktiven Kreise nacheinander
- Modus `Automatik` + Wochenplan `Automatik` = nur aktive Kreise mit Feuchtebedarf

`Aus` wird weiterhin ignoriert.
