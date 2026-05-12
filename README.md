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
