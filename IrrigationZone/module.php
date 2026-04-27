<?php

declare(strict_types=1);

class IrrigationZone extends IPSModule
{
    private const MOISTURE_LOWEST = 0;
    private const MOISTURE_AVERAGE = 1;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Enabled', true);
        $this->RegisterPropertyInteger('ZoneNumber', 1);
        $this->RegisterPropertyInteger('Duration', 0);
        $this->RegisterPropertyInteger('MoistureThreshold', 35);
        $this->RegisterPropertyInteger('MoistureMode', self::MOISTURE_LOWEST);

        $this->RegisterPropertyInteger('MoistureSensor1', 0);
        $this->RegisterPropertyInteger('MoistureSensor2', 0);
        $this->RegisterPropertyInteger('RainLast24h', 0);
        $this->RegisterPropertyInteger('RainThreshold24h', 0);

        $this->RegisterPropertyInteger('Actuator1Instance', 0);
        $this->RegisterPropertyInteger('Actuator1Variable', 0);
        $this->RegisterPropertyInteger('Actuator2Instance', 0);
        $this->RegisterPropertyInteger('Actuator2Variable', 0);

        // Kompatibilität zu alten V3.1-Konfigurationen
        $this->RegisterPropertyInteger('Valve1', 0);
        $this->RegisterPropertyInteger('Valve2', 0);
        $this->RegisterPropertyInteger('Valve1Instance', 0);
        $this->RegisterPropertyInteger('Valve1Variable', 0);
        $this->RegisterPropertyInteger('Valve2Instance', 0);
        $this->RegisterPropertyInteger('Valve2Variable', 0);

        $this->RegisterProfiles();

        $this->RegisterVariableBoolean('Enabled', 'Kreis aktiv', '~Switch', 10);
        $this->EnableAction('Enabled');

        $this->RegisterVariableInteger('ZoneNumber', 'Kreisnummer', '', 20);

        $this->RegisterVariableInteger('DurationMinutes', 'Beregnungsdauer', 'IRR.Minutes', 30);
        $this->EnableAction('DurationMinutes');

        $this->RegisterVariableInteger('MoistureThresholdValue', 'Feuchteschwelle', 'IRR.Percent', 40);
        $this->EnableAction('MoistureThresholdValue');

        $this->RegisterVariableInteger('MoistureModeValue', 'Feuchteauswertung', 'IRRZ.MoistureMode', 45);
        $this->EnableAction('MoistureModeValue');

        $this->RegisterVariableBoolean('Actuator1Active', 'Aktor 1 aktiv', '~Switch', 50);
        $this->RegisterVariableBoolean('Actuator2Active', 'Aktor 2 aktiv', '~Switch', 60);
        $this->RegisterVariableBoolean('ZoneActive', 'Kreis aktiv bewässert', '~Switch', 70);
        $this->EnableAction('ZoneActive');

        $this->RegisterVariableString('MoistureSensor1Value', 'Sensor 1 Wert', '', 100);
        $this->RegisterVariableString('MoistureSensor2Value', 'Sensor 2 Wert', '', 110);
        $this->RegisterVariableString('RainLast24hValue', 'Regen letzte 24 h', '', 120);

        $this->RegisterVariableFloat('ComputedMoisture', 'Berechnete Feuchte', 'IRR.PercentFloat', 130);
        $this->RegisterVariableBoolean('ShouldWater', 'Automatik: Bewässern', '~Switch', 140);
        $this->RegisterVariableString('DecisionText', 'Entscheidung', '', 150);
        $this->RegisterVariableString('LastAction', 'Letzte Aktion', '', 160);

        $this->SetBuffer('RegisteredMessages', json_encode([]));
        $this->Debug('Create', 'Zone initialisiert');
    }

    public function Destroy()
    {
        $this->Debug('Destroy', 'Zone wird zerstört');
        $this->UnregisterSourceMessages();
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->Debug('ApplyChanges', 'gestartet');

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->Debug('ApplyChanges', 'Kernel nicht ready');
            return;
        }

        $number = $this->ReadPropertyInteger('ZoneNumber');
        if ($number < 1 || $number > 10) {
            IPS_SetProperty($this->InstanceID, 'ZoneNumber', max(1, min(10, $number)));
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $mode = $this->ReadPropertyInteger('MoistureMode');
        if (!in_array($mode, [self::MOISTURE_LOWEST, self::MOISTURE_AVERAGE], true)) {
            IPS_SetProperty($this->InstanceID, 'MoistureMode', self::MOISTURE_LOWEST);
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $this->SetValue('Enabled', $this->ReadPropertyBoolean('Enabled'));
        $this->SetValue('ZoneNumber', $this->ReadPropertyInteger('ZoneNumber'));
        $this->SetValue('DurationMinutes', $this->ReadPropertyInteger('Duration'));
        $this->SetValue('MoistureThresholdValue', $this->ReadPropertyInteger('MoistureThreshold'));
        $this->SetValue('MoistureModeValue', $this->ReadPropertyInteger('MoistureMode'));

        $this->RegisterSourceMessages();
        $this->RefreshValues();
        $this->UpdateStatus();

        $this->Debug('ApplyChanges.Properties', [
            'Enabled' => $this->ReadPropertyBoolean('Enabled'),
            'ZoneNumber' => $this->ReadPropertyInteger('ZoneNumber'),
            'Duration' => $this->ReadPropertyInteger('Duration'),
            'MoistureThreshold' => $this->ReadPropertyInteger('MoistureThreshold'),
            'MoistureMode' => $this->ReadPropertyInteger('MoistureMode'),
            'Actuator1Instance' => $this->ReadPropertyInteger('Actuator1Instance'),
            'Actuator1Variable' => $this->ReadPropertyInteger('Actuator1Variable'),
            'Actuator2Instance' => $this->ReadPropertyInteger('Actuator2Instance'),
            'Actuator2Variable' => $this->ReadPropertyInteger('Actuator2Variable'),
            'LegacyValve1' => $this->ReadPropertyInteger('Valve1'),
            'LegacyValve2' => $this->ReadPropertyInteger('Valve2')
        ]);
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value)
    {
        $this->Debug('RequestAction', ['Ident' => $Ident, 'Value' => $Value]);

        switch ($Ident) {
            case 'Enabled':
                IPS_SetProperty($this->InstanceID, 'Enabled', (bool) $Value);
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'DurationMinutes':
                IPS_SetProperty($this->InstanceID, 'Duration', max(0, (int) $Value));
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'MoistureThresholdValue':
                IPS_SetProperty($this->InstanceID, 'MoistureThreshold', max(0, min(100, (int) $Value)));
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'MoistureModeValue':
                $mode = (int) $Value;
                if (!in_array($mode, [self::MOISTURE_LOWEST, self::MOISTURE_AVERAGE], true)) {
                    $mode = self::MOISTURE_LOWEST;
                }
                IPS_SetProperty($this->InstanceID, 'MoistureMode', $mode);
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'ZoneActive':
                if ((bool) $Value) {
                    $this->StartZone();
                } else {
                    $this->StopZone();
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
            'SenderID' => $SenderID,
            'Message' => $Message,
            'Data' => $Data
        ]);

        if ($Message === VM_UPDATE) {
            $this->RefreshValues();
        }
    }

    public function RefreshValues(): void
    {
        $this->Debug('RefreshValues', 'gestartet');

        $this->SetValue('MoistureSensor1Value', $this->FormatSelectedVariableValue('MoistureSensor1'));
        $this->SetValue('MoistureSensor2Value', $this->FormatSelectedVariableValue('MoistureSensor2'));
        $this->SetValue('RainLast24hValue', $this->FormatSelectedVariableValue('RainLast24h'));

        $moisture = $this->GetEffectiveMoisture();
        $this->SetValue('ComputedMoisture', $moisture ?? 0.0);

        $shouldWater = $this->ShouldWater();
        $this->SetValue('ShouldWater', $shouldWater);

        $this->UpdateStatus();
        $this->Debug('RefreshValues', 'abgeschlossen');
    }

    public function Evaluate(): bool
    {
        $this->Debug('Evaluate', 'gestartet');
        $result = $this->ShouldWater();
        $this->SetValue('ShouldWater', $result);
        return $result;
    }

    public function ShouldWater(): bool
    {
        $this->Debug('ShouldWater', 'gestartet');

        if (!$this->ReadPropertyBoolean('Enabled')) {
            $this->SetValue('DecisionText', 'Kreis deaktiviert');
            $this->Debug('ShouldWater', 'false: deaktiviert');
            return false;
        }

        $rainValue = $this->ReadNumericPropertyVariable('RainLast24h');
        $rainThreshold = $this->ReadPropertyInteger('RainThreshold24h');

        $this->Debug('ShouldWater.Rain', [
            'Value' => $rainValue,
            'Threshold' => $rainThreshold
        ]);

        if ($rainThreshold > 0 && $rainValue !== null && $rainValue >= $rainThreshold) {
            $msg = 'Keine Bewässerung: Regensperre aktiv (' . $this->FormatNumber($rainValue) . ' mm)';
            $this->SetValue('DecisionText', $msg);
            $this->Debug('ShouldWater', $msg);
            return false;
        }

        $moisture = $this->GetEffectiveMoisture();
        $threshold = $this->ReadPropertyInteger('MoistureThreshold');

        $this->Debug('ShouldWater.Moisture', [
            'Value' => $moisture,
            'Threshold' => $threshold,
            'Mode' => $this->ReadPropertyInteger('MoistureMode')
        ]);

        if ($moisture === null) {
            $this->SetValue('DecisionText', 'Keine Bewässerung: kein gültiger Feuchtewert');
            $this->Debug('ShouldWater', 'false: kein Feuchtewert');
            return false;
        }

        if ($moisture < $threshold) {
            $msg = 'Bewässern: Feuchte ' . $this->FormatNumber($moisture) . ' % < ' . $threshold . ' %';
            $this->SetValue('DecisionText', $msg);
            $this->Debug('ShouldWater', $msg);
            return true;
        }

        $msg = 'Keine Bewässerung: Feuchte ' . $this->FormatNumber($moisture) . ' % >= ' . $threshold . ' %';
        $this->SetValue('DecisionText', $msg);
        $this->Debug('ShouldWater', $msg);
        return false;
    }

    public function StartZone(): void
    {
        $this->Debug('StartZone', 'gestartet');

        $this->SetZoneActuatorState(1, true);
        $this->SetZoneActuatorState(2, true);

        $this->SetValue('Actuator1Active', $this->HasActuatorConfigured(1));
        $this->SetValue('Actuator2Active', $this->HasActuatorConfigured(2));
        $this->SetValue('ZoneActive', true);

        $this->WriteLog('Kreis ' . $this->ReadPropertyInteger('ZoneNumber') . ' gestartet');
    }

    public function StopZone(): void
    {
        $this->Debug('StopZone', 'gestartet');

        $this->SetZoneActuatorState(1, false);
        $this->SetZoneActuatorState(2, false);

        $this->SetValue('Actuator1Active', false);
        $this->SetValue('Actuator2Active', false);
        $this->SetValue('ZoneActive', false);

        $this->WriteLog('Kreis ' . $this->ReadPropertyInteger('ZoneNumber') . ' gestoppt');
    }

    public function IsEnabled(): bool
    {
        return $this->ReadPropertyBoolean('Enabled');
    }

    public function GetZoneNumber(): int
    {
        return $this->ReadPropertyInteger('ZoneNumber');
    }

    public function GetDurationMinutes(): int
    {
        return $this->ReadPropertyInteger('Duration');
    }

    private function SetZoneActuatorState(int $number, bool $state): void
    {
        if ($number === 1) {
            $variable = $this->ReadPropertyInteger('Actuator1Variable');
            $instance = $this->ReadPropertyInteger('Actuator1Instance');

            // Kompatibilität zu alten V3.1/V3.2 Properties
            $legacyVariable = $this->ReadPropertyInteger('Valve1Variable');
            $legacyInstance = $this->ReadPropertyInteger('Valve1Instance');
            $legacy = $this->ReadPropertyInteger('Valve1');
        } else {
            $variable = $this->ReadPropertyInteger('Actuator2Variable');
            $instance = $this->ReadPropertyInteger('Actuator2Instance');

            // Kompatibilität zu alten V3.1/V3.2 Properties
            $legacyVariable = $this->ReadPropertyInteger('Valve2Variable');
            $legacyInstance = $this->ReadPropertyInteger('Valve2Instance');
            $legacy = $this->ReadPropertyInteger('Valve2');
        }

        $this->Debug('SetZoneActuatorState', [
            'Number' => $number,
            'Variable' => $variable,
            'Instance' => $instance,
            'LegacyVariable' => $legacyVariable,
            'LegacyInstance' => $legacyInstance,
            'Legacy' => $legacy,
            'State' => $state
        ]);

        // Wichtig:
        // Es wird immer eine Bool-Schaltvariable gesucht und dann per RequestAction() geschaltet.
        // Das ist der richtige Weg für Shelly/xComfort in IP-Symcon.
        if ($variable > 0) {
            $this->SetActuatorState($variable, $state);
            return;
        }

        if ($instance > 0) {
            $this->SetActuatorState($instance, $state);
            return;
        }

        if ($legacyVariable > 0) {
            $this->SetActuatorState($legacyVariable, $state);
            return;
        }

        if ($legacyInstance > 0) {
            $this->SetActuatorState($legacyInstance, $state);
            return;
        }

        if ($legacy > 0) {
            $this->SetActuatorState($legacy, $state);
            return;
        }

        $this->Debug('SetZoneActuatorState', 'kein Aktor für Nummer ' . $number . ' konfiguriert');
    }

    private function HasActuatorConfigured(int $number): bool
    {
        if ($number === 1) {
            return $this->ReadPropertyInteger('Actuator1Variable') > 0
                || $this->ReadPropertyInteger('Actuator1Instance') > 0
                || $this->ReadPropertyInteger('Valve1Variable') > 0
                || $this->ReadPropertyInteger('Valve1Instance') > 0
                || $this->ReadPropertyInteger('Valve1') > 0;
        }

        return $this->ReadPropertyInteger('Actuator2Variable') > 0
            || $this->ReadPropertyInteger('Actuator2Instance') > 0
            || $this->ReadPropertyInteger('Valve2Variable') > 0
            || $this->ReadPropertyInteger('Valve2Instance') > 0
            || $this->ReadPropertyInteger('Valve2') > 0;
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
            $this->Debug('GetEffectiveMoisture', 'kein Wert');
            return null;
        }

        if (count($values) === 1) {
            $this->Debug('GetEffectiveMoisture', ['Mode' => 'single', 'Result' => $values[0]]);
            return $values[0];
        }

        if ($this->ReadPropertyInteger('MoistureMode') === self::MOISTURE_AVERAGE) {
            $result = array_sum($values) / count($values);
            $this->Debug('GetEffectiveMoisture', ['Mode' => 'average', 'Values' => $values, 'Result' => $result]);
            return $result;
        }

        $result = min($values);
        $this->Debug('GetEffectiveMoisture', ['Mode' => 'lowest', 'Values' => $values, 'Result' => $result]);
        return $result;
    }

    private function ReadNumericPropertyVariable(string $propertyName): ?float
    {
        $variableID = $this->ReadPropertyInteger($propertyName);
        $this->Debug('ReadNumericPropertyVariable', [
            'Property' => $propertyName,
            'ID' => $variableID
        ]);

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

    private function SetActuatorState(int $targetID, bool $state): void
    {
        $this->Debug('SetActuatorState', ['TargetID' => $targetID, 'State' => $state]);

        if ($targetID <= 0 || !@IPS_ObjectExists($targetID)) {
            $this->Debug('SetActuatorState', 'Zielobjekt fehlt oder ist 0');
            return;
        }

        $switchVariableID = $this->FindSwitchVariable($targetID);
        if ($switchVariableID <= 0) {
            $this->Debug('SetActuatorState', 'keine schaltbare Bool-Variable gefunden');
            return;
        }

        try {
            RequestAction($switchVariableID, $state);
            $this->Debug('SetActuatorState', ['SwitchVariableID' => $switchVariableID, 'Method' => 'RequestAction']);
            return;
        } catch (Throwable $e) {
            $this->Debug('SetActuatorState.RequestActionException', $e->getMessage());
        }

        $this->Debug('SetActuatorState', 'nicht geschaltet: RequestAction fehlgeschlagen. Kein SetValue-Fallback, weil das Aktoren nicht zuverlässig schaltet.');
    }

    private function FindSwitchVariable(int $targetID): int
    {
        if (@IPS_VariableExists($targetID)) {
            $var = IPS_GetVariable($targetID);
            if ($var['VariableType'] === VARIABLETYPE_BOOLEAN) {
                $this->Debug('FindSwitchVariable.DirectVariable', $targetID);
                return $targetID;
            }
        }

        if (!@IPS_InstanceExists($targetID)) {
            return 0;
        }

        $candidates = [];
        foreach (IPS_GetChildrenIDs($targetID) as $childID) {
            if (!@IPS_VariableExists($childID)) {
                continue;
            }

            $var = IPS_GetVariable($childID);
            if ($var['VariableType'] !== VARIABLETYPE_BOOLEAN) {
                continue;
            }

            $name = strtolower(IPS_GetName($childID));
            $ident = strtolower(IPS_GetObject($childID)['ObjectIdent']);

            $bad = ['online', 'connected', 'reachable', 'update', 'firmware', 'battery', 'lowbat', 'error', 'overtemperature'];
            foreach ($bad as $word) {
                if (str_contains($name, $word) || str_contains($ident, $word)) {
                    continue 2;
                }
            }

            $score = 0;
            $good = ['state', 'status', 'switch', 'relay', 'output', 'power', 'valve', 'pump'];
            foreach ($good as $word) {
                if (str_contains($name, $word) || str_contains($ident, $word)) {
                    $score += 10;
                }
            }

            if ($var['VariableAction'] > 0) {
                $score += 100;
            }

            $candidates[$childID] = $score;
        }

        if (count($candidates) === 0) {
            return 0;
        }

        arsort($candidates);
        $selected = (int) array_key_first($candidates);
        $this->Debug('FindSwitchVariable.Selected', [
            'VariableID' => $selected,
            'Name' => IPS_GetName($selected),
            'Score' => $candidates[$selected]
        ]);

        return $selected;
    }

    private function RegisterSourceMessages(): void
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

    private function UnregisterSourceMessages(): void
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

    private function UpdateStatus(): void
    {
        if (!$this->ReadPropertyBoolean('Enabled')) {
            $this->SetStatus(104);
            return;
        }

        if (!$this->HasActuatorConfigured(1) && !$this->HasActuatorConfigured(2)) {
            $this->SetStatus(200);
            return;
        }

        $this->SetStatus(102);
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('IRR.Minutes')) {
            IPS_CreateVariableProfile('IRR.Minutes', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.Minutes', 0, 720, 1);
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

        if (!IPS_VariableProfileExists('IRRZ.MoistureMode')) {
            IPS_CreateVariableProfile('IRRZ.MoistureMode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('IRRZ.MoistureMode', self::MOISTURE_LOWEST, 'Niedrigster Wert', '', 0xFFB300);
            IPS_SetVariableProfileAssociation('IRRZ.MoistureMode', self::MOISTURE_AVERAGE, 'Durchschnitt', '', 0x27AE60);
        }
    }

    private function WriteLog(string $message): void
    {
        $text = date('d.m.Y H:i:s') . ' - ' . $message;
        $this->SetValue('LastAction', $text);
        IPS_LogMessage('IRRZ[' . $this->InstanceID . ']', $message);
        $this->Debug('WriteLog', $message);
    }

    private function FormatNumber(float $value): string
    {
        return number_format($value, 1, ',', '');
    }

    private function Debug(string $Message, $Data = null): void
    {
        if ($Data === null) {
            $this->SendDebug('IRRZ', $Message, 0);
            return;
        }

        if (is_array($Data) || is_object($Data)) {
            $json = json_encode($Data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->SendDebug($Message, $json === false ? 'json_encode failed' : $json, 0);
            return;
        }

        if (is_bool($Data)) {
            $this->SendDebug($Message, $Data ? 'true' : 'false', 0);
            return;
        }

        $this->SendDebug($Message, (string) $Data, 0);
    }
}
