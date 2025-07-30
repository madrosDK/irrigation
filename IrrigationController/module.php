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
        // Bewässerungsparameter als Properties
        $this->RegisterPropertyInteger('Mode', 0); // 0=Manuell,1=Automatik
        $this->RegisterPropertyInteger('Duration', 10);
        $this->RegisterPropertyInteger('MoistureThreshold', 30);

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

        // Variablen mit Profilen
        $this->RegisterVariableInteger('Mode', 'Betriebsmodus', 'IRR.Mode', 10);
        $this->RegisterVariableInteger('Duration', 'Dauer (Min)', 'IRR.Duration', 20);
        $this->RegisterVariableInteger('MoistureThreshold', 'Feuchteschwelle (%)', 'IRR.MoistureThreshold', 30);

        // Aktiviere Web-Editing
        $this->EnableAction('Mode');
        $this->EnableAction('Duration');
        $this->EnableAction('MoistureThreshold');

        // Wochenplan-Event anlegen
        $eventName = 'IrrigationSchedule_' . $this->InstanceID;
        $eventId = @IPS_GetObjectIDByName($eventName, $this->InstanceID);
        if ($eventId === false) {
            $eventId = IPS_CreateEvent(1); // zyklisches Ereignis
            IPS_SetParent($eventId, $this->InstanceID);
            IPS_SetEventScript($eventId, 'IrrigationController_CheckAndIrrigate(' . $this->InstanceID . ');');
            IPS_SetName($eventId, $eventName);
            // Standard: Montag 04:00
            IPS_SetEventActive($eventId, true);
            IPS_SetEventCyclic($eventId, 2, 1, 0, 0, 0);
            IPS_SetEventCyclicTimeFrom($eventId, 4 * 3600);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Event aktiv/inaktiv je nach Modus
        $mode = $this->ReadPropertyInteger('Mode');
        $eventName = 'IrrigationSchedule_' . $this->InstanceID;
        $eventId = IPS_GetObjectIDByName($eventName, $this->InstanceID);
        if ($eventId !== false) {
            IPS_SetEventActive($eventId, ($mode === 1));
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
            default:
                throw new Exception('Unknown Ident');
        }
    }

    public function CheckAndIrrigate()
    {
        if ($this->ReadPropertyInteger('Mode') !== 1) {
            return; // nur im Automatikmodus
        }
        if ($this->ShouldWater()) {
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
