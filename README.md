# Irrigation (IP-Symcon)

**Version 5.0**

---

## Überblick

Dieses Modul stellt eine flexible Bewässerungssteuerung für IP-Symcon bereit.

Die aktuelle Version basiert auf einer **mehrstufigen Architektur**:

```text
Master → Zonen → Kreise
```

Damit lassen sich komplexe Bewässerungsabläufe sauber strukturieren und vollständig sequenziell ausführen.

---

## Modulaufbau

```text
IrrigationController/   → Master
IrrigationArea/         → Zonen
IrrigationZone/         → Kreise
library.json
README.md
```

---

## Architektur

### Master (IrrigationController)

Zentrale Steuerung der gesamten Bewässerung.

Aufgaben:

- Verwaltung aller Zonen
- zentrale Queue (keine parallelen Abläufe)
- Pumpensteuerung
- Anzeige des gesamten Ablaufs
- Berechnung der voraussichtlichen Laufzeit
- Logging der letzten Aktionen
- Logging der letzten Beregnungsdauern

---

### Zone (IrrigationArea)

Eine Zone enthält mehrere Kreise und definiert deren Ablauf.

Aufgaben:

- eigener Betriebsmodus (Manuell / Zeit / Automatik)
- eigene Wochenpläne
- Verwaltung der enthaltenen Kreise
- sequentielle Abarbeitung der Kreise
- Berechnung der eigenen Laufzeit

---

### Kreis (IrrigationZone)

Ein einzelner Bewässerungskreis.

Aufgaben:

- Aktoren schalten (1 oder 2)
- Bewässerungsdauer definieren
- Feuchtigkeit auswerten
- Automatikentscheidung treffen

---

## Betriebsmodi (pro Zone)

### Manuell

- Zone wird nur manuell gestartet

### Zeitsteuerung

- Wochenplan „Zeitsteuerung“ löst Start aus
- Zone wird beim Master **eingereiht (Queue)**

### Automatik

- Wochenplan „Automatik“ prüft Feuchtigkeit
- nur relevante Kreise werden ausgeführt
- Zone wird beim Master eingereiht

---

## Queue-Logik (wichtig!)

Das System arbeitet vollständig sequenziell:

```text
✔ Es läuft immer nur eine Zone
✔ Innerhalb der Zone läuft immer nur ein Kreis
✔ Neue Starts werden hinten angehängt
```

Beispiel:

```text
Zone 1 läuft
Zone 2 wird ausgelöst
→ Zone 2 wird angehängt
→ kein paralleler Start
```

---

## Zeitberechnung

Der Master berechnet laufend:

- verbleibende Zeit
- voraussichtliches Ende

Anzeige im Master:

```text
Ende ca. 18:42 | Rest ca. 25 min
```

Berechnung basiert auf:

- Kreis-Dauern
- Pausen zwischen Kreisen
- Pausen zwischen Zonen
- Warteschlange

---

## Neue Features in Version 5

### 1. Voraussichtliches Ende

Anzeige im Master:

- Endzeit
- Restzeit

---

### 2. Letzte 10 Beregnungsdauern

Anzeige aller abgeschlossenen Sequenzen:

```text
12.05.2026 18:10 – 32 min
12.05.2026 09:05 – 28 min
...
```

---

### 3. Zentrale HTML-Darstellung

Alle Anzeigen nutzen ein einheitliches Styling:

- Schriftart
- Schriftgröße
- Farben

werden im Master definiert.

---

### 4. Erweiterte Wochenpläne

- pro Zone getrennt
- pro Tag konfigurierbar
- echte Mo–So Struktur

---

## Pumpenlogik

Die Pumpe wird ausschließlich im Master gesteuert.

Funktionen:

- Pumpenvorlauf
- Abschaltung vor Ende
- durchgehender Betrieb während Sequenz

---

## Installation

```text
C:\ProgramData\Symcon\modules\irrigation\
```

Danach:

```text
1. Module neu laden
2. Master erstellen
3. Zonen erstellen
4. Kreise anlegen
```

---

## Hinweise

- max. 10 Zonen
- Kreise müssen in Zonen liegen
- Zonen müssen im Master liegen
- kein Parallelbetrieb möglich (gewollt)

---

## Debug

Alle Module schreiben Debug-Ausgaben:

```text
IRR[...]  → Master
IRRA[...] → Zone
IRRZ[...] → Kreis
```

---

## Fazit

Version 5 ist eine komplette Weiterentwicklung:

```text
✔ saubere Struktur (Master → Zonen → Kreise)
✔ keine Parallelprobleme
✔ Queue-System
✔ Zeitberechnung
✔ bessere Visualisierung
```


## Version 5.1 Build 4 – signierte Vorlaufzeiten

- Pumpenvorlauf und Ventilvorlauf: positive Sekunden = vor dem ersten Start einschalten, negative Sekunden = erst nach dem Start einschalten.
- Pumpennachlauf und Ventilnachlauf: positive Sekunden = später ausschalten, negative Sekunden = vor Ende ausschalten.
- Zonenventil wird ausschließlich über die ausgewählte Aktorinstanz geschaltet; keine separate Schaltvariable in der Konfiguration.
- Die Laufzeitprognose berücksichtigt positive Vorlaufzeiten; negative Vorlaufzeiten verlängern die Beregnung nicht.
- Negative Vorlaufzeiten können eine Zeit ohne eingeschaltete Pumpe oder ohne geöffnetes Zonenventil verursachen.

### Build 7 – Erkennung nach Modulupdate

Master und Zonen aktualisieren ihre Kindinstanzen zwei Sekunden nach ApplyChanges erneut. Bei zunächst leerer Erkennung folgen bis zu fünf Prüfungen. Vorhandene Kreise, Zonen und Einstellungen werden nicht gelöscht.

## Build 9 – optionale Aktorrückmeldung

Im Master kann die Aktorrückmeldung zentral aktiviert werden (standardmäßig aus,
für bestehende Installationen ohne Verhaltensänderung). Pro Pumpe, Zonenventil
und Kreisaktor kann die Überwachung zusätzlich deaktiviert werden. Der Master
prüft die schaltbare Boolean-Variable nach einer einstellbaren Wartezeit. Ist der
Zustand noch nicht bestätigt, kann er den Schaltbefehl einmal wiederholen.

Bei dauerhaft fehlender Rückmeldung wird ein Eintrag in „Letzte 10 Schaltfehler“
angelegt. Diese Einträge werden als IP-Symcon-Attribut gespeichert. Das Verhalten
ist zwischen „Nur protokollieren“ und „Sequenz stoppen“ einstellbar; ein
unbestätigtes AUS verhindert aus Sicherheitsgründen stets den nächsten Kreis.

Benachrichtigungen: Native IP-Symcon Push-Instanz, optionales Telegram-Sendescript
(muss die Parameter `$_IPS['TITLE']` und `$_IPS['MESSAGE']` verarbeiten), beides
oder keine Benachrichtigung. Die Konfiguration erfolgt im Master.

**Hinweis:** Die Schaltvariable muss eine verlässliche Geräterückmeldung liefern.
Wenn sie nur den angeforderten Sollzustand widerspiegelt, ist die Prüfung keine
unabhängige Bestätigung. Vor dem produktiven Einsatz mit Testaktoren prüfen.

### Build 10 – Schnelles Ein/Aus
- Sequenzschalter arbeitet ueber einen entkoppelten 100-ms-Kommandotimer statt synchroner Aktor-Kaskaden im WebFront-Aufruf.
- AUS hat Vorrang, deaktiviert sofort ausstehende Start-Timer und setzt die Anzeige auf AUS.
- Reentranzschutz verhindert mehrfaches gleichzeitiges Stoppen und einen Start waehrend des Stopps.
- Vorhandene Rueckmeldungen, Fehlerprotokolle und Benachrichtigungen bleiben erhalten.
