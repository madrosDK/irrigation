<?php

declare(strict_types=1);

class IrrigationController extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger('Mode', 0); // 0=Aus, 1=Manuell, 2=Zeit, 3=Automatik
        $this->RegisterPropertyInteger('Duration', 5);
        $this->RegisterPropertyInteger('MoistureThreshold', 50);
        $this->RegisterPropertyInteger('Sensor1', 0);
        $this->RegisterPropertyInteger('Sensor2', 0);

        // Profile
        $this->RegisterProfiles();

        // Variablen
        $this->RegisterVariableInteger('Mode', 'Betriebsmodus', 'IRR.Mode', 1);
        $this->RegisterVariableInteger('Duration', 'Beregnungsdauer', 'IRR.Duration', 2);
        $this->RegisterVariableInteger('MoistureThreshold', 'Feuchteschwelle', 'IRR.Threshold', 3);
        $this->RegisterVariableBoolean('Irrigation', 'Beregnung', 'IRR.Irrigation', 4);

        $this->EnableAction('Mode');
        $this->EnableAction('Duration');
        $this->EnableAction('MoistureThreshold');
        $this->EnableAction('Irrigation');

        // Wochenpläne an Variable 'Irrigation'
        $this->CreateWeekplan('ScheduleTimer');
        $this->CreateWeekplan('ScheduleAuto');

        // Timer für Rücksetzung nach Dauer
        $this->RegisterTimer('IrrigationTimer', 0, 'IRR_StopIrrigation($_IPS[\'TARGET\']);');

        // Nur beim ersten Mal Standard setzen
        if ($this->GetBuffer('Initialized') !== '1') {
            $this->SetValue('Mode', 0);
            $this->SetValue('Duration', 5);
            $this->SetValue('MoistureThreshold', 50);
            $this->SetBuffer('Initialized', '1');
        }
    }

    private function RegisterProfiles()
    {
        if (!IPS_VariableProfileExists('IRR.Mode')) {
            IPS_CreateVariableProfile('IRR.Mode', 1);
            IPS_SetVariableProfileAssociation('IRR.Mode', 0, 'Aus', '', 0x000000);
            IPS_SetVariableProfileAssociation('IRR.Mode', 1, 'Manuell', '', 0x808080);
            IPS_SetVariableProfileAssociation('IRR.Mode', 2, 'Zeitsteuerung', '', 0xFFFF00);
            IPS_SetVariableProfileAssociation('IRR.Mode', 3, 'Automatik', '', 0x00FF00);
        }
        if (!IPS_VariableProfileExists('IRR.Duration')) {
            IPS_CreateVariableProfile('IRR.Duration', 1);
            IPS_SetVariableProfileText('IRR.Duration', '', ' Min');
            IPS_SetVariableProfileValues('IRR.Duration', 1, 120, 1);
        }
        if (!IPS_VariableProfileExists('IRR.Threshold')) {
            IPS_CreateVariableProfile('IRR.Threshold', 1);
            IPS_SetVariableProfileText('IRR.Threshold', '', ' %');
            IPS_SetVariableProfileValues('IRR.Threshold', 1, 100, 1);
        }
        if (!IPS_VariableProfileExists('IRR.Irrigation')) {
            IPS_CreateVariableProfile('IRR.Irrigation', 0);
            IPS_SetVariableProfileAssociation('IRR.Irrigation', false, 'Aus', '', 0xFF0000);
            IPS_SetVariableProfileAssociation('IRR.Irrigation', true, 'Ein', '', 0x00FF00);
        }
    }

    private function CreateWeekplan(string $name)
    {
        $parentID = @$this->GetIDForIdent('Irrigation');
        if ($parentID === false) {
            return;
        }
        $eid = @IPS_GetEventIDByName($name, $parentID);
        if ($eid === false) {
            $eid = IPS_CreateEvent(2); // Wochenplan
            IPS_SetName($eid, $name);
            IPS_SetParent($eid, $parentID);
            IPS_SetEventActive($eid, true);
            IPS_SetHidden($eid, true);
            IPS_SetEventScheduleAction($eid, 0, 'Aus', 0xFF0000, false);
            IPS_SetEventScheduleAction($eid, 1, 'Ein', 0x00FF00, true);
            for ($d = 0; $d <= 6; $d++) {
                IPS_SetEventScheduleGroup($eid, $d, 1 << $d);
                IPS_SetEventScheduleGroupPoint($eid, $d, 0, 0, 0, 0, 0);
            }
            if ($name == 'ScheduleTimer' || $name == 'ScheduleAuto') {
                foreach ([1, 3] as $d) {
                    IPS_SetEventScheduleGroupPoint($eid, $d, 1, 3, 0, 0, 1);
                    IPS_SetEventScheduleGroupPoint($eid, $d, 2, 3, 30, 0, 0);
                }
            }
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $mode = $this->GetValue('Mode');
        $idIrr = @$this->GetIDForIdent('Irrigation');

        $idTimer = @IPS_GetEventIDByName('ScheduleTimer', $idIrr);
        $idAuto = @IPS_GetEventIDByName('ScheduleAuto', $idIrr);

        if ($idTimer !== false) {
            IPS_SetEventActive($idTimer, $mode == 2);
            IPS_SetHidden($idTimer, $mode != 2);
        }
        if ($idAuto !== false) {
            IPS_SetEventActive($idAuto, $mode == 3);
            IPS_SetHidden($idAuto, $mode != 3);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Mode':
                $this->SetValue('Mode', $Value);
                if ($Value == 0) {
                    // Bei Modus "Aus" alles stoppen
                    $this->SetValue('Irrigation', false);
                    $this->SetTimerInterval('IrrigationTimer', 0);
                }
                $this->ApplyChanges();
                break;

            case 'Duration':
                $this->SetValue('Duration', $Value);
                // Timerlaufzeit ggf. neu berechnen
                if ($this->GetValue('Irrigation')) {
                    $startTime = intval($this->GetBuffer('IrrigationStart'));
                    $now = time();
                    $elapsed = intval(($now - $startTime) / 60);
                    $remaining = max(0, $Value - $elapsed);
                    $this->SetTimerInterval('IrrigationTimer', $remaining * 60 * 1000);
                }
                break;

            case 'MoistureThreshold':
                $this->SetValue('MoistureThreshold', $Value);
                break;

            case 'Irrigation':
                if ($Value) {
                    $this->SetBuffer('IrrigationStart', (string)time());
                    $duration = $this->GetValue('Duration');
                    $this->SetTimerInterval('IrrigationTimer', $duration * 60 * 1000);
                } else {
                    $this->SetTimerInterval('IrrigationTimer', 0);
                }
                $this->SetValue('Irrigation', $Value);
                break;
        }
    }


    public function GetConfigurationForm()
{
    $mode = $this->GetValue('Mode');

    $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);

    foreach ($form['elements'] as &$element) {
        switch ($element['name']) {
            case 'Duration':
                $element['visible'] = ($mode == 1); // nur manuell
                break;
            case 'MoistureThreshold':
                $element['visible'] = ($mode == 2 || $mode == 3); // nur Zeit/Automatik
                break;
            case 'Irrigation':
                $element['visible'] = ($mode != 0); // nicht bei AUS
                break;
        }
    }

    return json_encode($form);
}


    public function StopIrrigation()
    {
        $this->SetTimerInterval('IrrigationTimer', 0);
        $this->SetValue('Irrigation', false);
    }
}
