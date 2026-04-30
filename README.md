# IrrigationController für IP-Symcon

**Version 4.1**

## Überblick

Dieses Modul stellt eine Bewässerungssteuerung für IP-Symcon bereit. Es besteht aus einem zentralen Master-Modul und mehreren Bewässerungskreisen. Die Kreise können zeitgesteuert oder automatisch anhand von Feuchtigkeitswerten nacheinander beregnet werden.

Das Modul ist dafür ausgelegt, verschiedene Aktortypen wie Shelly oder xComfort zu verwenden. In der Konfiguration wird jeweils die Aktor-Instanz ausgewählt. Das Modul sucht darunter selbstständig die passende schaltbare Bool-Variable und schaltet diese per `RequestAction()`.

## Modulaufbau

Das Projekt besteht aus zwei Modulen:

```text
IrrigationController/
IrrigationZone/
library.json
README.md
```

### IrrigationController

Das Master-Modul ist die zentrale Steuerung der Bewässerung.

Aufgaben des Masters:

- Verwaltung der Bewässerungskreise
- Anlegen neuer Kreise
- Steuerung der Pumpe
- Betriebsmodus auswählen
- Wochenpläne für Zeitsteuerung und Automatik bereitstellen
- Kreise nacheinander abarbeiten
- Sequenzstatus anzeigen
- Pumpen-Vorlauf und Pumpen-Frühabschaltung berücksichtigen

### IrrigationZone

Das Kreis-Modul bildet einen einzelnen Bewässerungskreis ab.

Aufgaben eines Kreises:

- Aktor 1 und optional Aktor 2 schalten
- eigene Bewässerungsdauer verwalten
- Feuchtigkeitssensoren auswerten
- Regensperre berücksichtigen
- entscheiden, ob im Automatikbetrieb bewässert werden soll
- manuellen Kreisstart unterstützen

## Betriebsmodi

Im Master kann ein Betriebsmodus gewählt werden.

### Zeitsteuerung

Im Modus Zeitsteuerung startet der Wochenplan `Zeitsteuerung` die Bewässerungssequenz.

Wenn der Wochenplan auf **Ein** schaltet:

1. Die Pumpe wird eingeschaltet.
2. Der Pumpenvorlauf wird abgewartet.
3. Alle aktiven Kreise werden der Reihenfolge nach abgearbeitet.
4. Jeder Kreis läuft für seine eigene eingestellte Beregnungsdauer.
5. Nach dem letzten Kreis wird die Pumpe sicher ausgeschaltet.

Der Schaltpunkt **Aus** wird nicht für eine Bewässerungslogik verwendet.

### Automatik

Im Modus Automatik startet der Wochenplan `Automatik` die automatische Prüfung.

Wenn der Wochenplan auf **Ein** schaltet:

1. Jeder aktive Kreis wird geprüft.
2. Inaktive Kreise werden übersprungen.
3. Kreise ohne Bewässerungsbedarf werden übersprungen.
4. Nur Kreise mit Bewässerungsbedarf werden in die Sequenz aufgenommen.
5. Die ausgewählten Kreise laufen nacheinander.
6. Nach dem letzten Kreis wird die Pumpe sicher ausgeschaltet.

Der Schaltpunkt **Aus** wird nicht für eine Bewässerungslogik verwendet.

## Sequenzlogik

Die Bewässerung läuft immer sequenziell.

Das bedeutet:

- Es läuft niemals mehr als ein Kreis gleichzeitig.
- Die Pumpe bleibt während einer Sequenz aktiv.
- Zwischen den Kreisen kann eine Pause eingestellt werden.
- Wenn ein Kreis übersprungen wird, fährt die Logik mit dem nächsten Kreis fort.
- Wenn keine Kreise mehr warten, wird die Sequenz beendet und die Pumpe ausgeschaltet.

## Pumpensteuerung

Die Pumpe wird im Master konfiguriert.

Einstellungen im Master:

- Pumpenaktor
- Pumpenvorlauf vor jedem Kreis
- Pumpe vor Ende des letzten Kreises ausschalten
- Pause zwischen Kreisen

### Pumpenvorlauf

Vor dem Start eines Kreises kann die Pumpe für eine einstellbare Zeit vorlaufen.

### Pumpe früher aus

Die Pumpe kann vor dem Ende des letzten Kreises ausgeschaltet werden. Dadurch kann der Druck in der Leitung vor dem Schließen der Aktoren abgebaut werden.

### Manueller Kreisstart

Wenn ein Kreis direkt über `Kreis aktiv bewässert` gestartet wird, schaltet der Kreis automatisch die Pumpe im Master mit ein.

Beim manuellen Stop:

1. Die Pumpe wird zuerst ausgeschaltet.
2. Die eingestellte Zeit `Pumpe früher aus` wird abgewartet.
3. Danach werden die Aktoren des Kreises ausgeschaltet.

## Aktorsteuerung

Jeder Kreis kann bis zu zwei Aktoren verwenden:

- Aktor 1
- Aktor 2

Beide Aktoren sind optional. Wenn beide Aktoren konfiguriert sind, werden beide geschaltet.

Unterstützte Aktoren sind zum Beispiel:

- Shelly
- xComfort
- andere IP-Symcon-Instanzen mit schaltbarer Bool-Variable

Das Modul sucht unter der gewählten Instanz nach einer passenden Bool-Variable. Diagnosevariablen wie `Online`, `Connected`, `Battery`, `Error` oder ähnliche werden nicht als Schaltvariable verwendet.

Beim Einschalten merkt sich das Modul die tatsächlich verwendete Bool-Schaltvariable. Beim Ausschalten wird zuerst genau diese Variable wieder verwendet.

## Feuchtigkeitsautomatik

Jeder Kreis kann bis zu zwei Feuchtigkeitssensoren verwenden.

Einstellungen pro Kreis:

- Feuchtesensor 1
- Feuchtesensor 2 optional
- Feuchteschwelle
- Auswertungsmethode
- Regensperre optional

### Auswertungsmethoden

Es stehen zwei Auswertungen zur Verfügung:

- niedrigster Feuchtigkeitswert
- Durchschnitt der Sensoren

### Bewässerungsentscheidung

Ein Kreis wird im Automatikbetrieb bewässert, wenn:

- der Kreis aktiv ist
- ein gültiger Feuchtewert vorhanden ist
- der berechnete Feuchtewert unter der eingestellten Schwelle liegt
- keine aktive Regensperre greift

Wenn diese Bedingungen nicht erfüllt sind, wird der Kreis übersprungen.

## Regensperre

Optional kann eine Variable für Regenmenge der letzten 24 Stunden ausgewählt werden.

Wenn die Regenmenge größer oder gleich der eingestellten Regenschwelle ist, wird der Kreis im Automatikbetrieb nicht bewässert.

## Kreisverwaltung

Neue Kreise können direkt im Master-Modul angelegt werden.

Der Master erkennt Kreise, die unterhalb der Master-Instanz liegen. Die Kreise werden anhand ihrer Kreisnummer sortiert und nacheinander ausgeführt.

Im Master wird eine Kreisübersicht angezeigt.

## Anzeige im WebFront

Das Master-Modul zeigt unter anderem:

- Betriebsmodus
- Sequenz aktiv
- Pumpe aktiv
- aktueller Kreis
- wartende Kreise
- Sequenzstatus
- letzte Aktionen
- Kreisübersicht

Das Kreis-Modul zeigt unter anderem:

- Kreis aktiv
- Kreisnummer
- Beregnungsdauer
- Feuchteschwelle
- Feuchteauswertung
- Aktorstatus
- Sensorwerte
- berechnete Feuchte
- Automatikentscheidung
- letzte Aktionen

## Debug

Das Modul nutzt `SendDebug()` für ausführliche Debug-Ausgaben.

Im Debugfenster von IP-Symcon kann nachvollzogen werden:

- welche Kreise erkannt wurden
- welche Kreise in die Sequenz aufgenommen wurden
- warum Kreise übersprungen wurden
- welche Aktorinstanz verwendet wurde
- welche Bool-Schaltvariable ausgewählt wurde
- ob `RequestAction()` erfolgreich war
- wann Pumpe und Aktoren geschaltet wurden

## Installation

Das ZIP-Archiv ist flach aufgebaut und enthält keinen zusätzlichen Projekt-Unterordner.

Inhalt:

```text
IrrigationController/
IrrigationZone/
library.json
README.md
```

Zum Installieren den Inhalt in den IP-Symcon Modulordner kopieren, zum Beispiel:

```text
C:\ProgramData\Symcon\modules\irrigation\
```

Danach in IP-Symcon:

1. Module neu laden
2. Master-Instanz öffnen
3. Änderungen übernehmen
4. gewünschte Kreise anlegen oder vorhandene Kreise prüfen
5. pro Kreis Aktoren, Sensoren und Dauer konfigurieren

## Grundkonfiguration

### Master

Im Master sollten mindestens folgende Punkte gesetzt werden:

- Betriebsmodus
- Pumpenaktor
- Pumpenvorlauf
- Pause zwischen Kreisen
- Pumpe früher aus
- Wochenplan für Zeitsteuerung oder Automatik

### Kreis

In jedem Kreis sollten mindestens folgende Punkte gesetzt werden:

- Kreis aktiv
- Kreisnummer
- Beregnungsdauer
- Aktor 1 oder Aktor 2
- Feuchtesensoren, falls Automatik verwendet wird
- Feuchteschwelle
- Feuchteauswertung

## Hinweise

- Kreise müssen unterhalb der Master-Instanz liegen.
- Es läuft immer nur ein Kreis gleichzeitig.
- Die Pumpe wird im Sequenzbetrieb vom Master gesteuert.
- Beim manuellen Start eines Kreises wird die Pumpe über den Master mitgeschaltet.
- Aktor 2 ist optional, wird aber gemeinsam mit Aktor 1 behandelt, wenn er konfiguriert ist.
- Die Wochenpläne starten über den Schaltpunkt **Ein**.
- Der Schaltpunkt **Aus** wird bewusst nicht zur Steuerung verwendet.
