<?php

declare(strict_types=1);

class IrrigationController extends IPSModule
{
    private const MODE_OFF = 0;
    private const MODE_MANUAL = 1;
    private const MODE_TIME = 2;
    private const MODE_AUTO = 3;

    public function Create()
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger('Mode', self::MODE_OFF);
        $this->RegisterPropertyInteger('Duration', 10);
        $this->RegisterPropertyInteger('MoistureThreshold', 35);
        $this->RegisterPropertyInteger('RainThreshold24h', 5);
        $this->RegisterPropertyBoolean('UseAverageMoisture', false);
        $this->RegisterPropertyBoolean('StartPumpFirst', true);
        $this->RegisterPropertyInteger('PumpLeadTimeSeconds', 3);

        $this->RegisterPropertyInteger('MoistureSensor1', 0);
        $this->RegisterPropertyInteger('MoistureSensor2', 0);
        $this->RegisterPropertyInteger('RainLast24h', 0);

        $this->RegisterPropertyInteger('Valve1', 0);
        $this->RegisterPropertyInteger('Valve2', 0);
        $this->RegisterPropertyInteger('Pump', 0);

        $this->RegisterProfiles();

        // Variables visible in frontend / object tree
        $this->RegisterVariableInteger('Mode', 'Betriebsmodus', 'IRR.Mode', 10);
        $this->EnableAction('Mode');

        $this->RegisterVariableInteger('DurationMinutes', 'Beregnungsdauer', 'IRR.Minutes', 20);
        $this->EnableAction('DurationMinutes');

        $this->RegisterVariableInteger('MoistureThresholdValue', 'Feuchteschwelle', 'IRR.Percent', 30);
        $this->EnableAction('MoistureThresholdValue');

        $this->RegisterVariableInteger('RainThresholdValue', 'Regensperre', 'IRR.RainMM', 40);
        $this->EnableAction('RainThresholdValue');

        $this->RegisterVariableBoolean('Irrigation', 'Beregnung aktiv', '~Switch', 50);
        $this->EnableAction('Irrigation');

        $this->RegisterVariableBoolean('PumpActive', 'Pumpe aktiv', '~Switch', 60);
        $this->RegisterVariableBoolean('Zone1Active', 'Zone 1 aktiv', '~Switch', 70);
        $this->RegisterVariableBoolean('Zone2Active', 'Zone 2 aktiv', '~Switch', 80);

        $this->RegisterVariableString('MoistureSensor1Value', 'Sensor 1 Wert', '', 100);
        $this->RegisterVariableString('MoistureSensor2Value', 'Sensor 2 Wert', '', 110);
        $this->RegisterVariableString('RainLast24hValue', 'Regen letzte 24 h', '', 120);

        $this->RegisterVariableFloat('ComputedMoisture', 'Berechnete Feuchte', 'IRR.PercentFloat', 130);
        $this->RegisterVariableString('DecisionText', 'Automatikentscheidung', '', 140);
        $this->RegisterVariableString('LastAction', 'Letzte Aktion', '', 150);
        $this->RegisterVariableString('ConfigOverview', 'Übersicht', '~HTMLBox', 160);

        $this->RegisterTimer('StopIrrigationTimer', 0, 'IRR_StopIrrigation($_IPS[\'TARGET\']);');
        $this->RegisterTimer('RefreshTimer', 60000, 'IRR_RefreshValues($_IPS[\'TARGET\']);');

        $this->SetBuffer('RegisteredMessages', json_encode([]));
    }

    public function Destroy()
    {
        $this->UnregisterSourceMessages();
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->SetValue('Mode', $this->ReadPropertyInteger('Mode'));
        $this->SetValue('DurationMinutes', $this->ReadPropertyInteger('Duration'));
        $this->SetValue('MoistureThresholdValue', $this->ReadPropertyInteger('MoistureThreshold'));
        $this->SetValue('RainThresholdValue', $this->ReadPropertyInteger('RainThreshold24h'));

        if (!@$this->GetValue('Irrigation')) {
            $this->SetValue('Irrigation', false);
        }
        $this->SetValue('PumpActive', false);
        $this->SetValue('Zone1Active', false);
        $this->SetValue('Zone2Active', false);

        $this->MaintainWeekplan('ScheduleTimer', 'Zeitsteuerung');
        $this->MaintainWeekplan('ScheduleAuto', 'Automatik');
        $this->UpdateWeekplanVisibility();

        $this->RegisterSourceMessages();
        $this->RefreshValues();
        $this->UpdateStatus();
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Mode':
                IPS_SetProperty($this->InstanceID, 'Mode', (int) $Value);
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'DurationMinutes':
                IPS_SetProperty($this->InstanceID, 'Duration', max(1, (int) $Value));
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'MoistureThresholdValue':
                IPS_SetProperty($this->InstanceID, 'MoistureThreshold', max(0, min(100, (int) $Value)));
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'RainThresholdValue':
                IPS_SetProperty($this->InstanceID, 'RainThreshold24h', max(0, (int) $Value));
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'Irrigation':
                if ((bool) $Value) {
                    $this->StartIrrigation();
                } else {
                    $this->StopIrrigation();
                }
                break;

            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message === VM_UPDATE) {
            $this->RefreshValues();
        }
    }

    public function RefreshValues()
    {
        $this->SetValue('MoistureSensor1Value', $this->FormatSelectedVariableValue('MoistureSensor1'));
        $this->SetValue('MoistureSensor2Value', $this->FormatSelectedVariableValue('MoistureSensor2'));
        $this->SetValue('RainLast24hValue', $this->FormatSelectedVariableValue('RainLast24h'));

        $moisture = $this->GetEffectiveMoisture();
        $this->SetValue('ComputedMoisture', $moisture ?? 0.0);

        if ($moisture === null) {
            $this->SetValue('DecisionText', 'Keine gültigen Feuchtesensoren konfiguriert');
        }

        $this->UpdateOverview();
        $this->UpdateStatus();
    }

    public function EvaluateAutomatic()
    {
        $this->RefreshValues();

        $mode = $this->GetValue('Mode');
        if ($mode !== self::MODE_AUTO) {
            $this->SetValue('DecisionText', 'Automatikprüfung übersprungen: Betriebsmodus ist nicht Automatik');
            $this->WriteLog('Automatikprüfung übersprungen: falscher Modus');
            return;
        }

        $rainValue = $this->ReadNumericPropertyVariable('RainLast24h');
        $rainThreshold = $this->ReadPropertyInteger('RainThreshold24h');
        if ($rainThreshold > 0 && $rainValue !== null && $rainValue >= $rainThreshold) {
            $msg = 'Automatik blockiert: Regensperre aktiv (' . $this->FormatNumber($rainValue) . ' mm / 24 h)';
            $this->SetValue('DecisionText', $msg);
            $this->WriteLog($msg);
            return;
        }

        $effectiveMoisture = $this->GetEffectiveMoisture();
        if ($effectiveMoisture === null) {
            $msg = 'Automatik nicht möglich: Kein gültiger Feuchtewert vorhanden';
            $this->SetValue('DecisionText', $msg);
            $this->WriteLog($msg);
            return;
        }

        $threshold = $this->ReadPropertyInteger('MoistureThreshold');
        if ($effectiveMoisture < $threshold) {
            $msg = 'Automatik startet Beregnung: Feuchte ' . $this->FormatNumber($effectiveMoisture) . ' % < ' . $threshold . ' %';
            $this->SetValue('DecisionText', $msg);
            $this->WriteLog($msg);
            $this->StartIrrigation();
            return;
        }

        $msg = 'Automatik startet nicht: Feuchte ' . $this->FormatNumber($effectiveMoisture) . ' % >= ' . $threshold . ' %';
        $this->SetValue('DecisionText', $msg);
        $this->WriteLog($msg);
    }

    public function StartIrrigation()
    {
        $durationMinutes = max(1, $this->ReadPropertyInteger('Duration'));

        if ($this->ReadPropertyBoolean('StartPumpFirst')) {
            $this->SetActuatorState($this->ReadPropertyInteger('Pump'), true);
            IPS_Sleep(max(0, $this->ReadPropertyInteger('PumpLeadTimeSeconds')) * 1000);
        }

        $zone1 = $this->ReadPropertyInteger('Valve1');
        $zone2 = $this->ReadPropertyInteger('Valve2');

        $this->SetActuatorState($zone1, true);
        $this->SetActuatorState($zone2, true);

        if (!$this->ReadPropertyBoolean('StartPumpFirst')) {
            $this->SetActuatorState($this->ReadPropertyInteger('Pump'), true);
        }

        $this->SetValue('Irrigation', true);
        $this->SetValue('PumpActive', $this->ReadPropertyInteger('Pump') > 0);
        $this->SetValue('Zone1Active', $zone1 > 0);
        $this->SetValue('Zone2Active', $zone2 > 0);

        $this->SetTimerInterval('StopIrrigationTimer', $durationMinutes * 60 * 1000);

        $msg = 'Beregnung gestartet für ' . $durationMinutes . ' Minute(n)';
        $this->SetValue('DecisionText', $msg);
        $this->WriteLog($msg);
        $this->UpdateOverview();
    }

    public function StopIrrigation()
    {
        $this->SetTimerInterval('StopIrrigationTimer', 0);

        $this->SetActuatorState($this->ReadPropertyInteger('Valve1'), false);
        $this->SetActuatorState($this->ReadPropertyInteger('Valve2'), false);
        $this->SetActuatorState($this->ReadPropertyInteger('Pump'), false);

        $this->SetValue('Irrigation', false);
        $this->SetValue('PumpActive', false);
        $this->SetValue('Zone1Active', false);
        $this->SetValue('Zone2Active', false);

        $msg = 'Beregnung gestoppt';
        $this->SetValue('DecisionText', $msg);
        $this->WriteLog($msg);
        $this->UpdateOverview();
    }

    private function RegisterProfiles()
    {
        if (!IPS_VariableProfileExists('IRR.Mode')) {
            IPS_CreateVariableProfile('IRR.Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('IRR.Mode', self::MODE_OFF, 'Aus', '', 0x808080);
            IPS_SetVariableProfileAssociation('IRR.Mode', self::MODE_MANUAL, 'Manuell', '', 0x2D8CFF);
            IPS_SetVariableProfileAssociation('IRR.Mode', self::MODE_TIME, 'Zeitsteuerung', '', 0xFFB300);
            IPS_SetVariableProfileAssociation('IRR.Mode', self::MODE_AUTO, 'Automatik', '', 0x27AE60);
        }

        if (!IPS_VariableProfileExists('IRR.Minutes')) {
            IPS_CreateVariableProfile('IRR.Minutes', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.Minutes', 1, 720, 1);
            IPS_SetVariableProfileText('IRR.Minutes', '', ' min');
        }

        if (!IPS_VariableProfileExists('IRR.Percent')) {
            IPS_CreateVariableProfile('IRR.Percent', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.Percent', 0, 100, 1);
            IPS_SetVariableProfileText('IRR.Percent', '', ' %');
        }

        if (!IPS_VariableProfileExists('IRR.PercentFloat')) {
            IPS_CreateVariableProfile('IRR.PercentFloat', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('IRR.PercentFloat', 1);
            IPS_SetVariableProfileText('IRR.PercentFloat', '', ' %');
        }

        if (!IPS_VariableProfileExists('IRR.RainMM')) {
            IPS_CreateVariableProfile('IRR.RainMM', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.RainMM', 0, 500, 1);
            IPS_SetVariableProfileText('IRR.RainMM', '', ' mm');
        }
    }

    private function MaintainWeekplan(string $Ident, string $Name)
    {
        $eventID = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
        if ($eventID === false) {
            $eventID = IPS_CreateEvent(2);
            IPS_SetParent($eventID, $this->InstanceID);
            IPS_SetIdent($eventID, $Ident);
            IPS_SetName($eventID, $Name);

            // Leerer Wochenplan, der in IP-Symcon gepflegt werden kann
            IPS_SetHidden($eventID, false);
            IPS_SetEventActive($eventID, false);

            IPS_SetEventScheduleAction($eventID, 0, 'Aus', 0x808080, false);
            IPS_SetEventScheduleAction($eventID, 1, 'Ein', 0x27AE60, true);

            for ($day = 0; $day <= 6; $day++) {
                @IPS_SetEventScheduleGroup($eventID, $day, 1 << $day);
            }
        }
    }

    private function UpdateWeekplanVisibility()
    {
        $mode = $this->ReadPropertyInteger('Mode');

        $timerEventID = @IPS_GetObjectIDByIdent('ScheduleTimer', $this->InstanceID);
        $autoEventID = @IPS_GetObjectIDByIdent('ScheduleAuto', $this->InstanceID);

        if ($timerEventID !== false) {
            IPS_SetHidden($timerEventID, $mode !== self::MODE_TIME);
            IPS_SetEventActive($timerEventID, $mode === self::MODE_TIME);
        }

        if ($autoEventID !== false) {
            IPS_SetHidden($autoEventID, $mode !== self::MODE_AUTO);
            IPS_SetEventActive($autoEventID, $mode === self::MODE_AUTO);
        }
    }

    private function RegisterSourceMessages()
    {
        $this->UnregisterSourceMessages();

        $ids = [];
        foreach (['MoistureSensor1', 'MoistureSensor2', 'RainLast24h'] as $property) {
            $id = $this->ReadPropertyInteger($property);
            if ($id > 0 && @IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
                $ids[] = $id;
            }
        }

        $this->SetBuffer('RegisteredMessages', json_encode($ids));
    }

    private function UnregisterSourceMessages()
    {
        $ids = json_decode($this->GetBuffer('RegisteredMessages'), true);
        if (!is_array($ids)) {
            return;
        }

        foreach ($ids as $id) {
            if (is_int($id) && $id > 0 && @IPS_ObjectExists($id)) {
                @$this->UnregisterMessage($id, VM_UPDATE);
            }
        }

        $this->SetBuffer('RegisteredMessages', json_encode([]));
    }

    private function UpdateStatus()
    {
        if (
            $this->ReadPropertyInteger('Valve1') <= 0 &&
            $this->ReadPropertyInteger('Valve2') <= 0 &&
            $this->ReadPropertyInteger('Pump') <= 0
        ) {
            $this->SetStatus(202);
            return;
        }

        $this->SetStatus(101);
    }

    private function GetEffectiveMoisture(): ?float
    {
        $values = [];
        $sensor1 = $this->ReadNumericPropertyVariable('MoistureSensor1');
        $sensor2 = $this->ReadNumericPropertyVariable('MoistureSensor2');

        if ($sensor1 !== null) {
            $values[] = $sensor1;
        }

        if ($sensor2 !== null) {
            $values[] = $sensor2;
        }

        if (count($values) === 0) {
            return null;
        }

        if (count($values) === 1) {
            return $values[0];
        }

        if ($this->ReadPropertyBoolean('UseAverageMoisture')) {
            return array_sum($values) / count($values);
        }

        return min($values);
    }

    private function ReadNumericPropertyVariable(string $propertyName): ?float
    {
        $variableID = $this->ReadPropertyInteger($propertyName);
        if ($variableID <= 0 || !@IPS_VariableExists($variableID)) {
            return null;
        }

        $value = @GetValue($variableID);
        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function FormatSelectedVariableValue(string $propertyName): string
    {
        $variableID = $this->ReadPropertyInteger($propertyName);
        if ($variableID <= 0 || !@IPS_VariableExists($variableID)) {
            return 'nicht konfiguriert';
        }

        $name = IPS_GetName($variableID);
        $formatted = @GetValueFormatted($variableID);
        if ($formatted === false || $formatted === '') {
            $formatted = (string) @GetValue($variableID);
        }

        return $name . ': ' . $formatted;
    }

    private function FormatObjectName(int $objectID): string
    {
        if ($objectID <= 0 || !@IPS_ObjectExists($objectID)) {
            return 'nicht konfiguriert';
        }

        return IPS_GetName($objectID) . ' (#' . $objectID . ')';
    }

    private function SetActuatorState(int $targetID, bool $state): void
    {
        if ($targetID <= 0 || !@IPS_ObjectExists($targetID)) {
            return;
        }

        try {
            @RequestAction($targetID, $state);
            return;
        } catch (Throwable $e) {
            // fallback below
        }

        $object = IPS_GetObject($targetID);
        if ($object['ObjectType'] === OBJECTTYPE_VARIABLE) {
            try {
                @RequestAction($targetID, $state);
                return;
            } catch (Throwable $e) {
                @SetValue($targetID, $state);
                return;
            }
        }

        if ($object['ObjectType'] === OBJECTTYPE_INSTANCE) {
            $children = IPS_GetChildrenIDs($targetID);
            foreach ($children as $childID) {
                if (!@IPS_VariableExists($childID)) {
                    continue;
                }

                $var = IPS_GetVariable($childID);
                if ($var['VariableType'] !== VARIABLETYPE_BOOLEAN) {
                    continue;
                }

                try {
                    @RequestAction($childID, $state);
                    return;
                } catch (Throwable $e) {
                    @SetValue($childID, $state);
                    return;
                }
            }
        }
    }

    private function WriteLog(string $message): void
    {
        $text = date('d.m.Y H:i:s') . ' - ' . $message;
        $this->SetValue('LastAction', $text);
        IPS_LogMessage('IRR[' . $this->InstanceID . ']', $message);
    }

    private function FormatNumber(float $value): string
    {
        return number_format($value, 1, ',', '');
    }

    private function UpdateOverview(): void
    {
        $rows = [
            ['Betriebsmodus', $this->GetModeText($this->GetValue('Mode'))],
            ['Beregnungsdauer', $this->GetValue('DurationMinutes') . ' min'],
            ['Feuchteschwelle', $this->GetValue('MoistureThresholdValue') . ' %'],
            ['Regensperre', $this->GetValue('RainThresholdValue') . ' mm / 24 h'],
            ['Beregnung aktiv', $this->GetValue('Irrigation') ? 'Ja' : 'Nein'],
            ['Pumpe aktiv', $this->GetValue('PumpActive') ? 'Ja' : 'Nein'],
            ['Zone 1 aktiv', $this->GetValue('Zone1Active') ? 'Ja' : 'Nein'],
            ['Zone 2 aktiv', $this->GetValue('Zone2Active') ? 'Ja' : 'Nein'],
            ['Berechnete Feuchte', $this->FormatNumber((float) $this->GetValue('ComputedMoisture')) . ' %'],
            ['Automatikentscheidung', (string) $this->GetValue('DecisionText')],
            ['Letzte Aktion', (string) $this->GetValue('LastAction')],
            ['Sensor 1', (string) $this->GetValue('MoistureSensor1Value')],
            ['Sensor 2', (string) $this->GetValue('MoistureSensor2Value')],
            ['Regen letzte 24 h', (string) $this->GetValue('RainLast24hValue')],
            ['Ventil 1', $this->FormatObjectName($this->ReadPropertyInteger('Valve1'))],
            ['Ventil 2', $this->FormatObjectName($this->ReadPropertyInteger('Valve2'))],
            ['Pumpe', $this->FormatObjectName($this->ReadPropertyInteger('Pump'))],
        ];

        $html = '<style>
            table.irr {border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 13px;}
            table.irr td {border: 1px solid #d9d9d9; padding: 6px 8px; vertical-align: top;}
            table.irr td:first-child {background: #f5f5f5; font-weight: bold; width: 34%;}
        </style>';
        $html .= '<table class="irr">';

        foreach ($rows as $row) {
            $html .= '<tr><td>' . htmlspecialchars((string) $row[0]) . '</td><td>' . htmlspecialchars((string) $row[1]) . '</td></tr>';
        }

        $html .= '</table>';

        $this->SetValue('ConfigOverview', $html);
    }

    private function GetModeText(int $mode): string
    {
        switch ($mode) {
            case self::MODE_OFF:
                return 'Aus';
            case self::MODE_MANUAL:
                return 'Manuell';
            case self::MODE_TIME:
                return 'Zeitsteuerung';
            case self::MODE_AUTO:
                return 'Automatik';
            default:
                return 'Unbekannt';
        }
    }
}
