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
        $this->RegisterPropertyInteger('Duration', 10);
        $this->RegisterPropertyInteger('MoistureThreshold', 35);
        $this->RegisterPropertyInteger('MoistureMode', self::MOISTURE_LOWEST);

        $this->RegisterPropertyInteger('MoistureSensor1', 0);
        $this->RegisterPropertyInteger('MoistureSensor2', 0);
        $this->RegisterPropertyInteger('RainLast24h', 0);
        $this->RegisterPropertyInteger('RainThreshold24h', 0);

        $this->RegisterPropertyInteger('Actuator1Instance', 0);
        $this->RegisterPropertyInteger('Valve1Variable', 0);
        $this->RegisterPropertyInteger('Actuator2Instance', 0);
        $this->RegisterPropertyInteger('DelayBetweenActuatorsMs', 500);

        // Kompatibilität zu älteren Versionen, nicht im Formular sichtbar
        $this->RegisterPropertyInteger('Actuator1Variable', 0);
        $this->RegisterPropertyInteger('Actuator2Variable', 0);
        $this->RegisterPropertyInteger('Valve1', 0);
        $this->RegisterPropertyInteger('Valve2', 0);
        $this->RegisterPropertyInteger('Valve1Instance', 0);
        $this->RegisterPropertyInteger('Valve2Instance', 0);
        $this->RegisterPropertyInteger('Valve2Variable', 0);

        // Kompatibilität zu alten V3.1-Konfigurationen

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
        $this->RegisterVariableString('LastAction', 'Letzte 4 Aktionen', '~HTMLBox', 160);

        $this->SetBuffer('RegisteredMessages', json_encode([]));
        $this->SetBuffer('Actuator1SwitchVariableID', '0');
        $this->SetBuffer('Actuator2SwitchVariableID', '0');
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
            'Valve1Instance' => $this->ReadPropertyInteger('Valve1Instance'),
            'Valve1Variable' => $this->ReadPropertyInteger('Valve1Variable'),
            'Valve2Instance' => $this->ReadPropertyInteger('Valve2Instance'),
            'Valve2Variable' => $this->ReadPropertyInteger('Valve2Variable'),
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
                IPS_SetProperty($this->InstanceID, 'Duration', max(1, (int) $Value));
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

    public function StartZone(bool $FromMaster = false): void
    {
        $delayMs = max(0, $this->ReadPropertyInteger('DelayBetweenActuatorsMs'));

        $this->Debug('StartZone', [
            'Step' => 'gestartet',
            'FromMaster' => $FromMaster,
            'Actuator1Configured' => $this->HasActuatorConfigured(1),
            'Actuator2Configured' => $this->HasActuatorConfigured(2),
            'Actuator1Instance' => $this->ReadPropertyInteger('Actuator1Instance'),
            'Actuator2Instance' => $this->ReadPropertyInteger('Actuator2Instance'),
            'DelayBetweenActuatorsMs' => $delayMs,
            'Order' => 'Aktor2 zuerst, wenn beide vorhanden'
        ]);

        if (!$FromMaster) {
            $this->SetMasterPumpState(true);
        }

        $has1 = $this->HasActuatorConfigured(1);
        $has2 = $this->HasActuatorConfigured(2);

        $actuator1Switched = false;
        $actuator2Switched = false;

        if ($has1 && $has2) {
            // Da Aktor 2 alleine funktioniert, aber nicht nach Aktor 1,
            // wird bei zwei Aktoren bewusst Aktor 2 zuerst geschaltet.
            $actuator2Switched = $this->SetZoneActuatorState(2, true);

            if ($delayMs > 0) {
                $this->Debug('StartZone.DelayBeforeActuator1', $delayMs);
                IPS_Sleep($delayMs);
            }

            $actuator1Switched = $this->SetZoneActuatorState(1, true);
        } else {
            if ($has1) {
                $actuator1Switched = $this->SetZoneActuatorState(1, true);
            }

            if ($has2) {
                $actuator2Switched = $this->SetZoneActuatorState(2, true);
            }
        }

        $this->SetValue('Actuator1Active', $actuator1Switched);
        $this->SetValue('Actuator2Active', $actuator2Switched);
        $this->SetValue('ZoneActive', $actuator1Switched || $actuator2Switched);

        $this->WriteLog('Kreis ' . $this->ReadPropertyInteger('ZoneNumber') . ' gestartet');
    }

    public function StopZone(bool $FromMaster = false): void
    {
        $delayMs = max(0, $this->ReadPropertyInteger('DelayBetweenActuatorsMs'));

        $this->Debug('StopZone', [
            'Step' => 'gestartet',
            'FromMaster' => $FromMaster,
            'Actuator1Configured' => $this->HasActuatorConfigured(1),
            'Actuator2Configured' => $this->HasActuatorConfigured(2),
            'Actuator1Instance' => $this->ReadPropertyInteger('Actuator1Instance'),
            'Actuator2Instance' => $this->ReadPropertyInteger('Actuator2Instance'),
            'DelayBetweenActuatorsMs' => $delayMs,
            'Order' => 'Aktor2 zuerst AUS'
        ]);

        // Nur bei manuellem Stop steuert der Kreis die Pumpe.
        // Bei Zeitsteuerung/Automatik macht das der Master.
        if (!$FromMaster) {
            $earlyOffSeconds = $this->GetMasterPumpEarlyOffSeconds();
            $this->Debug('StopZone.ManualPumpEarlyOff', [
                'EarlyOffSeconds' => $earlyOffSeconds
            ]);

            $this->SetMasterPumpState(false);

            if ($earlyOffSeconds > 0) {
                IPS_Sleep($earlyOffSeconds * 1000);
            }
        }

        $has1 = $this->HasActuatorConfigured(1);
        $has2 = $this->HasActuatorConfigured(2);

        // Wichtig für Sequenzbetrieb:
        // Aktor 2 wird immer explizit ausgeschaltet, wenn er konfiguriert ist.
        // Nicht vom Statuswert abhängig machen.
        if ($has2) {
            $this->Debug('StopZone', 'Schalte Aktor 2 AUS');
            $this->SetZoneActuatorState(2, false);
        }

        if ($has1 && $has2 && $delayMs > 0) {
            $this->Debug('StopZone.DelayBeforeActuator1', $delayMs);
            IPS_Sleep($delayMs);
        }

        if ($has1) {
            $this->Debug('StopZone', 'Schalte Aktor 1 AUS');
            $this->SetZoneActuatorState(1, false);
        }

        // Sicherheitsversuch im Sequenzbetrieb:
        // Falls Aktor 2 durch Gateway/Timing nicht beim ersten Versuch ausging,
        // nochmals nach Aktor 1 ausschalten.
        if ($FromMaster && $has2) {
            if ($delayMs > 0) {
                IPS_Sleep($delayMs);
            }
            $this->Debug('StopZone', 'Sicherheitsversuch: Aktor 2 nochmals AUS');
            $this->SetZoneActuatorState(2, false);
        }

        $this->SetValue('Actuator1Active', false);
        $this->SetValue('Actuator2Active', false);
        $this->SetValue('ZoneActive', false);

        // Sicherheit: nach Stop keine alten Schaltvariablen merken.
        $this->SetBuffer('Actuator1SwitchVariableID', '0');
        $this->SetBuffer('Actuator2SwitchVariableID', '0');

        $this->WriteLog('Kreis ' . $this->ReadPropertyInteger('ZoneNumber') . ' gestoppt');
    }

    private function GetMasterPumpEarlyOffSeconds(): int
    {
        $parentID = @IPS_GetParent($this->InstanceID);
        if ($parentID <= 0 || !@IPS_InstanceExists($parentID)) {
            return 0;
        }

        try {
            if (function_exists('IRR_GetPumpEarlyOffSeconds')) {
                $value = @IRR_GetPumpEarlyOffSeconds($parentID);
                if (is_int($value)) {
                    return max(0, $value);
                }
            }

            $value = @IPS_GetProperty($parentID, 'PumpEarlyOffSeconds');
            if (is_numeric($value)) {
                return max(0, (int) $value);
            }
        } catch (Throwable $e) {
            $this->Debug('GetMasterPumpEarlyOffSeconds.Exception', $e->getMessage());
        }

        return 0;
    }

    private function SetMasterPumpState(bool $state): void
    {
        $parentID = @IPS_GetParent($this->InstanceID);

        $this->Debug('SetMasterPumpState', [
            'ParentID' => $parentID,
            'State' => $state
        ]);

        if ($parentID <= 0 || !@IPS_InstanceExists($parentID)) {
            $this->Debug('SetMasterPumpState', 'kein gültiger Master als Parent gefunden');
            return;
        }

        try {
            if ($state) {
                @IRR_StartPumpFromZone($parentID);
            } else {
                @IRR_StopPumpFromZone($parentID);
            }
        } catch (Throwable $e) {
            $this->Debug('SetMasterPumpState.Exception', $e->getMessage());
        }
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

    private function SetZoneActuatorState(int $number, bool $state): bool
    {
        $targetID = $this->GetActuatorTargetID($number);

        $this->Debug('SetZoneActuatorState', [
            'Number' => $number,
            'TargetID' => $targetID,
            'TargetName' => ($targetID > 0 && @IPS_ObjectExists($targetID)) ? @IPS_GetName($targetID) : 'nicht konfiguriert',
            'State' => $state
        ]);

        if ($targetID <= 0) {
            $this->Debug('SetZoneActuatorState', 'kein Aktor für Nummer ' . $number . ' konfiguriert');
            return false;
        }

        return $this->SetActuatorState($targetID, $state, $number);
    }

    private function GetActuatorTargetID(int $number): int
    {
        if ($number === 1) {
            $instance = $this->ReadPropertyInteger('Actuator1Instance');
            $variableCompat = $this->ReadPropertyInteger('Actuator1Variable');
            $legacyInstance = $this->ReadPropertyInteger('Valve1Instance');
            $legacyVariable = $this->ReadPropertyInteger('Valve1Variable');
            $legacy = $this->ReadPropertyInteger('Valve1');
        } else {
            $instance = $this->ReadPropertyInteger('Actuator2Instance');
            $variableCompat = $this->ReadPropertyInteger('Actuator2Variable');
            $legacyInstance = $this->ReadPropertyInteger('Valve2Instance');
            $legacyVariable = $this->ReadPropertyInteger('Valve2Variable');
            $legacy = $this->ReadPropertyInteger('Valve2');
        }

        $this->Debug('GetActuatorTargetID', [
            'Number' => $number,
            'Instance' => $instance,
            'VariableCompat' => $variableCompat,
            'LegacyInstance' => $legacyInstance,
            'LegacyVariable' => $legacyVariable,
            'Legacy' => $legacy
        ]);

        // Aktuelle Formulareinstellung: immer Instanz
        if ($instance > 0 && @IPS_ObjectExists($instance)) {
            return $instance;
        }

        // Kompatibilität mit älteren Versionen
        if ($variableCompat > 0 && @IPS_ObjectExists($variableCompat)) {
            return $variableCompat;
        }

        if ($legacyInstance > 0 && @IPS_ObjectExists($legacyInstance)) {
            return $legacyInstance;
        }

        if ($legacyVariable > 0 && @IPS_ObjectExists($legacyVariable)) {
            return $legacyVariable;
        }

        if ($legacy > 0 && @IPS_ObjectExists($legacy)) {
            return $legacy;
        }

        return 0;
    }

    private function HasActuatorConfigured(int $number): bool
    {
        return $this->GetActuatorTargetID($number) > 0;
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

    private function SetActuatorState(int $targetID, bool $state, int $actuatorNumber = 0): bool
    {
        $this->Debug('SetActuatorState', [
            'TargetID' => $targetID,
            'TargetName' => ($targetID > 0 && @IPS_ObjectExists($targetID)) ? @IPS_GetName($targetID) : '',
            'State' => $state,
            'ActuatorNumber' => $actuatorNumber
        ]);

        if ($targetID <= 0 || !@IPS_ObjectExists($targetID)) {
            $this->Debug('SetActuatorState', 'Zielobjekt fehlt oder ist 0');
            return false;
        }

        $bufferIdent = $actuatorNumber === 2 ? 'Actuator2SwitchVariableID' : 'Actuator1SwitchVariableID';

        $candidates = [];

        // Beim Ausschalten zuerst exakt dieselbe Schaltvariable verwenden,
        // die beim Einschalten erfolgreich war.
        // Das ist wichtig bei Shelly/xComfort, wenn unter einer Instanz mehrere Bool-Variablen liegen.
        if (!$state && $actuatorNumber > 0) {
            $lastSwitchVariableID = (int) $this->GetBuffer($bufferIdent);
            if ($lastSwitchVariableID > 0 && @IPS_VariableExists($lastSwitchVariableID)) {
                $candidates[] = $lastSwitchVariableID;
                $this->Debug('SetActuatorState.UsingLastSwitchVariable', [
                    'ActuatorNumber' => $actuatorNumber,
                    'SwitchVariableID' => $lastSwitchVariableID,
                    'Name' => @IPS_GetName($lastSwitchVariableID)
                ]);
            }
        }

        foreach ($this->FindSwitchVariableCandidates($targetID) as $candidateID) {
            if (!in_array($candidateID, $candidates, true)) {
                $candidates[] = $candidateID;
            }
        }

        if (count($candidates) === 0) {
            $this->Debug('SetActuatorState', 'keine geeignete schaltbare Bool-Variable gefunden');
            return false;
        }

        foreach ($candidates as $switchVariableID) {
            $this->Debug('SetActuatorState.RequestAction.Start', [
                'SwitchVariableID' => $switchVariableID,
                'SwitchVariableName' => @IPS_GetName($switchVariableID),
                'State' => $state,
                'ActuatorNumber' => $actuatorNumber
            ]);

            try {
                RequestAction($switchVariableID, $state);
                $this->Debug('SetActuatorState.RequestAction.Success', [
                    'SwitchVariableID' => $switchVariableID,
                    'State' => $state,
                    'ActuatorNumber' => $actuatorNumber
                ]);

                if ($actuatorNumber > 0) {
                    if ($state) {
                        $this->SetBuffer($bufferIdent, (string) $switchVariableID);
                    } else {
                        $this->SetBuffer($bufferIdent, '0');
                    }
                }

                return true;
            } catch (Throwable $e) {
                $this->Debug('SetActuatorState.RequestAction.Exception', [
                    'SwitchVariableID' => $switchVariableID,
                    'Error' => $e->getMessage(),
                    'ActuatorNumber' => $actuatorNumber
                ]);
            }
        }

        $this->Debug('SetActuatorState', 'kein Kandidat konnte per RequestAction geschaltet werden');
        return false;
    }

    private function FindSwitchVariableCandidates(int $targetID): array
    {
        if (@IPS_VariableExists($targetID)) {
            $var = IPS_GetVariable($targetID);
            if ($var['VariableType'] === VARIABLETYPE_BOOLEAN) {
                return [$targetID];
            }
        }

        if (!@IPS_InstanceExists($targetID)) {
            return [];
        }

        $idsToCheck = IPS_GetChildrenIDs($targetID);

        foreach (IPS_GetChildrenIDs($targetID) as $childID) {
            foreach (@IPS_GetChildrenIDs($childID) ?: [] as $grandChildID) {
                $idsToCheck[] = $grandChildID;
            }
        }

        $candidates = [];
        $debugCandidates = [];

        foreach (array_unique($idsToCheck) as $childID) {
            if (!@IPS_VariableExists($childID)) {
                continue;
            }

            $var = IPS_GetVariable($childID);
            if ($var['VariableType'] !== VARIABLETYPE_BOOLEAN) {
                continue;
            }

            $object = IPS_GetObject($childID);
            $nameRaw = IPS_GetName($childID);
            $identRaw = $object['ObjectIdent'];
            $profileRaw = $var['VariableCustomProfile'] !== '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];

            $name = strtolower($nameRaw);
            $ident = strtolower($identRaw);
            $profile = strtolower($profileRaw);

            $bad = [
                'online', 'connected', 'reachable', 'update', 'firmware', 'battery',
                'lowbat', 'error', 'overtemperature', 'rssi', 'cloud', 'overload',
                'schutz', 'alarm', 'warning', 'warnung', 'motion', 'kontakt', 'contact'
            ];

            $isBad = false;
            foreach ($bad as $word) {
                if (str_contains($name, $word) || str_contains($ident, $word) || str_contains($profile, $word)) {
                    $isBad = true;
                    break;
                }
            }

            if ($isBad) {
                continue;
            }

            $score = 0;

            if ($var['VariableAction'] > 0) {
                $score += 1000;
            } else {
                $score -= 1000;
            }

            if (str_contains($profile, 'switch') || str_contains($profile, 'boolean') || str_contains($profile, '~switch')) {
                $score += 200;
            }

            $veryGood = [
                'state', 'status', 'switch', 'relay', 'output', 'power',
                'ausgang', 'schalter', 'switchstate', 'onoff', 'on_off'
            ];

            foreach ($veryGood as $word) {
                if ($name === $word || $ident === $word || str_contains($name, $word) || str_contains($ident, $word)) {
                    $score += 100;
                }
            }

            $good = ['valve', 'pump', 'kanal', 'aktor', 'schalten'];
            foreach ($good as $word) {
                if (str_contains($name, $word) || str_contains($ident, $word)) {
                    $score += 20;
                }
            }

            if ($score >= 0) {
                $candidates[$childID] = $score;
            }

            $debugCandidates[] = [
                'ID' => $childID,
                'Name' => $nameRaw,
                'Ident' => $identRaw,
                'Profile' => $profileRaw,
                'ActionID' => $var['VariableAction'],
                'Score' => $score
            ];
        }

        arsort($candidates);

        $this->Debug('FindSwitchVariableCandidates', [
            'TargetID' => $targetID,
            'TargetName' => @IPS_GetName($targetID),
            'Candidates' => $debugCandidates,
            'SortedIDs' => array_keys($candidates)
        ]);

        return array_map('intval', array_keys($candidates));
    }

    private function FindSwitchVariable(int $targetID): int
    {
        $candidates = $this->FindSwitchVariableCandidates($targetID);
        if (count($candidates) === 0) {
            return 0;
        }

        return $candidates[0];
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
        $this->SetBuffer('Actuator1SwitchVariableID', '0');
        $this->SetBuffer('Actuator2SwitchVariableID', '0');
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
        $entries = [];
        $buffer = $this->GetBuffer('LastActionLog');

        if (is_string($buffer) && trim($buffer) !== '') {
            $decoded = json_decode($buffer, true);
            if (is_array($decoded)) {
                $entries = $decoded;
            }
        }

        array_unshift($entries, [
            'time'    => date('d.m.Y H:i:s'),
            'message' => $message
        ]);

        $entries = array_slice($entries, 0, 4);
        $this->SetBuffer('LastActionLog', json_encode($entries));
        $this->SetValue('LastAction', $this->RenderLastActionHtml($entries));

        IPS_LogMessage('IRRZ[' . $this->InstanceID . ']', $message);
        $this->Debug('WriteLog', $message);
    }

    private function RenderLastActionHtml(array $entries): string
    {
        if (count($entries) === 0) {
            return '';
        }

        $html = '<div style="font-family:Arial, sans-serif; font-size:13px; line-height:1.45;">';

        foreach ($entries as $entry) {
            $time = isset($entry['time']) ? htmlspecialchars((string) $entry['time'], ENT_QUOTES, 'UTF-8') : '';
            $message = isset($entry['message']) ? htmlspecialchars((string) $entry['message'], ENT_QUOTES, 'UTF-8') : '';

            $html .= '<div style="margin-bottom:4px; padding:2px 0;">';
            $html .= '<span style="color:#0066cc; font-weight:bold;">' . $time . '</span>';
            $html .= '<span style="color:#666;"> &ndash; </span>';
            $html .= '<span style="color:#222;">' . $message . '</span>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
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
