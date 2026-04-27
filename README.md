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
