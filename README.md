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


## Änderung V3.10 – Wochenplan-Aktion mit direktem PHP-Code

Die interne Trigger-Varianten wurden rückgängig gemacht.

Die Wochenpläne liegen direkt unter der Master-Instanz.

Bei der Aktion **Ein** wird direkt PHP-Code hinterlegt:

- Zeitsteuerung:
  `IRR_StartManualSequence(...)`

- Automatik:
  `IRR_StartAutomaticSequence(...)`

Die Aktion **Aus** bleibt leer und wird nicht verwendet.


## Fix V3.11 – Kreise aktualisieren setzt Status neu

Der Button **Kreise aktualisieren** ruft jetzt zusätzlich auf:

- `UpdateWeekplanVisibility()`
- `UpdateStatus()`

Damit wird die Master-Instanz sofort wieder aktiv, sobald Kreise und Pumpe vorhanden sind.
Es ist nicht mehr nötig, erst eine Formulareigenschaft wie den Pumpenaktor zu ändern.

Zusätzlich wurde die Wochenplan-Aktion vereinfacht:
- Zeitsteuerung Ein: `IRR_StartManualSequence(...)`
- Automatik Ein: `IRR_StartAutomaticSequence(...)`


## Fix V3.12 – Aktor 2 wird wirklich gemeinsam geschaltet

- `StartZone()` schaltet Aktor 1 und Aktor 2 jetzt explizit nacheinander.
- `StopZone()` schaltet Aktor 1 und Aktor 2 explizit nacheinander aus.
- Die Statusvariablen `Aktor 1 aktiv` und `Aktor 2 aktiv` werden nur noch auf `true` gesetzt, wenn der Schaltbefehl erfolgreich abgesetzt wurde.
- Debug zeigt jetzt pro Aktor:
  - gewählte Instanz
  - gefundene Bool-Schaltvariable
  - Kandidaten unter Shelly/xComfort
  - Erfolg oder Fehler von `RequestAction()`


## Fix V3.13 – Aktor 2 mit identischer Universal-Logik

- Aktor 1 und Aktor 2 verwenden jetzt dieselbe gemeinsame Resolver-Logik.
- Für beide Aktoren wird zuerst die konfigurierte Instanz genommen.
- Darunter sucht das Modul die passende boolesche Schaltvariable.
- Die Auswahl bevorzugt Variablen mit `VariableAction`.
- Diagnosevariablen wie Online, Connected, Error, Battery usw. werden verworfen.
- Debug zeigt für Aktor 2 dieselbe Auswertung wie für Aktor 1.


## Fix V3.14 – Timeout bei Aktor 2

- Aktor 2 nutzt weiterhin dieselbe Logik wie Aktor 1.
- Neu: einstellbare Pause zwischen Aktor 1 und Aktor 2 in Millisekunden.
- Standard: 500 ms.
- Das hilft vor allem bei xComfort-Gateways, die zwei direkte Schaltbefehle hintereinander nicht sauber verarbeiten.
- Die Suche nach der Schaltvariable prüft jetzt auch eine Ebene unterhalb der Aktor-Instanz.
- Variablen ohne `VariableAction` werden stark abgewertet.


## Fix V3.15 – Aktor 2 zeitversetzt per Timer

Wenn nur Aktor 2 konfiguriert war, wurde er bereits geschaltet.  
Wenn Aktor 1 und Aktor 2 gemeinsam konfiguriert waren, blockierte offenbar der erste Schaltbefehl den zweiten.

Änderung:
- Aktor 1 wird sofort geschaltet.
- Aktor 2 wird danach per eigenem Timer zeitversetzt geschaltet.
- Die Pause kommt aus `Pause zwischen Aktor 1 und Aktor 2 (Millisekunden)`.
- Gleiches gilt beim Ausschalten.


## Änderung V3.16 – bessere Übersicht

- `Letzte Aktion` heißt jetzt `Letzte 10 Aktionen`.
- Die letzten 10 Aktionen werden mit Zeitstempel untereinander angezeigt.
- Die Kreisübersicht zeigt jeden Kreis in einer eigenen Zeile.
- Doppelte Bezeichnungen wie `Kreis 1 | Kreis 1` wurden entfernt.


## Fix V3.17 – Zeilenumbrüche im WebFront

- `Letzte 10 Aktionen` nutzt jetzt `<br>` statt `\n`.
- `Kreisübersicht` nutzt jetzt `<br>` statt `\n`.
- Dadurch werden die Einträge im IP-Symcon WebFront wirklich untereinander angezeigt.


## Fix V3.25 – Timer-Duplikat beim Kreisanlegen

- Timer `StartActuator2Timer` und `StopActuator2Timer` werden jetzt sicher registriert.
- Wenn sie bereits vorhanden sind, werden sie nicht erneut angelegt.
- Das verhindert den Fehler „Timer ... ist bereits vorhanden“.
- Kreis-Anlage im Master prüft jetzt, ob `IPS_CreateInstance()` wirklich eine gültige Instanz-ID zurückgegeben hat.
- Dadurch wird nicht mehr mit Instanz `#0` weitergearbeitet.
