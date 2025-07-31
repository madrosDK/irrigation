<?php

declare(strict_types=1);

class IrrigationController extends IPSModule
{
  public function Create()
  {
      parent::Create();

      // === Properties ===
      $this->RegisterPropertyInteger('MoistureSensor1', 0);
      $this->RegisterPropertyInteger('MoistureSensor2', 0);
      $this->RegisterPropertyInteger('RainLast24h', 0);
      $this->RegisterPropertyInteger('Valve1', 0);
      $this->RegisterPropertyInteger('Valve2', 0);
      $this->RegisterPropertyInteger('Pump', 0);

      $this->RegisterPropertyInteger('Mode', 0);
      $this->RegisterPropertyInteger('Duration', 5);
      $this->RegisterPropertyInteger('MoistureThreshold', 50);

      // === Profile ===
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

      // === Variablen nur registrieren, wenn sie fehlen ===
      $this->RegisterVariableIfMissing('Mode', 'Betriebsmodus', 'IRR.Mode', 10);
      $this->RegisterVariableIfMissing('Duration', 'Dauer (Min)', 'IRR.Duration', 20);
      $this->RegisterVariableIfMissing('MoistureThreshold', 'Feuchteschwelle (%)', 'IRR.MoistureThreshold', 30);
      $this->RegisterVariableIfMissing('Irrigation', 'Beregnung', 'IRR.Irrigation', 40);

      // === Aktionen aktivieren ===
      foreach (['Mode', 'Duration', 'MoistureThreshold', 'Irrigation'] as $ident) {
          $this->EnableAction($ident);
      }

      // === Timer anlegen ===
      if (!@IPS_GetObjectIDByIdent('IrrigationTimer', $this->InstanceID)) {
          $this->RegisterTimer('IrrigationTimer', 0, 'IRR_RequestAction($_IPS["TARGET"], "Irrigation", false);');
      }

      // === Initialwerte setzen, aber nur beim ersten Erstellen ===
      if ($this->GetBuffer('Initialized') !== '1') {
          $this->SetValue('Mode', $this->ReadPropertyInteger('Mode'));
          $this->SetValue('Duration', $this->ReadPropertyInteger('Duration'));
          $this->SetValue('MoistureThreshold', $this->ReadPropertyInteger('MoistureThreshold'));
          $this->SetBuffer('Initialized', '1');
      }

      // === Wochenpläne erstellen ===
      $this->CreateWeekplan('ScheduleTimer', true);   // Zeitsteuerung
      $this->CreateWeekplan('ScheduleAuto', false);   // Automatik
  }


    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $mode = $this->GetValue('Mode');

        $idTimer = @IPS_GetEventIDByName('ScheduleTimer', $this->GetIDForIdent('Irrigation'));
        $idAuto = @IPS_GetEventIDByName('ScheduleAuto', $this->InstanceID);

        if ($idTimer !== false) IPS_SetHidden($idTimer, $mode !== 2);
        if ($idAuto !== false) IPS_SetHidden($idAuto, $mode !== 3);
        if ($idTimer !== false) IPS_SetEventActive($idTimer, $mode === 2);
        if ($idAuto !== false) IPS_SetEventActive($idAuto, $mode === 3);
    }

    private function RegisterVariableIfMissing(string $ident, string $name, string $profile, int $position)
    {
        if (@$this->GetIDForIdent($ident) === false) {
            $this->RegisterVariable(VARIABLETYPE_BOOLEAN, $ident, $name, $profile, $position);
            if ($profile !== '') {
                IPS_SetVariableCustomProfile($this->GetIDForIdent($ident), $profile);
            }
        }
    }


    public function RequestAction($ident, $value)
    {
        switch ($ident) {
            case 'Mode':
                $this->SetValue('Mode', $value);
                $this->ApplyChanges(); // Sichtbarkeit der Wochenpläne anpassen
                break;

            case 'Duration':
                $this->SetValue('Duration', $value);
                break;

            case 'MoistureThreshold':
                $this->SetValue('MoistureThreshold', $value);
                break;

            case 'Irrigation':
                $this->SetValue('Irrigation', $value);
                $this->SwitchActuators($value);
                if ($value) {
                    $remaining = $this->GetValue('Duration') * 60;
                    $this->SetTimerInterval('IrrigationTimer', $remaining * 1000);
                } else {
                    $this->SetTimerInterval('IrrigationTimer', 0);
                }
                break;
        }
    }

    private function SwitchActuators(bool $state)
    {
        foreach (['Valve1', 'Valve2', 'Pump'] as $ident) {
            $id = $this->ReadPropertyInteger($ident);
            if ($id > 0) {
                @RequestAction($id, $state);
            }
        }
    }

    public function EvaluateAutomatic()
    {
        $threshold = $this->GetValue('MoistureThreshold');
        $id1 = $this->ReadPropertyInteger('MoistureSensor1');
        $id2 = $this->ReadPropertyInteger('MoistureSensor2');
        $moisture1 = ($id1 > 0) ? @GetValue($id1) : null;
        $moisture2 = ($id2 > 0) ? @GetValue($id2) : null;

        $underThreshold = false;
        if (!is_null($moisture1) && $moisture1 < $threshold) {
            $underThreshold = true;
        }
        if (!is_null($moisture2) && $moisture2 < $threshold) {
            $underThreshold = true;
        }

        if ($underThreshold) {
            $this->RequestAction('Irrigation', true);
        }
    }

    private function CreateWeekplan(string $name, bool $linkToVariable)
    {
        $parent = $linkToVariable ? $this->GetIDForIdent('Irrigation') : $this->InstanceID;
        $id = @IPS_GetEventIDByName($name, $parent);
        if ($id === false) {
            $id = IPS_CreateEvent(2); // Wochenplan
            IPS_SetName($id, $name);
            IPS_SetParent($id, $parent);
            IPS_SetEventActive($id, true);
            IPS_SetHidden($id, true);

            IPS_SetEventScheduleAction($id, 0, 'Aus', 0xFF0000, false);
            IPS_SetEventScheduleAction($id, 1, 'Ein', 0x00FF00, true);

            for ($i = 0; $i <= 6; $i++) {
                $bitmask = 1 << $i;
                IPS_SetEventScheduleGroup($id, $i, $bitmask);
            }

            if ($linkToVariable) {
                foreach ([1, 3] as $grp) {
                    IPS_SetEventScheduleGroupPoint($id, $grp, 0, 0, 0, 0, 0);
                    IPS_SetEventScheduleGroupPoint($id, $grp, 1, 3, 0, 0, 1);
                    IPS_SetEventScheduleGroupPoint($id, $grp, 2, 3, 30, 0, 0);
                }
                foreach ([0, 2, 4, 5, 6] as $grp) {
                    IPS_SetEventScheduleGroupPoint($id, $grp, 0, 0, 0, 0, 0);
                }
            } else {
                // Automatik: ruft EvaluateAutomatic() bei EIN-Zeitpunkt auf
                IPS_SetEventCyclic($id, 0, 0, 0, 0, 0);
                IPS_SetEventCyclicDateFrom($id, 0, 0, 0);
                IPS_SetEventCyclicDateTo($id, 0, 0, 0);
                IPS_SetEventCyclicTimeFrom($id, 0, 0, 0);
                IPS_SetEventCyclicTimeTo($id, 23, 59, 59);
                IPS_SetEventScript($id, "IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleName'];\nIPS_RunScriptText('<?php IRR_EvaluateAutomatic(' . $this->InstanceID . '); ?>');");

                // Auch hier EIN-Zeiten Dienstag und Donnerstag
                foreach ([1, 3] as $grp) {
                    IPS_SetEventScheduleGroupPoint($id, $grp, 0, 3, 0, 0, 1);
                }
            }
        }
    }
}
