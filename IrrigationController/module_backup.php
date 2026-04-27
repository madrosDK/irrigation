<?php

declare(strict_types=1);

class IrrigationController extends IPSModule
{
    private const MODE_MANUAL = 1;
    private const MODE_TIME = 2;
    private const MODE_AUTO = 3;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Mode', self::MODE_MANUAL);
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

        $this->RegisterTimer('StopIrrigationTimer', 0, 'IRR_StopIrrigation($_IPS[\'TARGET\']);');
        $this->RegisterTimer('RefreshTimer', 60000, 'IRR_RefreshValues($_IPS[\'TARGET\']);');

        $this->SetBuffer('RegisteredMessages', json_encode([]));
        $this->Debug('Create', 'Modul initialisiert');
    }

    public function Destroy()
    {
        $this->Debug('Destroy', 'Modul wird zerstört');
        $this->UnregisterSourceMessages();
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->Debug('ApplyChanges', 'gestartet');
        $this->Debug('KernelRunlevel', IPS_GetKernelRunlevel());

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->Debug('ApplyChanges', 'abgebrochen: Kernel nicht ready');
            return;
        }

        $mode = $this->ReadPropertyInteger('Mode');
        $this->Debug('Properties', [
            'Mode' => $mode,
            'Duration' => $this->ReadPropertyInteger('Duration'),
            'MoistureThreshold' => $this->ReadPropertyInteger('MoistureThreshold'),
            'RainThreshold24h' => $this->ReadPropertyInteger('RainThreshold24h'),
            'UseAverageMoisture' => $this->ReadPropertyBoolean('UseAverageMoisture'),
            'StartPumpFirst' => $this->ReadPropertyBoolean('StartPumpFirst'),
            'PumpLeadTimeSeconds' => $this->ReadPropertyInteger('PumpLeadTimeSeconds'),
            'MoistureSensor1' => $this->ReadPropertyInteger('MoistureSensor1'),
            'MoistureSensor2' => $this->ReadPropertyInteger('MoistureSensor2'),
            'RainLast24h' => $this->ReadPropertyInteger('RainLast24h'),
            'Valve1' => $this->ReadPropertyInteger('Valve1'),
            'Valve2' => $this->ReadPropertyInteger('Valve2'),
            'Pump' => $this->ReadPropertyInteger('Pump')
        ]);

        if (!in_array($mode, [self::MODE_MANUAL, self::MODE_TIME, self::MODE_AUTO], true)) {
            $this->Debug('ApplyChanges', 'ungültiger Mode, setze auf MANUAL');
            IPS_SetProperty($this->InstanceID, 'Mode', self::MODE_MANUAL);
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $this->SetValue('Mode', $mode);
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
        $this->Debug('ApplyChanges', 'abgeschlossen');
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value)
    {
        $this->Debug('RequestAction', ['Ident' => $Ident, 'Value' => $Value]);

        switch ($Ident) {
            case 'Mode':
                $value = (int) $Value;
                if (!in_array($value, [self::MODE_MANUAL, self::MODE_TIME, self::MODE_AUTO], true)) {
                    $value = self::MODE_MANUAL;
                }
                IPS_SetProperty($this->InstanceID, 'Mode', $value);
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

        $this->Debug('MessageSink', [
            'TimeStamp' => $TimeStamp,
            'SenderID' => $SenderID,
            'Message' => $Message,
            'Data' => $Data
        ]);

        if ($Message === VM_UPDATE) {
            $this->Debug('MessageSink', 'VM_UPDATE erkannt -> RefreshValues');
            $this->RefreshValues();
        }
    }

    public function RefreshValues()
    {
        $this->Debug('RefreshValues', 'gestartet');

        $this->SetValue('MoistureSensor1Value', $this->FormatSelectedVariableValue('MoistureSensor1'));
        $this->SetValue('MoistureSensor2Value', $this->FormatSelectedVariableValue('MoistureSensor2'));
        $this->SetValue('RainLast24hValue', $this->FormatSelectedVariableValue('RainLast24h'));

        $this->Debug('RefreshValues.Sensor1', $this->GetValue('MoistureSensor1Value'));
        $this->Debug('RefreshValues.Sensor2', $this->GetValue('MoistureSensor2Value'));
        $this->Debug('RefreshValues.Rain24h', $this->GetValue('RainLast24hValue'));

        $moisture = $this->GetEffectiveMoisture();
        $this->Debug('RefreshValues.ComputedMoisture', $moisture);
        $this->SetValue('ComputedMoisture', $moisture ?? 0.0);

        if ($moisture === null) {
            $this->Debug('RefreshValues', 'keine gültigen Feuchtesensoren');
            $this->SetValue('DecisionText', 'Keine gültigen Feuchtesensoren konfiguriert');
        }

        $this->UpdateStatus();
        $this->Debug('RefreshValues', 'abgeschlossen');
    }

    public function EvaluateAutomatic()
    {
        $this->Debug('EvaluateAutomatic', 'gestartet');
        $this->RefreshValues();

        $this->Debug('EvaluateAutomatic.Mode', $this->GetValue('Mode'));

        if ($this->GetValue('Mode') !== self::MODE_AUTO) {
            $this->SetValue('DecisionText', 'Automatikprüfung übersprungen: Betriebsmodus ist nicht Automatik');
            $this->WriteLog('Automatikprüfung übersprungen: falscher Modus');
            return;
        }

        $rainValue = $this->ReadNumericPropertyVariable('RainLast24h');
        $rainThreshold = $this->ReadPropertyInteger('RainThreshold24h');
        $this->Debug('EvaluateAutomatic.RainValue', $rainValue);
        $this->Debug('EvaluateAutomatic.RainThreshold', $rainThreshold);

        if ($rainThreshold > 0 && $rainValue !== null && $rainValue >= $rainThreshold) {
            $msg = 'Automatik blockiert: Regensperre aktiv (' . $this->FormatNumber($rainValue) . ' mm / 24 h)';
            $this->SetValue('DecisionText', $msg);
            $this->WriteLog($msg);
            return;
        }

        $effectiveMoisture = $this->GetEffectiveMoisture();
        $this->Debug('EvaluateAutomatic.EffectiveMoisture', $effectiveMoisture);

        if ($effectiveMoisture === null) {
            $msg = 'Automatik nicht möglich: Kein gültiger Feuchtewert vorhanden';
            $this->SetValue('DecisionText', $msg);
            $this->WriteLog($msg);
            return;
        }

        $threshold = $this->ReadPropertyInteger('MoistureThreshold');
        $this->Debug('EvaluateAutomatic.MoistureThreshold', $threshold);

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
        $this->Debug('StartIrrigation', 'gestartet');

        $durationMinutes = max(1, $this->ReadPropertyInteger('Duration'));
        $this->Debug('StartIrrigation.DurationMinutes', $durationMinutes);
        $this->Debug('StartIrrigation.StartPumpFirst', $this->ReadPropertyBoolean('StartPumpFirst'));
        $this->Debug('StartIrrigation.PumpLeadTimeSeconds', $this->ReadPropertyInteger('PumpLeadTimeSeconds'));

        if ($this->ReadPropertyBoolean('StartPumpFirst')) {
            $this->SetActuatorState($this->ReadPropertyInteger('Pump'), true);
            IPS_Sleep(max(0, $this->ReadPropertyInteger('PumpLeadTimeSeconds')) * 1000);
        }

        $zone1 = $this->ReadPropertyInteger('Valve1');
        $zone2 = $this->ReadPropertyInteger('Valve2');
        $this->Debug('StartIrrigation.Zone1', $zone1);
        $this->Debug('StartIrrigation.Zone2', $zone2);
        $this->Debug('StartIrrigation.Pump', $this->ReadPropertyInteger('Pump'));

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
        $this->Debug('StartIrrigation.StopTimerMs', $durationMinutes * 60 * 1000);

        $msg = 'Beregnung gestartet für ' . $durationMinutes . ' Minute(n)';
        $this->SetValue('DecisionText', $msg);
        $this->WriteLog($msg);
        $this->Debug('StartIrrigation', 'abgeschlossen');
    }

    public function StopIrrigation()
    {
        $this->Debug('StopIrrigation', 'gestartet');
        $this->SetTimerInterval('StopIrrigationTimer', 0);
        $this->Debug('StopIrrigation.Timer', 0);

        $this->Debug('StopIrrigation.Valve1', $this->ReadPropertyInteger('Valve1'));
        $this->Debug('StopIrrigation.Valve2', $this->ReadPropertyInteger('Valve2'));
        $this->Debug('StopIrrigation.Pump', $this->ReadPropertyInteger('Pump'));

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
        $this->Debug('StopIrrigation', 'abgeschlossen');
    }

    private function RegisterProfiles()
    {
        if (!IPS_VariableProfileExists('IRR.Mode')) {
            IPS_CreateVariableProfile('IRR.Mode', VARIABLETYPE_INTEGER);
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
        $this->Debug('MaintainWeekplan', ['Ident' => $Ident, 'Name' => $Name]);

        $eventID = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
        if ($eventID === false) {
            $this->Debug('MaintainWeekplan', 'Wochenplan existiert noch nicht, wird erstellt');
            $eventID = IPS_CreateEvent(2);
            IPS_SetParent($eventID, $this->InstanceID);
            IPS_SetIdent($eventID, $Ident);
            IPS_SetName($eventID, $Name);

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
        $this->Debug('UpdateWeekplanVisibility.Mode', $mode);

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
        $this->Debug('RegisterSourceMessages', 'gestartet');
        $this->UnregisterSourceMessages();

        $ids = [];
        foreach (['MoistureSensor1', 'MoistureSensor2', 'RainLast24h'] as $property) {
            $id = $this->ReadPropertyInteger($property);
            if ($id > 0 && @IPS_VariableExists($id)) {
                $this->Debug('RegisterSourceMessages.Register', ['Property' => $property, 'ID' => $id]);
                $this->RegisterMessage($id, VM_UPDATE);
                $ids[] = $id;
            } else {
                $this->Debug('RegisterSourceMessages.Skip', ['Property' => $property, 'ID' => $id]);
            }
        }

        $this->SetBuffer('RegisteredMessages', json_encode($ids));
        $this->Debug('RegisterSourceMessages.Done', $ids);
    }

    private function UnregisterSourceMessages()
    {
        $ids = json_decode($this->GetBuffer('RegisteredMessages'), true);
        $this->Debug('UnregisterSourceMessages', $ids);

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
        $this->Debug('UpdateStatus.Input', [
            'Valve1' => $this->ReadPropertyInteger('Valve1'),
            'Valve2' => $this->ReadPropertyInteger('Valve2'),
            'Pump' => $this->ReadPropertyInteger('Pump')
        ]);

        if (
            $this->ReadPropertyInteger('Valve1') <= 0 &&
            $this->ReadPropertyInteger('Valve2') <= 0 &&
            $this->ReadPropertyInteger('Pump') <= 0
        ) {
            $this->Debug('UpdateStatus', 'SetStatus 200');
            $this->SetStatus(200);
            return;
        }

        $this->Debug('UpdateStatus', 'SetStatus 102');
        $this->SetStatus(102);
    }

    private function GetEffectiveMoisture(): ?float
    {
        $this->Debug('GetEffectiveMoisture', 'gestartet');

        $values = [];
        $sensor1 = $this->ReadNumericPropertyVariable('MoistureSensor1');
        $sensor2 = $this->ReadNumericPropertyVariable('MoistureSensor2');

        $this->Debug('GetEffectiveMoisture.Sensor1', $sensor1);
        $this->Debug('GetEffectiveMoisture.Sensor2', $sensor2);

        if ($sensor1 !== null) {
            $values[] = $sensor1;
        }

        if ($sensor2 !== null) {
            $values[] = $sensor2;
        }

        if (count($values) === 0) {
            $this->Debug('GetEffectiveMoisture.Result', 'null');
            return null;
        }

        if (count($values) === 1) {
            $this->Debug('GetEffectiveMoisture.Result', $values[0]);
            return $values[0];
        }

        if ($this->ReadPropertyBoolean('UseAverageMoisture')) {
            $result = array_sum($values) / count($values);
            $this->Debug('GetEffectiveMoisture.UseAverage', true);
            $this->Debug('GetEffectiveMoisture.Result', $result);
            return $result;
        }

        $result = min($values);
        $this->Debug('GetEffectiveMoisture.UseAverage', false);
        $this->Debug('GetEffectiveMoisture.Result', $result);
        return $result;
    }

    private function ReadNumericPropertyVariable(string $propertyName): ?float
    {
        $this->Debug('ReadNumericPropertyVariable.Property', $propertyName);

        $variableID = $this->ReadPropertyInteger($propertyName);
        $this->Debug('ReadNumericPropertyVariable.ID', $variableID);

        if ($variableID <= 0 || !@IPS_VariableExists($variableID)) {
            $this->Debug('ReadNumericPropertyVariable', 'Variable fehlt oder ist ungültig');
            return null;
        }

        $value = @GetValue($variableID);
        $this->Debug('ReadNumericPropertyVariable.Value', $value);

        if (!is_numeric($value)) {
            $this->Debug('ReadNumericPropertyVariable', 'Wert ist nicht numerisch');
            return null;
        }

        return (float) $value;
    }

    private function FormatSelectedVariableValue(string $propertyName): string
    {
        $this->Debug('FormatSelectedVariableValue.Property', $propertyName);

        $variableID = $this->ReadPropertyInteger($propertyName);
        $this->Debug('FormatSelectedVariableValue.ID', $variableID);

        if ($variableID <= 0 || !@IPS_VariableExists($variableID)) {
            $this->Debug('FormatSelectedVariableValue', 'nicht konfiguriert');
            return 'nicht konfiguriert';
        }

        $name = IPS_GetName($variableID);
        $formatted = @GetValueFormatted($variableID);
        if ($formatted === false || $formatted == '') {
            $formatted = (string) @GetValue($variableID);
        }

        $result = $name . ': ' . $formatted;
        $this->Debug('FormatSelectedVariableValue.Result', $result);
        return $result;
    }

    private function SetActuatorState(int $targetID, bool $state): void
    {
        $this->Debug('SetActuatorState', ['TargetID' => $targetID, 'State' => $state]);

        if ($targetID <= 0 || !@IPS_ObjectExists($targetID)) {
            $this->Debug('SetActuatorState', 'Zielobjekt fehlt oder ist 0');
            return;
        }

        $object = IPS_GetObject($targetID);
        $this->Debug('SetActuatorState.Object', [
            'ObjectID' => $targetID,
            'ObjectName' => $object['ObjectName'],
            'ObjectType' => $object['ObjectType'],
            'ObjectIdent' => $object['ObjectIdent']
        ]);

        // RequestAction() muss auf die schaltbare Variable gehen, nicht auf die Instanz.
        // Das ist besonders für xComfort-Aktoren wichtig.
        if ($object['ObjectType'] === OBJECTTYPE_VARIABLE) {
            $this->SwitchVariable($targetID, $state);
            return;
        }

        if ($object['ObjectType'] !== OBJECTTYPE_INSTANCE) {
            $this->Debug('SetActuatorState', 'Ziel ist weder Variable noch Instanz');
            return;
        }

        $switchVariableID = $this->FindSwitchVariable($targetID);
        if ($switchVariableID <= 0) {
            $this->Debug('SetActuatorState', 'keine schaltbare Kindvariable gefunden');
            return;
        }

        $this->Debug('SetActuatorState.SwitchVariable', [
            'InstanceID' => $targetID,
            'VariableID' => $switchVariableID,
            'VariableName' => IPS_GetName($switchVariableID)
        ]);

        $this->SwitchVariable($switchVariableID, $state);
    }

    private function FindSwitchVariable(int $instanceID): int
    {
        $children = $this->GetChildVariablesRecursive($instanceID, 3);
        $this->Debug('FindSwitchVariable.ChildrenRecursive', $children);

        $bestID = 0;
        $bestScore = -9999;
        $bestReason = '';

        foreach ($children as $childID) {
            if (!@IPS_VariableExists($childID)) {
                continue;
            }

            $variable = IPS_GetVariable($childID);
            $childObject = IPS_GetObject($childID);

            $this->Debug('FindSwitchVariable.Child', [
                'ID' => $childID,
                'Name' => $childObject['ObjectName'],
                'Ident' => $childObject['ObjectIdent'],
                'Type' => $variable['VariableType'],
                'Action' => $variable['VariableAction'],
                'Profile' => $variable['VariableProfile'],
                'CustomProfile' => $variable['VariableCustomProfile']
            ]);

            if ($variable['VariableType'] !== VARIABLETYPE_BOOLEAN) {
                continue;
            }

            $haystack = strtolower($childObject['ObjectName'] . ' ' . $childObject['ObjectIdent'] . ' ' . $variable['VariableProfile'] . ' ' . $variable['VariableCustomProfile']);
            $score = 0;
            $reasons = [];

            if ($variable['VariableAction'] > 0) {
                $score += 100;
                $reasons[] = 'has action';
            }

            $positiveTerms = [
                'state' => 50,
                'status' => 45,
                'switch' => 45,
                'schalter' => 45,
                'relay' => 45,
                'relais' => 45,
                'output' => 45,
                'ausgang' => 45,
                'power' => 35,
                'onoff' => 35,
                'on/off' => 35,
                'ein/aus' => 35,
                'active' => 20,
                'aktiv' => 20
            ];

            foreach ($positiveTerms as $term => $points) {
                if (strpos($haystack, $term) !== false) {
                    $score += $points;
                    $reasons[] = '+' . $term;
                }
            }

            $negativeTerms = [
                'online' => 120,
                'connected' => 120,
                'reachable' => 120,
                'available' => 100,
                'update' => 100,
                'firmware' => 100,
                'cloud' => 80,
                'overtemperature' => 80,
                'overpower' => 80,
                'error' => 70,
                'fehler' => 70,
                'alarm' => 70,
                'battery' => 70,
                'batterie' => 70,
                'motion' => 50,
                'bewegung' => 50,
                'input' => 40,
                'eingang' => 40
            ];

            foreach ($negativeTerms as $term => $points) {
                if (strpos($haystack, $term) !== false) {
                    $score -= $points;
                    $reasons[] = '-' . $term;
                }
            }

            $ident = $childObject['ObjectIdent'];
            if (in_array($ident, ['STATE', 'State', 'state', 'STATUS', 'Status', 'status', 'Switch', 'SWITCH', 'Relay', 'RELAY', 'Output', 'OUTPUT', 'Power', 'POWER', 'OnOff'], true)) {
                $score += 80;
                $reasons[] = 'exact preferred ident';
            }

            $this->Debug('FindSwitchVariable.Score', [
                'ID' => $childID,
                'Score' => $score,
                'Reasons' => $reasons
            ]);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestID = $childID;
                $bestReason = implode(', ', $reasons);
            }
        }

        if ($bestID > 0 && $bestScore > 0) {
            $this->Debug('FindSwitchVariable.Result', [
                'ID' => $bestID,
                'Score' => $bestScore,
                'Reason' => $bestReason
            ]);
            return $bestID;
        }

        $this->Debug('FindSwitchVariable.Result', 'keine passende schaltbare Bool-Variable gefunden');
        return 0;
    }

    private function GetChildVariablesRecursive(int $parentID, int $maxDepth, int $currentDepth = 0): array
    {
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        $result = [];
        $children = IPS_GetChildrenIDs($parentID);

        foreach ($children as $childID) {
            if (@IPS_VariableExists($childID)) {
                $result[] = $childID;
                continue;
            }

            if (@IPS_ObjectExists($childID)) {
                $result = array_merge($result, $this->GetChildVariablesRecursive($childID, $maxDepth, $currentDepth + 1));
            }
        }

        return $result;
    }

    private function SwitchVariable(int $variableID, bool $state): void
    {
        if ($variableID <= 0 || !@IPS_VariableExists($variableID)) {
            $this->Debug('SwitchVariable', 'Variable fehlt oder ist ungültig');
            return;
        }

        $variable = IPS_GetVariable($variableID);
        $object = IPS_GetObject($variableID);

        $this->Debug('SwitchVariable', [
            'VariableID' => $variableID,
            'Name' => $object['ObjectName'],
            'Ident' => $object['ObjectIdent'],
            'Type' => $variable['VariableType'],
            'Action' => $variable['VariableAction'],
            'State' => $state
        ]);

        if ($variable['VariableType'] !== VARIABLETYPE_BOOLEAN) {
            $this->Debug('SwitchVariable', 'Variable ist nicht boolesch');
            return;
        }

        // Wenn eine Aktion hinterlegt ist, wird damit der echte Aktor geschaltet.
        if ($variable['VariableAction'] > 0) {
            try {
                RequestAction($variableID, $state);
                $this->Debug('SwitchVariable', 'RequestAction erfolgreich');
                return;
            } catch (Throwable $e) {
                $this->Debug('SwitchVariable.RequestActionException', $e->getMessage());
            }
        } else {
            $this->Debug('SwitchVariable', 'keine VariableAction vorhanden, RequestAction wird trotzdem versucht');
            try {
                RequestAction($variableID, $state);
                $this->Debug('SwitchVariable', 'RequestAction ohne VariableAction erfolgreich');
                return;
            } catch (Throwable $e) {
                $this->Debug('SwitchVariable.RequestActionNoActionException', $e->getMessage());
            }
        }

        // Fallback nur für Dummy-/Statusvariablen. Das schaltet keinen echten Aktor.
        @SetValue($variableID, $state);
        $this->Debug('SwitchVariable', 'Fallback SetValue ausgeführt - Achtung: schaltet keinen echten Aktor, falls keine Aktion hinterlegt ist');
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

    private function Debug(string $Message, $Data = null): void
    {
        if ($Data === null) {
            $this->SendDebug('IRR', $Message, 0);
            return;
        }

        if (is_array($Data) || is_object($Data)) {
            $json = json_encode($Data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $json = 'json_encode failed';
            }
            $this->SendDebug($Message, $json, 0);
            return;
        }

        if (is_bool($Data)) {
            $this->SendDebug($Message, $Data ? 'true' : 'false', 0);
            return;
        }

        $this->SendDebug($Message, (string) $Data, 0);
    }
}
