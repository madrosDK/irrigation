<?php

declare(strict_types=1);

class IrrigationController extends IPSModule
{
    public function Create()
    {
        parent::Create();

        //--- Properties ---
        $this->RegisterPropertyInteger('MoistureSensor1',     0);
        $this->RegisterPropertyInteger('MoistureSensor2',     0);
        $this->RegisterPropertyInteger('RainLast24h',         0);
        $this->RegisterPropertyInteger('Valve1',              0);
        $this->RegisterPropertyInteger('Valve2',              0);
        $this->RegisterPropertyInteger('Pump',                0);

        $this->RegisterPropertyInteger('Mode',               0);  // 0=Aus,1=Manuell,2=Zeitsteuerung,3=Automatik
        $this->RegisterPropertyInteger('Duration',           5);  // Standarddauer in Minuten
        $this->RegisterPropertyInteger('MoistureThreshold', 50);  // Standard-Schwelle in %

        //--- Profiles ---
        if (IPS_VariableProfileExists('IRR.Mode')) {
            IPS_DeleteVariableProfile('IRR.Mode');
        }
        IPS_CreateVariableProfile('IRR.Mode', VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileValues('IRR.Mode', 0, 3, 1);
        IPS_SetVariableProfileAssociation('IRR.Mode', 0, 'Aus',           '', 0x000000);
        IPS_SetVariableProfileAssociation('IRR.Mode', 1, 'Manuell',       '', 0x808080);
        IPS_SetVariableProfileAssociation('IRR.Mode', 2, 'Zeitsteuerung','', 0xFFFF00);
        IPS_SetVariableProfileAssociation('IRR.Mode', 3, 'Automatik',    '', 0x00FF00);

        if (!IPS_VariableProfileExists('IRR.Duration')) {
            IPS_CreateVariableProfile('IRR.Duration', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.Duration', 1, 120, 1);
            IPS_SetVariableProfileText('IRR.Duration',   '', ' Min');
        }
        if (!IPS_VariableProfileExists('IRR.MoistureThreshold')) {
            IPS_CreateVariableProfile('IRR.MoistureThreshold', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.MoistureThreshold', 1, 100, 1);
            IPS_SetVariableProfileText('IRR.MoistureThreshold',   '', ' %');
        }
        if (!IPS_VariableProfileExists('IRR.Irrigation')) {
            IPS_CreateVariableProfile('IRR.Irrigation', VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation('IRR.Irrigation', false, 'Aus', '', 0xFF0000);
            IPS_SetVariableProfileAssociation('IRR.Irrigation', true,  'Ein', '', 0x00FF00);
        }

        //--- Variables ---
        $this->RegisterVariableInteger('Mode',               'Betriebsmodus',      'IRR.Mode',           10);
        $this->RegisterVariableInteger('Duration',           'Dauer (Min)',        'IRR.Duration',       20);
        $this->RegisterVariableInteger('MoistureThreshold',  'Feuchteschwelle (%)','IRR.MoistureThreshold',30);
        $this->RegisterVariableBoolean('Irrigation',         'Beregnung',          'IRR.Irrigation',     40);

        // Enable web editing
        $this->EnableAction('Mode');
        $this->EnableAction('Duration');
        $this->EnableAction('MoistureThreshold');
        $this->EnableAction('Irrigation');

        //--- Duration timer for irrigation stop ---
        // Calls RequestAction("Irrigation", false) when time expires
        $this->RegisterTimer('IrrigationTimer', 0, 'IRR_RequestAction($_IPS["TARGET"], "Irrigation", false);');

        //--- Weekly schedule event (configured in UI) ---
        $eventName = 'IrrigationSchedule_' . $this->InstanceID;
        $eventId   = @IPS_GetObjectIDByName($eventName, $this->InstanceID);
        if ($eventId === false) {
            $eventId = IPS_CreateEvent(1); // 1 = cyclic event
            IPS_SetParent($eventId, $this->InstanceID);
            // Weekly event should trigger irrigation
            IPS_SetEventScript($eventId, 'IRR_RequestAction(' . $this->InstanceID . ',"Irrigation",true);');
            IPS_SetName($eventId, $eventName);
            IPS_SetEventActive($eventId, false);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        //--- One-time initialization of variable values ---
        if ($this->GetBuffer('Initialized') !== '1') {
            $this->SetValue('Mode',              $this->ReadPropertyInteger('Mode'));
            $this->SetValue('Duration',          $this->ReadPropertyInteger('Duration'));
            $this->SetValue('MoistureThreshold', $this->ReadPropertyInteger('MoistureThreshold'));
            $this->SetValue('Irrigation',        false);
            $this->SetBuffer('Initialized', '1');
        }

        //--- Activate/Deactivate weekly schedule event based on mode ---
        $mode    = $this->GetValue('Mode');
        $eventId = IPS_GetObjectIDByName('IrrigationSchedule_' . $this->InstanceID, $this->InstanceID);
        if ($eventId !== false) {
            IPS_SetEventActive($eventId, in_array($mode, [2, 3]));
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Mode':
                $this->SetValue('Mode', $Value);
                $this->ApplyChanges();
                break;

            case 'Duration':
                // Adjust remaining timer if currently irrigating
                $this->SetValue('Duration', $Value);
                if ($this->GetValue('Irrigation')) {
                    $start   = intval($this->GetBuffer('IrrigationStart'));
                    $elapsed = time() - $start;
                    $remaining = max(0, $Value * 60 - $elapsed);
                    $this->SetTimerInterval('IrrigationTimer', $remaining * 1000);
                }
                break;

            case 'MoistureThreshold':
                $this->SetValue('MoistureThreshold', $Value);
                break;

            case 'Irrigation':
                if ($Value) {
                    // Start irrigation
                    $this->SetValue('Irrigation', true);
                    // Record start time
                    $this->SetBuffer('IrrigationStart', (string)time());
                    // Switch on actuators
                    foreach (['Pump','Valve1','Valve2'] as $prop) {
                        $id = $this->ReadPropertyInteger($prop);
                        if ($id > 0) {
                            IPS_RequestAction($id, true);
                        }
                    }
                    // Schedule stop based on current Duration
                    $interval = $this->GetValue('Duration') * 60 * 1000;
                    $this->SetTimerInterval('IrrigationTimer', $interval);
                    // Also cancel any scheduled weekly irrigation
                    $this->SetValue('Mode', 0);
                    $this->ApplyChanges();
                } else {
                    // Stop irrigation immediately
                    $this->SetTimerInterval('IrrigationTimer', 0);
                    foreach (['Pump','Valve1','Valve2'] as $prop) {
                        $id = $this->ReadPropertyInteger($prop);
                        if ($id > 0) {
                            IPS_RequestAction($id, false);
                        }
                    }
                    $this->SetValue('Irrigation', false);
                }
                break;

            default:
                throw new Exception('Unknown Ident ' . $Ident);
        }
    }

    public function CheckAndIrrigate()
    {
        // Only for Automatik mode (3)
        if ($this->GetValue('Mode') !== 3) {
            return;
        }
        if ($this->ShouldWater()) {
            // Trigger as if user toggled irrigation on
            $this->RequestAction('Irrigation', true);
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
}
