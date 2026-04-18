# Pergola Lamellen Steuerung – IP-Symcon Modul

Steuert eine Alu-Pergola-Überdachung mit KNX-angesteuerten Lamellen.
Unterstützt Regenalarm, Lüftungsautomatik bei Außentemperatur und manuelle Szenenauswahl im WebFront.

---

## Installation

In der IPS-Konsole:
**Kerninstanzen → Modulverwaltung → Hinzufügen:**
```
https://github.com/rsmb11/symcon-pergola
```

Neue Instanz anlegen:
**Gerät hinzufügen → Hersteller "rsmb11" → Pergola Lamellen Steuerung**

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

## WebFront Variablen

| Variable | Typ | Beschreibung |
|----------|-----|-------------|
| Lamellenposition | Integer (0–100%) | Schieberegler, direkte Positionsvorgabe |
| Szene | Integer (Dropdown) | Offen / Sonnenschutz / Geschlossen / Regen / Lüftung |
| Modus | String | Statusanzeige |
| Automation gesperrt | Boolean | Manuelle Sperre für Automatik |

---

## Prioritäten der Automatik

```
Regen-Alarm       →  100% schließen  (höchste Priorität)
Außentemp >= 25°C →  Lüftungsstellung (30%)
Manuelle Eingabe  →  setzt Sperre, Automatik pausiert
```

---

## Funktionen aus Skripten

```php
PERGOLA_LamellenSetzen($id, 50);
PERGOLA_SzeneAusfuehren($id, 'OFFEN');
PERGOLA_SzeneAusfuehren($id, 'SONNENSCHUTZ');
PERGOLA_SzeneAusfuehren($id, 'GESCHLOSSEN');
PERGOLA_SzeneAusfuehren($id, 'REGEN');
PERGOLA_SzeneAusfuehren($id, 'LUEFTUNG');
PERGOLA_AutomationPruefen($id);
```
