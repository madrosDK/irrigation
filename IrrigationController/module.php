<?php

declare(strict_types=1);

class IrrigationController extends IPSModule
{
    public function Create()
    {
        parent::Create();
        // Properties for sensor and actor instance IDs
        $this->RegisterPropertyInteger('MoistureSensor1', 0);
        $this->RegisterPropertyInteger('MoistureSensor2', 0);
        $this->RegisterPropertyInteger('RainLast24h', 0);
        $this->RegisterPropertyInteger('Valve1', 0);
        $this->RegisterPropertyInteger('Valve2', 0);
        $this->RegisterPropertyInteger('Pump', 0);

        // Create profile for Betriebsmodus
        if (!IPS_VariableProfileExists('IRR.Mode')) {
            IPS_CreateVariableProfile('IRR.Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.Mode', 0, 2, 1);
            IPS_SetVariableProfileAssociation('IRR.Mode', 0, 'Manuell', '', 0x808080);
            IPS_SetVariableProfileAssociation('IRR.Mode', 1, 'Zeitsteuerung', '', 0xFFFF00);
            IPS_SetVariableProfileAssociation('IRR.Mode', 2, 'Automatik', '', 0x00FF00);
        }

        // User-configurable variables
        $this->RegisterVariableInteger('Mode', 'Betriebsmodus', 'IRR.Mode', 10);
        $this->RegisterVariableInteger('Days', 'Wochentage', '', 20);
        $this->RegisterVariableString('StartTime', 'Startzeit', '', 30);
        $this->RegisterVariableInteger('Duration', 'Dauer (Min)', '', 40);
        $this->RegisterVariableInteger('MoistureThreshold', 'Feuchteschwelle (%)', '', 50);

        // Enable actions for variables to be editable in web interface
        $this->EnableAction('Mode');
        $this->EnableAction('Days');
        $this->EnableAction('StartTime');
        $this->EnableAction('Duration');
        $this->EnableAction('MoistureThreshold');

        // Timers
        $this->RegisterTimer('IrrigateTimer', 0, 'IRR_CheckAndIrrigate($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Setup timer based on Mode
        $mode = $this->GetValue('Mode');
        switch ($mode) {
            case 0: // Manuell
                $this->SetTimerInterval('IrrigateTimer', 0);
                break;
            case 1: // Zeitsteuerung
                $start = $this->GetValue('StartTime');
                $seconds = $this->TimeStringToSeconds($start);
                $this->SetTimerInterval('IrrigateTimer', 24 * 3600 * 1000);
                IPS_SetEventCyclicTimeFrom('IrrigateTimer', $seconds);
                break;
            case 2: // Automatik
                $start = $this->GetValue('StartTime');
                $seconds = $this->TimeStringToSeconds($start);
                $this->SetTimerInterval('IrrigateTimer', 24 * 3600 * 1000);
                IPS_SetEventCyclicTimeFrom('IrrigateTimer', $seconds);
                break;
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Mode':
            case 'Days':
            case 'StartTime':
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
        $mode = $this->GetValue('Mode');
        $shouldIrrigate = false;
        if ($mode === 1) {
            // Zeitsteuerung ignoriert Feuchte
            $shouldIrrigate = true;
        } elseif ($mode === 2) {
            // Automatik: Feuchte prüfen
            $shouldIrrigate = $this->ShouldWater();
        }
        if ($shouldIrrigate) {
            $this->ActivateWatering();
        }
    }

    private function ShouldWater(): bool
    {
        $threshold = $this->GetValue('MoistureThreshold');
        $values = [];
        foreach (['MoistureSensor1','MoistureSensor2'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0) {
                $val = GetValue($id);
                $values[] = is_numeric($val) ? $val : null;
            }
        }
        foreach ($values as $v) {
            if ($v !== null && $v < $threshold) {
                return true;
            }
        }
        return false;
    }

    private function ActivateWatering()
    {
        $duration = $this->GetValue('Duration');
        foreach (['Pump','Valve1','Valve2'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0) {
                RequestAction($id, true);
            }
        }
        IPS_Sleep($duration * 60 * 1000);
        foreach (['Pump','Valve1','Valve2'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0) {
                RequestAction($id, false);
            }
        }
    }

    private function TimeStringToSeconds(string $time): int
    {
        list($h,$m) = explode(':',$time);
        return ((int)$h*3600 + (int)$m*60);
    }
}
