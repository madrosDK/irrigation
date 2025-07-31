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
        IPS_SetVariableProfileAssociation('IRR.Mode', 0, 'Aus',           '', 0x000000);
        IPS_SetVariableProfileAssociation('IRR.Mode', 1, 'Manuell',       '', 0x808080);
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

        // Profile für Beregnung Ein/Aus (IRR.Irrigation)
        if (!IPS_VariableProfileExists('IRR.Irrigation')) {
            IPS_CreateVariableProfile('IRR.Irrigation', VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation('IRR.Irrigation', false, 'Aus', '', 0xFF0000);
            IPS_SetVariableProfileAssociation('IRR.Irrigation', true,  'Ein', '', 0x00FF00);
        }

        // Variablen mit Profilen
        $this->RegisterVariableInteger('Mode',              'Betriebsmodus',       'IRR.Mode',           10);
        $this->RegisterVariableInteger('Duration',          'Dauer (Min)',         'IRR.Duration',       20);
        $this->RegisterVariableInteger('MoistureThreshold', 'Feuchteschwelle (%)','IRR.MoistureThreshold',30);
        $this->RegisterVariableBoolean('Irrigation',        'Beregnung',           'IRR.Irrigation',     40);

        // Web-Editing aktivieren
        $this->EnableAction('Mode');
        $this->EnableAction('Duration');
        $this->EnableAction('MoistureThreshold');
        $this->EnableAction('Irrigation');

        // Wochenplan-Event anlegen (Typ=1 zyklisch, Tage/Uhrzeit manuell im UI einstellen)
        $eventName = 'IrrigationSchedule_' . $this->InstanceID;
        $eventId   = @IPS_GetObjectIDByName($eventName, $this->InstanceID);
        if ($eventId === false) {
            $eventId = IPS_CreateEvent(1);
            IPS_SetParent($eventId, $this->InstanceID);
            // Wenn das Event feuert, setzen wir die Variable 'Irrigation' auf true => löst ActivateWatering()
            IPS_SetEventScript($eventId, 'IRR_RequestAction(' . $this->InstanceID . ',"Irrigation",true);');
            IPS_SetName($eventId, $eventName);
            IPS_SetEventActive($eventId, false);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // 1) Einmalige Initialisierung der Standardwerte
        if ($this->GetBuffer('Initialized') !== '1') {
            $this->SetValue('Mode',              $this->ReadPropertyInteger('Mode'));
            $this->SetValue('Duration',          $this->ReadPropertyInteger('Duration'));
            $this->SetValue('MoistureThreshold', $this->ReadPropertyInteger('MoistureThreshold'));
            $this->SetValue('Irrigation',        false);
            $this->SetBuffer('Initialized', '1');
        }

        // 2) Event aktiv/inaktiv je nach Modus (2=Zeitsteuerung,3=Automatik)
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
            case 'Duration':
            case 'MoistureThreshold':
                $this->SetValue($Ident, $Value);
                $this->ApplyChanges();
                break;

            case 'Irrigation':
                $this->SetValue('Irrigation', $Value);
                if ($Value) {
                    // Manuell oder durch Wochenplan-Event gestartet
                    $this->ActivateWatering();
                } else {
                    // Sofort abbrechen
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
        // Nur bei Automatik (Mode = 3) prüfen
        if ($this->GetValue('Mode') !== 3) {
            return;
        }
        if ($this->ShouldWater()) {
            $this->ActivateWatering();
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
        // 1) Statusvariable setzen
        $this->SetValue('Irrigation', true);

        // 2) Pumpen- & Ventil-Aktoren einschalten
        foreach (['Pump','Valve1','Valve2'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0) {
                IPS_RequestAction($id, true);
            }
        }

        // 3) Bewässerungsdauer abwarten
        $duration = $this->GetValue('Duration');
        IPS_Sleep($duration * 60 * 1000);

        // 4) Ausschalten
        foreach (['Pump','Valve1','Valve2'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0) {
                IPS_RequestAction($id, false);
            }
        }

        // 5) Statusvariable zurücksetzen
        $this->SetValue('Irrigation', false);
    }
}
