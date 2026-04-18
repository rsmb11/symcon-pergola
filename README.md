# Pergola Lamellen Steuerung – IP-Symcon Modul

Steuert eine Alu-Pergola-Überdachung mit KNX-angesteuerten Lamellen.
Unterstützt Regenalarm, Lüftungsautomatik bei Außentemperatur und manuelle Szenenauswahl im WebFront.

---

## Installation

1. In der IPS-Konsole:
   **Kerninstanzen → Modulverwaltung → Hinzufügen:**
   ```
   https://github.com/rsmb11/symcon-pergola
   ```

2. Neue Instanz anlegen:
   **Gerät hinzufügen → Hersteller "rsmb11" → Pergola Lamellen Steuerung**

3. Im Konfigurationsformular ausfüllen (siehe unten).

---

## Konfigurationsformular

| Feld | Beschreibung |
|------|-------------|
| KNX Instanz-ID Feld 1 | Objekt-ID des KNX-Datenpunkts 2/2/22 (Standard: 35537) |
| KNX Instanz-ID Feld 2 | Objekt-ID des KNX-Datenpunkts 2/2/23 (Standard: 14830) |
| Variable Regenalarm | Boolean-Variable aus KNX oder Wetterstation |
| Variable Außentemperatur | Float-Variable in °C |
| Temperaturgrenze Lüftung | Ab dieser Temperatur Lüftungsstellung (Standard: 25°C) |
| Lüftungsstellung | Öffnungsgrad bei Lüftung in % (Standard: 30%) |
| Szene: Offen | Position in % (Standard: 0%) |
| Szene: Sonnenschutz | Position in % (Standard: 75%) |
| Szene: Geschlossen | Position in % (Standard: 100%) |
| Szene: Regen | Position in % (Standard: 100%) |
| Timer aktiv | Zyklische Automatikprüfung ein/aus |
| Prüfintervall | Zeitabstand in Minuten (Standard: 5 min) |

---

## WebFront

Das Modul legt folgende Variablen an (sichtbar im WebFront):

| Variable | Typ | Beschreibung |
|----------|-----|-------------|
| Lamellenposition | Integer (0–100%) | Schieberegler, direkte Positionsvorgabe |
| Szene | Integer (Dropdown) | Offen / Sonnenschutz / Geschlossen / Regen / Lüftung |
| Modus | String | Statusanzeige (aktueller Modus + Prozentwert) |
| Automation gesperrt | Boolean | true = manuelle Sperre, Automation pausiert |

---

## Prioritäten der Automatik

```
Regen-Alarm  ──►  100% schließen  (höchste Priorität, ignoriert manuelle Sperre)
Außentemp ≥ X°C  ──►  Lüftungsstellung
Manuelle Eingabe  ──►  setzt Sperre, Automatik pausiert
```

Die manuelle Sperre kann im WebFront über die Variable "Automation gesperrt" wieder aufgehoben werden.

---

## Funktionen (aus Skripten aufrufbar)

```php
// Direkte Position setzen (0 = offen, 100 = geschlossen)
PERGOLA_LamellenSetzen($id, 50);

// Szene ausführen
PERGOLA_SzeneAusfuehren($id, 'OFFEN');
PERGOLA_SzeneAusfuehren($id, 'SONNENSCHUTZ');
PERGOLA_SzeneAusfuehren($id, 'GESCHLOSSEN');
PERGOLA_SzeneAusfuehren($id, 'REGEN');
PERGOLA_SzeneAusfuehren($id, 'LUEFTUNG');

// Automation manuell auslösen
PERGOLA_AutomationPruefen($id);
```

`$id` ist die Instanz-ID der angelegten Pergola-Instanz in IPS.

---

## Ereignisse einrichten (empfohlen)

Damit die Automation sofort auf Regenalarm oder Temperaturänderungen reagiert:

1. **Ereignis 1:** Ausgelöst durch Variable *Regenalarm* bei Änderung → Skript: `PERGOLA_AutomationPruefen($id);`
2. **Ereignis 2:** Ausgelöst durch Variable *Außentemperatur* bei Änderung → Skript: `PERGOLA_AutomationPruefen($id);`

Der interne Timer übernimmt als zusätzliche Fallback-Prüfung.
