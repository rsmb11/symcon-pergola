<?php

declare(strict_types=1);

/**
 * PERGOLA LAMELLEN STEUERUNG – IP-Symcon Modul
 * =====================================================
 * Modul-GUID: {A1B2C3D4-E5F6-7890-ABCD-EF1234567890}
 *
 * Installation:
 *   1. Ordner "PergolaSteuerung" nach
 *      /var/lib/symcon/modules/ kopieren (Linux/RPI)
 *      bzw. C:\ProgramData\Symcon\modules\ (Windows)
 *   2. In IPS: Kerninstanzen → Modulverwaltung → Module aktualisieren
 *   3. Neue Instanz anlegen: Gerät hinzufügen → "Pergola Lamellen Steuerung"
 *   4. Im Konfigurationsformular KNX-IDs und Variablen eintragen
 *
 * Öffentliche Funktionen (aus Skripten/Ereignissen aufrufbar):
 *   PERGOLA_LamellenSetzen($id, 30);               // direkte Position 0-100
 *   PERGOLA_SzeneAusfuehren($id, 'SONNENSCHUTZ');  // Szene per Name
 *   PERGOLA_AutomationPruefen($id);                // Regen/Temp prüfen
 * =====================================================
 */

class PergolaSteuerung extends IPSModule
{
    // ─── Konstanten ────────────────────────────────────────────────────────────

    /** Variablenidentifier innerhalb der Modulinstanz */
    const VAR_POSITION    = 'Lamellenposition';
    const VAR_MODUS       = 'Modus';
    const VAR_AUTO_SPERRE = 'AutoSperre';
    const VAR_SZENE       = 'Szene';

    /** Szenen-Mapping (Name → Konfigurationsschlüssel) */
    const SZENEN = [
        0 => ['name' => 'OFFEN',        'config' => 'SzeneOffen',        'label' => 'Offen'],
        1 => ['name' => 'SONNENSCHUTZ', 'config' => 'SzeneSonnenschutz', 'label' => 'Sonnenschutz'],
        2 => ['name' => 'GESCHLOSSEN',  'config' => 'SzeneGeschlossen',  'label' => 'Geschlossen'],
        3 => ['name' => 'REGEN',        'config' => 'SzeneRegen',        'label' => 'Regen'],
        4 => ['name' => 'LUEFTUNG',     'config' => 'LueftungPosition',  'label' => 'Lüftung'],
    ];

    // ─── Modul-Lifecycle ────────────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        // Eigenschaften mit Standardwerten registrieren
        $this->RegisterPropertyInteger('KNXInstanzFeld1',      35537);
        $this->RegisterPropertyInteger('KNXInstanzFeld2',      14830);
        $this->RegisterPropertyInteger('RegenAlarmVariableID', 0);
        $this->RegisterPropertyInteger('AussenTempVariableID', 0);

        $this->RegisterPropertyFloat('LueftungTempGrenze', 25.0);
        $this->RegisterPropertyInteger('LueftungPosition', 30);

        $this->RegisterPropertyInteger('SzeneOffen',        0);
        $this->RegisterPropertyInteger('SzeneSonnenschutz', 75);
        $this->RegisterPropertyInteger('SzeneGeschlossen',  100);
        $this->RegisterPropertyInteger('SzeneRegen',        100);

        $this->RegisterPropertyBoolean('TimerAktiv',     true);
        $this->RegisterPropertyInteger('TimerIntervall', 5);

        // Timer registrieren
        $this->RegisterTimer('AutomationTimer', 0, 'PERGOLA_AutomationPruefen($_IPS[\'TARGET\']);');
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Modul-Variablen anlegen (falls nicht vorhanden)
        $this->RegisterVariableProfile();
        $this->RegisterVariableInteger(self::VAR_POSITION, 'Lamellenposition', 'Pergola.Position', 10);
        $this->EnableAction(self::VAR_POSITION);

        $this->RegisterVariableString(self::VAR_MODUS, 'Modus', '', 20);
        $this->RegisterVariableBoolean(self::VAR_AUTO_SPERRE, 'Automation gesperrt', '~Switch', 30);
        $this->RegisterVariableInteger(self::VAR_SZENE, 'Szene', 'Pergola.Szene', 40);
        $this->EnableAction(self::VAR_SZENE);

        // Referenzen auf externe Variablen setzen
        $this->UpdateReferences();

        // Timer (de-)aktivieren
        $this->AktualisiereTimer();

        $this->SetStatus(102); // aktiv
    }

    // ─── Aktionen aus WebFront ──────────────────────────────────────────────────

    /**
     * Wird aufgerufen wenn Nutzer im WebFront Schieberegler oder Dropdown bedient
     */
    public function RequestAction(string $ident, mixed $value): void
    {
        switch ($ident) {
            case self::VAR_POSITION:
                // Manuelle Positionsvorgabe über Schieberegler
                $this->SetValue(self::VAR_AUTO_SPERRE, true);
                $this->LamellenSetzen((int)$value, 'Manuell');
                break;

            case self::VAR_SZENE:
                // Szene aus Dropdown gewählt
                $szene = self::SZENEN[(int)$value] ?? null;
                if ($szene) {
                    // Automation-Sperre: bei manuellen Szenen setzen,
                    // bei Regen/Lüftung nicht sperren
                    $automatisch = in_array($szene['name'], ['REGEN', 'LUEFTUNG']);
                    $this->SetValue(self::VAR_AUTO_SPERRE, !$automatisch);
                    $this->SzeneNachName($szene['name']);
                }
                break;
        }
    }

    // ─── Öffentliche API-Funktionen ─────────────────────────────────────────────

    /**
     * Lamellen direkt auf Prozentwert fahren
     * Aufruf: PERGOLA_LamellenSetzen($id, 50);
     */
    public function LamellenSetzen(int $prozent, string $modus = 'Manuell'): bool
    {
        $prozent = max(0, min(100, $prozent));

        $feld1 = $this->ReadPropertyInteger('KNXInstanzFeld1');
        $feld2 = $this->ReadPropertyInteger('KNXInstanzFeld2');

        if ($feld1 <= 0 || $feld2 <= 0) {
            $this->LogMessage('KNX Instanz-IDs nicht konfiguriert!', KL_ERROR);
            return false;
        }

        // An KNX senden
        IPS_RequestAction($feld1, '', $prozent);
        IPS_RequestAction($feld2, '', $prozent);

        // Statusvariablen aktualisieren
        $this->SetValue(self::VAR_POSITION, $prozent);
        $this->SetValue(self::VAR_MODUS, $modus . ' (' . $prozent . '%)');

        $this->LogMessage("Lamellen → {$prozent}% | {$modus}", KL_NOTIFY);
        return true;
    }

    /**
     * Szene per Name ausführen
     * Aufruf: PERGOLA_SzeneAusfuehren($id, 'SONNENSCHUTZ');
     */
    public function SzeneAusfuehren(string $szene): bool
    {
        return $this->SzeneNachName(strtoupper(trim($szene)));
    }

    /**
     * Automation manuell auslösen (Regen + Temperatur prüfen)
     * Aufruf: PERGOLA_AutomationPruefen($id);
     */
    public function AutomationPruefen(): void
    {
        $regenID = $this->ReadPropertyInteger('RegenAlarmVariableID');
        $tempID  = $this->ReadPropertyInteger('AussenTempVariableID');

        // Manuelle Sperre prüfen
        $gesperrt = $this->GetValue(self::VAR_AUTO_SPERRE);
        if ($gesperrt) {
            $this->LogMessage('Automation: manuelle Sperre aktiv, übersprungen.', KL_DEBUG);
            return;
        }

        // Regenalarm hat höchste Priorität
        if ($regenID > 0 && IPS_VariableExists($regenID)) {
            $regen = GetValue($regenID);
            if ($regen === true) {
                $this->LogMessage('Automation: Regen erkannt → schließen', KL_NOTIFY);
                $this->SzeneNachName('REGEN');
                return;
            }
        }

        // Temperaturprüfung
        if ($tempID > 0 && IPS_VariableExists($tempID)) {
            $temp   = (float)GetValue($tempID);
            $grenze = $this->ReadPropertyFloat('LueftungTempGrenze');
            if ($temp >= $grenze) {
                $this->LogMessage("Automation: {$temp}°C >= {$grenze}°C → Lüftung", KL_NOTIFY);
                $this->SzeneNachName('LUEFTUNG');
                return;
            }
        }

        $this->LogMessage('Automation: kein Eingriff nötig.', KL_DEBUG);
    }

    // ─── Interne Hilfsmethoden ──────────────────────────────────────────────────

    /**
     * Szene per internem Namen ausführen
     */
    private function SzeneNachName(string $name): bool
    {
        // Szene im Mapping suchen
        $gefunden = null;
        $szeneInt = 0;
        foreach (self::SZENEN as $int => $szene) {
            if ($szene['name'] === $name) {
                $gefunden = $szene;
                $szeneInt = $int;
                break;
            }
        }

        if ($gefunden === null) {
            $this->LogMessage("Unbekannte Szene: {$name}", KL_WARNING);
            return false;
        }

        // Position aus Konfiguration lesen
        $configKey = $gefunden['config'];
        if ($configKey === 'LueftungPosition') {
            $position = $this->ReadPropertyInteger('LueftungPosition');
        } else {
            $position = $this->ReadPropertyInteger($configKey);
        }

        // Szene-Variable aktualisieren
        $this->SetValue(self::VAR_SZENE, $szeneInt);

        return $this->LamellenSetzen($position, $gefunden['label']);
    }

    /**
     * Variablenprofile anlegen (einmalig)
     */
    private function RegisterVariableProfile(): void
    {
        // Position: 0-100% Schieberegler
        if (!IPS_VariableProfileExists('Pergola.Position')) {
            IPS_CreateVariableProfile('Pergola.Position', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('Pergola.Position', 0, 100, 1);
            IPS_SetVariableProfileText('Pergola.Position', '', ' %');
            IPS_SetVariableProfileIcon('Pergola.Position', 'Shutter');
        }

        // Szenen-Auswahl: Dropdown
        if (!IPS_VariableProfileExists('Pergola.Szene')) {
            IPS_CreateVariableProfile('Pergola.Szene', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Pergola.Szene', 0, 'Offen',        'Sun',    0x00AA00);
            IPS_SetVariableProfileAssociation('Pergola.Szene', 1, 'Sonnenschutz', 'Sun',    0xFFAA00);
            IPS_SetVariableProfileAssociation('Pergola.Szene', 2, 'Geschlossen',  'Shutter',0xAA2200);
            IPS_SetVariableProfileAssociation('Pergola.Szene', 3, 'Regen',        'Drops',  0x0055FF);
            IPS_SetVariableProfileAssociation('Pergola.Szene', 4, 'Lüftung',      'Wind',   0x00CCFF);
        }
    }

    /**
     * Referenzen auf externe Variablen registrieren (für IPS-interne Abhängigkeiten)
     */
    private function UpdateReferences(): void
    {
        $this->UnregisterReferences();

        $regenID = $this->ReadPropertyInteger('RegenAlarmVariableID');
        $tempID  = $this->ReadPropertyInteger('AussenTempVariableID');

        if ($regenID > 0) {
            $this->RegisterReference($regenID);
        }
        if ($tempID > 0) {
            $this->RegisterReference($tempID);
        }
    }

    /**
     * Timer nach Konfiguration setzen oder deaktivieren
     */
    private function AktualisiereTimer(): void
    {
        $aktiv     = $this->ReadPropertyBoolean('TimerAktiv');
        $intervall = $this->ReadPropertyInteger('TimerIntervall');

        if ($aktiv && $intervall > 0) {
            $ms = $intervall * 60 * 1000; // Minuten → Millisekunden
            $this->SetTimerInterval('AutomationTimer', $ms);
        } else {
            $this->SetTimerInterval('AutomationTimer', 0);
        }
    }
}
