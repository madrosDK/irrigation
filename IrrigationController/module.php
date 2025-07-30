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
        $this->RegisterPropertyInteger('RainLast24h', 0);
        $this->RegisterPropertyInteger('Valve1', 0);
        $this->RegisterPropertyInteger('Valve2', 0);
        $this->RegisterPropertyInteger('Pump', 0);
        // Bewässerungsparameter
        $this->RegisterPropertyInteger('Mode', 0);            // 0=Manuell,1=Zeitsteuerung,2=Automatik
        $this->RegisterPropertyInteger('Duration', 10);       // Dauer in Minuten
        $this->RegisterPropertyInteger('MoistureThreshold', 30); // Schwellwert Feuchte in %

        // Profile für Betriebsmodus
        if (!IPS_VariableProfileExists('IRR.Mode')) {
            IPS_CreateVariableProfile('IRR.Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.Mode', 0, 2, 1);
            IPS_SetVariableProfileAssociation('IRR.Mode', 0, 'Manuell', '', 0x808080);
            IPS_SetVariableProfileAssociation('IRR.Mode', 1, 'Zeitsteuerung', '', 0xFFFF00);
            IPS_SetVariableProfileAssociation('IRR.Mode', 2, 'Automatik', '', 0x00FF00);
        }
        // Profile für Dauer (1-120 Minuten)
        if (!IPS_VariableProfileExists('IRR.Duration')) {
            IPS_CreateVariableProfile('IRR.Duration', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.Duration', 1, 120, 1);
            IPS_SetVariableProfileText('IRR.Duration', '', ' Min');
        }
        // Profile für Feuchteschwelle (1-100%)
        if (!IPS_VariableProfileExists('IRR.MoistureThreshold')) {
            IPS_CreateVariableProfile('IRR.MoistureThreshold', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.MoistureThreshold', 1, 100, 1);
            IPS_SetVariableProfileText('IRR.MoistureThreshold', '', ' %');
        }
        // Profile für Master-Schalter
        if (!IPS_VariableProfileExists('IRR.Switch')) {
            IPS_CreateVariableProfile('IRR.Switch', VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation('IRR.Switch', false, 'Aus', 'Power', 0xFF0000);
            IPS_SetVariableProfileAssociation('IRR.Switch', true, 'Ein', 'Power', 0x00FF00);
        }

        // Variablen mit Profilen
        $this->RegisterVariableInteger('Mode', 'Betriebsmodus', 'IRR.Mode', 10);
        $this->RegisterVariableInteger('Duration', 'Dauer (Min)', 'IRR.Duration', 20);
        $this->RegisterVariableInteger('MoistureThreshold', 'Feuchteschwelle (%)', 'IRR.MoistureThreshold', 30);
        $this->RegisterVariableBoolean('Switch', 'Master Schalter', 'IRR.Switch', 40);

        // Aktiviere Web-Editing
        $this->EnableAction('Mode');
        $this->EnableAction('Duration');
        $this->EnableAction('MoistureThreshold');
        $this->EnableAction('Switch');

                // Variable für manuelle Bewässerung
        if (!IPS_VariableProfileExists('IRR.Manual')) {
            IPS_CreateVariableProfile('IRR.Manual', VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation('IRR.Manual', false, 'Aus', '', 0xFF0000);
            IPS_SetVariableProfileAssociation('IRR.Manual', true, 'Ein', '', 0x00FF00);
        }
        $this->RegisterVariableBoolean('Manual', 'Manuelle Bewässerung', 'IRR.Manual', 50);
        $this->EnableAction('Manual');

        // Wochenplan-Event anlegen (nur Ereignis) (nur Ereignis)
        $eventName = 'IrrigationSchedule_' . $this->InstanceID;
        $eventId = @IPS_GetObjectIDByName($eventName, $this->InstanceID);
        if ($eventId === false) {
            $eventId = IPS_CreateEvent(0);
            IPS_SetParent($eventId, $this->InstanceID);
            IPS_SetEventScript($eventId, 'IrrigationController_CheckAndIrrigate(' . $this->InstanceID . ');');
            IPS_SetName($eventId, $eventName);
            IPS_SetEventActive($eventId, false);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Event aktiv/inaktiv je nach Modus und Switch
        $mode = $this->ReadPropertyInteger('Mode');
        $enabled = $this->GetValue('Switch');
        $eventName = 'IrrigationSchedule_' . $this->InstanceID;
        $eventId = IPS_GetObjectIDByName($eventName, $this->InstanceID);
        if ($eventId !== false) {
            IPS_SetEventActive($eventId, $enabled && in_array($mode, [1, 2]));
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
            case 'Switch':
                $this->SetValue('Switch', $Value);
                $this->ApplyChanges();
                break;
            default:
                throw new Exception('Unknown Ident');
        }
    }

    public function CheckAndIrrigate()
    {
        // Master-Schalter prüfen
        if (!$this->GetValue('Switch')) {
            return;
        }
        $mode = $this->ReadPropertyInteger('Mode');
        if ($mode === 0) {
            return; // Manuell
        }
        if ($mode === 1) {
            $this->ActivateWatering();
            return;
        }
        if ($mode === 2 && $this->ShouldWater()) {
            $this->ActivateWatering();
        }
    }

    private function ShouldWater(): bool
    {
        $threshold = $this->ReadPropertyInteger('MoistureThreshold');
        foreach (['MoistureSensor1', 'MoistureSensor2'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && is_numeric(GetValue($id)) && GetValue($id) < $threshold) {
                return true;
            }
        }
        return false;
    }

    private function ActivateWatering()
    {
        $duration = $this->ReadPropertyInteger('Duration');
        foreach (['Pump', 'Valve1', 'Valve2'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0) {
                IPS_RequestAction($id, true);
            }
        }
        IPS_Sleep($duration * 60 * 1000);
        foreach (['Pump', 'Valve1', 'Valve2'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0) {
                IPS_RequestAction($id, false);
            }
        }
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
