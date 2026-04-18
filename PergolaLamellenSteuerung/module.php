<?php

declare(strict_types=1);

class PergolaLamellenSteuerung extends IPSModuleStrict
{
    const VAR_POSITION    = 'Lamellenposition';
    const VAR_MODUS       = 'Modus';
    const VAR_AUTO_SPERRE = 'AutoSperre';
    const VAR_SZENE       = 'Szene';

    const SZENEN = [
        0 => ['name' => 'OFFEN',        'config' => 'SzeneOffen',        'label' => 'Offen'],
        1 => ['name' => 'SONNENSCHUTZ', 'config' => 'SzeneSonnenschutz', 'label' => 'Sonnenschutz'],
        2 => ['name' => 'GESCHLOSSEN',  'config' => 'SzeneGeschlossen',  'label' => 'Geschlossen'],
        3 => ['name' => 'REGEN',        'config' => 'SzeneRegen',        'label' => 'Regen'],
        4 => ['name' => 'LUEFTUNG',     'config' => 'LueftungPosition',  'label' => 'Lueftung'],
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('KNXInstanzFeld1',      35537);
        $this->RegisterPropertyInteger('KNXInstanzFeld2',      14830);
        $this->RegisterPropertyInteger('RegenAlarmVariableID', 0);
        $this->RegisterPropertyInteger('AussenTempVariableID', 0);
        $this->RegisterPropertyFloat('LueftungTempGrenze',     25.0);
        $this->RegisterPropertyInteger('LueftungPosition',     30);
        $this->RegisterPropertyInteger('SzeneOffen',           0);
        $this->RegisterPropertyInteger('SzeneSonnenschutz',    75);
        $this->RegisterPropertyInteger('SzeneGeschlossen',     100);
        $this->RegisterPropertyInteger('SzeneRegen',           100);
        $this->RegisterPropertyBoolean('TimerAktiv',           true);
        $this->RegisterPropertyInteger('TimerIntervall',       5);

        $this->RegisterTimer('AutomationTimer', 0, 'PERGOLA_AutomationPruefen($_IPS[\'TARGET\']);');
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterVariableProfile();

        $this->RegisterVariableInteger(self::VAR_POSITION, 'Lamellenposition', 'Pergola.Position', 10);
        $this->EnableAction(self::VAR_POSITION);

        $this->RegisterVariableString(self::VAR_MODUS, 'Modus', '', 20);
        $this->RegisterVariableBoolean(self::VAR_AUTO_SPERRE, 'Automation gesperrt', '~Switch', 30);

        $this->RegisterVariableInteger(self::VAR_SZENE, 'Szene', 'Pergola.Szene', 40);
        $this->EnableAction(self::VAR_SZENE);

        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        $regenID = $this->ReadPropertyInteger('RegenAlarmVariableID');
        $tempID  = $this->ReadPropertyInteger('AussenTempVariableID');
        if ($regenID > 0) $this->RegisterReference($regenID);
        if ($tempID > 0)  $this->RegisterReference($tempID);

        $aktiv     = $this->ReadPropertyBoolean('TimerAktiv');
        $intervall = $this->ReadPropertyInteger('TimerIntervall');
        $this->SetTimerInterval('AutomationTimer', ($aktiv && $intervall > 0) ? $intervall * 60 * 1000 : 0);

        $this->SetStatus(102);
    }

    public function RequestAction(string $ident, mixed $value): void
    {
        switch ($ident) {
            case self::VAR_POSITION:
                $this->SetValue(self::VAR_AUTO_SPERRE, true);
                $this->LamellenSetzen((int)$value, 'Manuell');
                break;
            case self::VAR_SZENE:
                $szene = self::SZENEN[(int)$value] ?? null;
                if ($szene) {
                    $automatisch = in_array($szene['name'], ['REGEN', 'LUEFTUNG']);
                    $this->SetValue(self::VAR_AUTO_SPERRE, !$automatisch);
                    $this->SzeneNachName($szene['name']);
                }
                break;
        }
    }

    public function LamellenSetzen(int $prozent, string $modus = 'Manuell'): bool
    {
        $prozent = max(0, min(100, $prozent));
        $feld1   = $this->ReadPropertyInteger('KNXInstanzFeld1');
        $feld2   = $this->ReadPropertyInteger('KNXInstanzFeld2');

        if ($feld1 <= 0 || $feld2 <= 0) {
            $this->LogMessage('KNX Instanz-IDs nicht konfiguriert!', KL_ERROR);
            return false;
        }

        RequestAction($feld1, $prozent);
        RequestAction($feld2, $prozent);

        $this->SetValue(self::VAR_POSITION, $prozent);
        $this->SetValue(self::VAR_MODUS, $modus . ' (' . $prozent . '%)');
        $this->LogMessage("Lamellen -> {$prozent}% | {$modus}", KL_NOTIFY);
        return true;
    }

    public function SzeneAusfuehren(string $szene): bool
    {
        return $this->SzeneNachName(strtoupper(trim($szene)));
    }

    public function AutomationPruefen(): void
    {
        if ($this->GetValue(self::VAR_AUTO_SPERRE)) return;

        $regenID = $this->ReadPropertyInteger('RegenAlarmVariableID');
        if ($regenID > 0 && IPS_VariableExists($regenID) && GetValue($regenID) === true) {
            $this->SzeneNachName('REGEN');
            return;
        }

        $tempID = $this->ReadPropertyInteger('AussenTempVariableID');
        if ($tempID > 0 && IPS_VariableExists($tempID)) {
            if ((float)GetValue($tempID) >= $this->ReadPropertyFloat('LueftungTempGrenze')) {
                $this->SzeneNachName('LUEFTUNG');
            }
        }
    }

    private function SzeneNachName(string $name): bool
    {
        foreach (self::SZENEN as $int => $szene) {
            if ($szene['name'] === $name) {
                $position = $this->ReadPropertyInteger($szene['config']);
                $this->SetValue(self::VAR_SZENE, $int);
                return $this->LamellenSetzen($position, $szene['label']);
            }
        }
        return false;
    }

    private function RegisterVariableProfile(): void
    {
        if (!IPS_VariableProfileExists('Pergola.Position')) {
            IPS_CreateVariableProfile('Pergola.Position', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('Pergola.Position', 0, 100, 1);
            IPS_SetVariableProfileText('Pergola.Position', '', ' %');
            IPS_SetVariableProfileIcon('Pergola.Position', 'Shutter');
        }

        if (!IPS_VariableProfileExists('Pergola.Szene')) {
            IPS_CreateVariableProfile('Pergola.Szene', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Pergola.Szene', 0, 'Offen',        'Sun',     0x00AA00);
            IPS_SetVariableProfileAssociation('Pergola.Szene', 1, 'Sonnenschutz', 'Sun',     0xFFAA00);
            IPS_SetVariableProfileAssociation('Pergola.Szene', 2, 'Geschlossen',  'Shutter', 0xAA2200);
            IPS_SetVariableProfileAssociation('Pergola.Szene', 3, 'Regen',        'Drops',   0x0055FF);
            IPS_SetVariableProfileAssociation('Pergola.Szene', 4, 'Lueftung',     'Wind',    0x00CCFF);
        }
    }
}
