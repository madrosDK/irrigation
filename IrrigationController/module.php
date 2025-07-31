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
        $this->RegisterPropertyInteger('MoistureThreshold',50);  // Standard-Schwelle in %

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
        $this->RegisterVariableInteger('Mode',               'Betriebsmodus',       'IRR.Mode',            10);
        $this->RegisterVariableInteger('Duration',           'Dauer (Min)',         'IRR.Duration',        20);
        $this->RegisterVariableInteger('MoistureThreshold',  'Feuchteschwelle (%)', 'IRR.MoistureThreshold',30);
        $this->RegisterVariableBoolean('Irrigation',         'Beregnung',           'IRR.Irrigation',      40);

        $this->EnableAction('Mode');
        $this->EnableAction('Duration');
        $this->EnableAction('MoistureThreshold');
        $this->EnableAction('Irrigation');

        //--- Timer: schaltet nach Duration wieder ab ---
        $this->RegisterTimer('IrrigationTimer', 0, 'IRR_RequestAction($_IPS["TARGET"], "Irrigation", false);');

        //--- Wochenplan-Event (Typ 2 = Schedule Event) ---
        $eventName = 'IrrigationSchedule_' . $this->InstanceID;
        $eventId   = @IPS_GetEventIDByName($eventName, $this->InstanceID);
        if ($eventId === false) {
            $eventId = IPS_CreateEvent(2); // Wochenplan
            IPS_SetParent($eventId, $this->InstanceID);
            IPS_SetName($eventId, $eventName);
            IPS_SetEventActive($eventId, false);

            // Aktionen definieren
            IPS_SetEventScheduleAction($eventId, 0, 'Aus', 0xFF0000, false);
            IPS_SetEventScheduleAction($eventId, 1, 'Ein', 0x00FF00, true);

            // Gruppe 0 = Montag (Bitmaske: 1)
            IPS_SetEventScheduleGroup($eventId, 0, 1);
            IPS_SetEventScheduleGroupPoint($eventId, 0, 0, 4, 0, 0, 1); // 04:00 Uhr → Aktion "Ein"

            // Zielvariable zuweisen
            IPS_SetEventTriggerTargetID($eventId, $this->GetIDForIdent('Irrigation')); // ✅
        }


    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Initialisierung nur einmal
        if ($this->GetBuffer('Initialized') !== '1') {
            $this->SetValue('Mode',              $this->ReadPropertyInteger('Mode'));
            $this->SetValue('Duration',          $this->ReadPropertyInteger('Duration'));
            $this->SetValue('MoistureThreshold', $this->ReadPropertyInteger('MoistureThreshold'));
            $this->SetValue('Irrigation',        false);
            $this->SetBuffer('Initialized', '1');
        }
        // Wochenplan aktivieren/deaktivieren je nach Modus
        $mode    = $this->GetValue('Mode');
        $eventId = IPS_GetEventIDByName('IrrigationSchedule_' . $this->InstanceID, $this->InstanceID);
        if ($eventId !== false) {
            // Mode 2 & 3 erlauben Wochenplan
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
                $this->SetValue('Duration', $Value);
                // während laufender Beregnung Restzeit anpassen
                if ($this->GetValue('Irrigation')) {
                    $start     = (int)$this->GetBuffer('IrrigationStart');
                    $elapsed   = time() - $start;
                    $remaining = max(0, $Value * 60 - $elapsed);
                    $this->SetTimerInterval('IrrigationTimer', $remaining * 1000);
                }
                break;

            case 'MoistureThreshold':
                $this->SetValue('MoistureThreshold', $Value);
                break;

            case 'Irrigation':
                if ($Value) {
                    // manuell oder durch Wochenplan gestartet
                    $this->SetValue('Irrigation', true);
                    $this->SetBuffer('IrrigationStart', (string)time());
                    foreach (['Pump','Valve1','Valve2'] as $prop) {
                        $id = $this->ReadPropertyInteger($prop);
                        if ($id > 0) {
                            IPS_RequestAction($id, true);
                        }
                    }
                    // Timer starten
                    $this->SetTimerInterval('IrrigationTimer', $this->GetValue('Duration') * 60 * 1000);
                } else {
                    // sofort abbrechen
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
        // nur im Automatikmodus
        if ($this->GetValue('Mode') !== 3) {
            return;
        }
        if ($this->ShouldWater()) {
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
