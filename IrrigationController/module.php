<?php

declare(strict_types=1);

class IrrigationController extends IPSModule
{
    public function Create()
    {
        parent::Create();
        // Sensor- und Aktor-Instanzen
        $this->RegisterPropertyInteger('MoistureSensor1', 0);
        $this->RegisterPropertyInteger('MoistureSensor2', 0);
        $this->RegisterPropertyInteger('RainLast24h',   0);
        $this->RegisterPropertyInteger('Valve1',        0);
        $this->RegisterPropertyInteger('Valve2',        0);
        $this->RegisterPropertyInteger('Pump',          0);

        // Bewässerungsparameter (Mode: 0=Aus,1=Manuell,2=Zeitsteuerung,3=Automatik)
        $this->RegisterPropertyInteger('Mode',               0);
        $this->RegisterPropertyInteger('Duration',           5);   // Minuten, Standard=5
        $this->RegisterPropertyInteger('MoistureThreshold', 50);  // %,    Standard=50

        // Profile für Betriebsmodus (IRR.Mode)
        if (IPS_VariableProfileExists('IRR.Mode')) {
            IPS_DeleteVariableProfile('IRR.Mode');
        }
        IPS_CreateVariableProfile('IRR.Mode', VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileValues('IRR.Mode', 0, 3, 1);
        IPS_SetVariableProfileAssociation('IRR.Mode', 0, 'Aus',          '', 0x000000);
        IPS_SetVariableProfileAssociation('IRR.Mode', 1, 'Manuell',      '', 0x808080);
        IPS_SetVariableProfileAssociation('IRR.Mode', 2, 'Zeitsteuerung','', 0xFFFF00);
        IPS_SetVariableProfileAssociation('IRR.Mode', 3, 'Automatik',    '', 0x00FF00);

        // Profile für Dauer (IRR.Duration)
        if (!IPS_VariableProfileExists('IRR.Duration')) {
            IPS_CreateVariableProfile('IRR.Duration', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.Duration', 1, 120, 1);
            IPS_SetVariableProfileText('IRR.Duration',   '', ' Min');
        }

        // Profile für Feuchteschwelle (IRR.MoistureThreshold)
        if (!IPS_VariableProfileExists('IRR.MoistureThreshold')) {
            IPS_CreateVariableProfile('IRR.MoistureThreshold', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.MoistureThreshold', 1, 100, 1);
            IPS_SetVariableProfileText('IRR.MoistureThreshold',   '', ' %');
        }

        // Profile für Beregnung-Ein/Aus (IRR.Irrigation)
        if (!IPS_VariableProfileExists('IRR.Irrigation')) {
            IPS_CreateVariableProfile('IRR.Irrigation', VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation('IRR.Irrigation', false, 'Aus',   '', 0xFF0000);
            IPS_SetVariableProfileAssociation('IRR.Irrigation', true,  'Ein',   '', 0x00FF00);
        }

        // Variablen mit Profilen
        $this->RegisterVariableInteger('Mode',               'Betriebsmodus',      'IRR.Mode',          10);
        $this->RegisterVariableInteger('Duration',           'Dauer (Min)',        'IRR.Duration',      20);
        $this->RegisterVariableInteger('MoistureThreshold',  'Feuchteschwelle (%)','IRR.MoistureThreshold',30);
        $this->RegisterVariableBoolean('Irrigation',         'Beregnung',          'IRR.Irrigation',    40);

        // Standardwerte setzen (nur beim Anlegen)
        $this->SetValue('Mode',              $this->ReadPropertyInteger('Mode'));
        $this->SetValue('Duration',          $this->ReadPropertyInteger('Duration'));
        $this->SetValue('MoistureThreshold', $this->ReadPropertyInteger('MoistureThreshold'));
        $this->SetValue('Irrigation',        false);

        // Web-Editing aktivieren
        $this->EnableAction('Mode');
        $this->EnableAction('Duration');
        $this->EnableAction('MoistureThreshold');
        $this->EnableAction('Irrigation');

        // Wochenplan-Event anlegen (Ereignis, manuell im UI konfigurieren)
        $eventName = 'IrrigationSchedule_' . $this->InstanceID;
        $eventId   = @IPS_GetObjectIDByName($eventName, $this->InstanceID);
        if ($eventId === false) {
            $eventId = IPS_CreateEvent(0); // 0 = Skript-Ereignis
            IPS_SetParent($eventId, $this->InstanceID);
            IPS_SetEventScript($eventId, 'IrrigationController_CheckAndIrrigate(' . $this->InstanceID . ');');
            IPS_SetName($eventId, $eventName);
            IPS_SetEventActive($eventId, false);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Event aktiv/inaktiv je nach Mode
        $mode    = $this->GetValue('Mode');
        $eventId = IPS_GetObjectIDByName('IrrigationSchedule_' . $this->InstanceID, $this->InstanceID);
        if ($eventId !== false) {
            // Zeitsteuerung (2) und Automatik (3) aktivieren
            IPS_SetEventActive($eventId, in_array($mode, [2, 3]));
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Mode':
            case 'Duration':
            case 'MoistureThreshold':
                $this->SetValue($Ident, $Value);
                $this->ApplyChanges();
                break;

            case 'Irrigation':
                $this->SetValue('Irrigation', $Value);
                if ($Value) {
                    // Manuell starten
                    $this->ActivateWatering();
                } else {
                    // Sofort stoppen
                    foreach (['Pump','Valve1','Valve2'] as $prop) {
                        $id = $this->ReadPropertyInteger($prop);
                        if ($id > 0) {
                            IPS_RequestAction($id, false);
                        }
                    }
                }
                break;

            default:
                throw new Exception('Unknown Ident ' . $Ident);
        }
    }

    public function CheckAndIrrigate()
    {
        $mode = $this->GetValue('Mode');
        switch ($mode) {
            case 0: // Aus
            case 1: // Manuell
                return;
            case 2: // Zeitsteuerung
                $this->ActivateWatering();
                break;
            case 3: // Automatik
                if ($this->ShouldWater()) {
                    $this->ActivateWatering();
                }
                break;
        }
    }

    private function ShouldWater(): bool
    {
        $threshold = $this->GetValue('MoistureThreshold');
        foreach (['MoistureSensor1','MoistureSensor2'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && is_numeric(GetValue($id)) && GetValue($id) < $threshold) {
                return true;
            }
        }
        return false;
    }

    private function ActivateWatering()
    {
        // Statusvariable setzen
        $this->SetValue('Irrigation', true);

        // Pumpen- & Ventil-Aktoren einschalten
        foreach (['Pump','Valve1','Valve2'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0) {
                IPS_RequestAction($id, true);
            }
        }

        // Bewässerungsdauer abwarten
        $duration = $this->GetValue('Duration');
        IPS_Sleep($duration * 60 * 1000);

        // Ausschalten
        foreach (['Pump','Valve1','Valve2'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0) {
                IPS_RequestAction($id, false);
            }
        }

        // Statusvariable zurücksetzen
        $this->SetValue('Irrigation', false);
    }

    private function TimeStringToSeconds(string $time): int
    {
        if (strpos($time, ':') === false) {
            return 0;
        }
        list($h, $m) = explode(':', $time);
        return ((int)$h * 3600 + (int)$m * 60);
    }
}
