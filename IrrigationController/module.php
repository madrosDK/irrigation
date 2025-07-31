<?php

declare(strict_types=1);

class IrrigationController extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger('MoistureSensor1', 0);
        $this->RegisterPropertyInteger('MoistureSensor2', 0);
        $this->RegisterPropertyInteger('RainLast24h', 0);
        $this->RegisterPropertyInteger('Valve1', 0);
        $this->RegisterPropertyInteger('Valve2', 0);
        $this->RegisterPropertyInteger('Pump', 0);

        $this->RegisterPropertyInteger('Mode', 0); // 0=Aus, 1=Manuell, 2=Zeitsteuerung, 3=Automatik
        $this->RegisterPropertyInteger('Duration', 5);
        $this->RegisterPropertyInteger('MoistureThreshold', 50);

        // Profiles
        if (!IPS_VariableProfileExists('IRR.Mode')) {
            IPS_CreateVariableProfile('IRR.Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.Mode', 0, 3, 1);
            IPS_SetVariableProfileAssociation('IRR.Mode', 0, 'Aus', '', 0x000000);
            IPS_SetVariableProfileAssociation('IRR.Mode', 1, 'Manuell', '', 0x808080);
            IPS_SetVariableProfileAssociation('IRR.Mode', 2, 'Zeitsteuerung', '', 0xFFFF00);
            IPS_SetVariableProfileAssociation('IRR.Mode', 3, 'Automatik', '', 0x00FF00);
        }

        if (!IPS_VariableProfileExists('IRR.Duration')) {
            IPS_CreateVariableProfile('IRR.Duration', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.Duration', 1, 120, 1);
            IPS_SetVariableProfileText('IRR.Duration', '', ' Min');
        }

        if (!IPS_VariableProfileExists('IRR.MoistureThreshold')) {
            IPS_CreateVariableProfile('IRR.MoistureThreshold', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.MoistureThreshold', 1, 100, 1);
            IPS_SetVariableProfileText('IRR.MoistureThreshold', '', ' %');
        }

        if (!IPS_VariableProfileExists('IRR.Irrigation')) {
            IPS_CreateVariableProfile('IRR.Irrigation', VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation('IRR.Irrigation', false, 'Aus', '', 0xFF0000);
            IPS_SetVariableProfileAssociation('IRR.Irrigation', true, 'Ein', '', 0x00FF00);
        }

        // Variables
        $this->RegisterVariableInteger('Mode', 'Betriebsmodus', 'IRR.Mode', 10);
        $this->RegisterVariableInteger('Duration', 'Dauer (Min)', 'IRR.Duration', 20);
        $this->RegisterVariableInteger('MoistureThreshold', 'Feuchteschwelle (%)', 'IRR.MoistureThreshold', 30);
        $this->RegisterVariableBoolean('Irrigation', 'Beregnung', 'IRR.Irrigation', 40);

        // Enable Action
        $this->EnableAction('Mode');
        $this->EnableAction('Duration');
        $this->EnableAction('MoistureThreshold');
        $this->EnableAction('Irrigation');

        // Timer
        $this->RegisterTimer('IrrigationTimer', 0, 'IRR_RequestAction($_IPS["TARGET"], "Irrigation", false);');

        // Initialwerte nur beim ersten Anlegen setzen
        if ($this->GetBuffer('Initialized') !== '1') {
            $this->SetValue('Mode', $this->ReadPropertyInteger('Mode'));
            $this->SetValue('Duration', $this->ReadPropertyInteger('Duration'));
            $this->SetValue('MoistureThreshold', $this->ReadPropertyInteger('MoistureThreshold'));
            $this->SetBuffer('Initialized', '1');
        }

        // Wochenplan an Variable "Irrigation" hängen
        $varId = $this->GetIDForIdent('Irrigation');
        $eventName = 'IrrigationSchedule';

        $eventId = @IPS_GetEventIDByName($eventName, $varId);
        if ($eventId === false) {
            $eventId = IPS_CreateEvent(2); // Wochenplan
            IPS_SetName($eventId, $eventName);
            IPS_SetParent($eventId, $varId);
            IPS_SetEventActive($eventId, true);

            // Aktionen
            IPS_SetEventScheduleAction($eventId, 0, 'Aus', 0xFF0000, false);
            IPS_SetEventScheduleAction($eventId, 1, 'Ein', 0x00FF00, true);

            // Alle 7 Gruppen vorbereiten (auch wenn leer)
            $weekdays = [
                0 => 1,     // Montag
                1 => 2,     // Dienstag
                2 => 4,     // Mittwoch
                3 => 8,     // Donnerstag
                4 => 16,    // Freitag
                5 => 32,    // Samstag
                6 => 64     // Sonntag
            ];

            foreach ($weekdays as $groupId => $bitmask) {
                IPS_SetEventScheduleGroup($eventId, $groupId, $bitmask);
            }

            // Nur Dienstag (1) und Donnerstag (3) belegen
            foreach ([1, 3] as $groupId) {
                IPS_SetEventScheduleGroupPoint($eventId, $groupId, 0, 0, 0, 0, 0);     // 00:00 Aus
                IPS_SetEventScheduleGroupPoint($eventId, $groupId, 1, 3, 0, 0, 1);     // 03:00 Ein
                IPS_SetEventScheduleGroupPoint($eventId, $groupId, 2, 3, 30, 0, 0);    // 03:30 Aus
            }
        }

    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Wochenplan-Ereignis aktivieren/deaktivieren je nach Modus
        $mode = $this->GetValue('Mode');
        $varId = $this->GetIDForIdent('Irrigation');
        $eventId = @IPS_GetEventIDByName('IrrigationSchedule', $varId);
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
                $this->SetValue('Duration', $Value);
                if ($this->GetValue('Irrigation')) {
                    $start = intval($this->GetBuffer('IrrigationStart'));
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
                    // Start
                    $this->SetValue('Irrigation', true);
                    $this->SetBuffer('IrrigationStart', (string)time());

                    foreach (['Pump','Valve1','Valve2'] as $prop) {
                        $id = $this->ReadPropertyInteger($prop);
                        if ($id > 0) {
                            @RequestAction($id, true);
                        }
                    }

                    $interval = $this->GetValue('Duration') * 60 * 1000;
                    $this->SetTimerInterval('IrrigationTimer', $interval);
                } else {
                    // Stopp
                    $this->SetTimerInterval('IrrigationTimer', 0);

                    foreach (['Pump','Valve1','Valve2'] as $prop) {
                        $id = $this->ReadPropertyInteger($prop);
                        if ($id > 0) {
                            @RequestAction($id, false);
                        }
                    }

                    $this->SetValue('Irrigation', false);
                }
                break;
        }
    }

    public function CheckAndIrrigate()
    {
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
