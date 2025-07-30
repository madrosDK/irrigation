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
        $this->RegisterPropertyInteger('Mode', 0); // 0=Manuell,1=Automatik
        $this->RegisterPropertyInteger('Duration', 10);
        $this->RegisterPropertyInteger('MoistureThreshold', 30);

        // Erstelle Wochenplan-Event nur einmal
        $eventName = 'IrrigationSchedule_' . $this->InstanceID;
        $eventId = @IPS_GetObjectIDByName($eventName, $this->InstanceID);
        if ($eventId === false) {
            $eventId = IPS_CreateEvent(1); // 1 = zyklisches Ereignis
            IPS_SetParent($eventId, $this->InstanceID);
            IPS_SetEventScript($eventId, 'IrrigationController_CheckAndIrrigate(' . $this->InstanceID . ');');
            IPS_SetName($eventId, $eventName);
        }
                // Wochenplan konfigurieren (wöchentlich) – bitte Tage und Zeiten im IP‑Symcon Ereignis‑UI einstellen
                // Wochenplan: bitte über Ereignis-UI Tage und Zeit einstellen // Typ 2 = wöchentlich
        IPS_SetEventActive($eventId, true);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Event bleibt aktiv oder inaktiv je nach Mode
        $mode = $this->ReadPropertyInteger('Mode');
        $eventName = 'IrrigationSchedule_' . $this->InstanceID;
        $eventId = IPS_GetObjectIDByName($eventName, $this->InstanceID);
        if ($eventId !== false) {
            IPS_SetEventActive($eventId, $mode === 1);
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
        // Im Automatiksmodus Feuchte prüfen
        if ($this->ReadPropertyInteger('Mode') !== 1) {
            return;
        }
        if ($this->ShouldWater()) {
            $this->ActivateWatering();
        }
    }

    private function ShouldWater(): bool
    {
        $threshold = $this->ReadPropertyInteger('MoistureThreshold');
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
        $duration = $this->ReadPropertyInteger('Duration');
        foreach (['Pump','Valve1','Valve2'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0) {
                IPS_RequestAction($id, true);
            }
        }
        IPS_Sleep($duration * 60 * 1000);
        foreach (['Pump','Valve1','Valve2'] as $prop) {
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
