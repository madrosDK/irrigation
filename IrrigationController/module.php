<?php

declare(strict_types=1);

class IrrigationController extends IPSModule
{
    private const MODE_MANUAL = 1;
    private const MODE_TIME = 2;
    private const MODE_AUTO = 3;

    private const MODULE_ID_ZONE = '{B69A3F87-2E64-4AA0-B67E-7D84587B8A11}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Mode', self::MODE_MANUAL);
        $this->RegisterPropertyInteger('PumpLeadTimeSeconds', 5);
        $this->RegisterPropertyInteger('PumpEarlyOffSeconds', 0);
        $this->RegisterPropertyInteger('PauseBetweenZonesSeconds', 5);
        $this->RegisterPropertyInteger('MaxZones', 10);

        // V3.2: Instanz oder direkte Schaltvariable möglich.
        $this->RegisterPropertyInteger('PumpInstance', 0);
        // Kompatibilität zu alten V3.1-V3.3-Konfigurationen
        $this->RegisterPropertyInteger('PumpVariable', 0);
        $this->RegisterPropertyInteger('Pump', 0);

        $this->RegisterProfiles();

        $this->RegisterVariableInteger('Mode', 'Betriebsmodus', 'IRR.Mode', 10);
        $this->EnableAction('Mode');

        $this->RegisterVariableInteger('PumpLeadTimeSeconds', 'Pumpenvorlauf', 'IRR.Seconds', 20);
        $this->EnableAction('PumpLeadTimeSeconds');

        $this->RegisterVariableInteger('PumpEarlyOffSeconds', 'Pumpe früher aus', 'IRR.Seconds', 30);
        $this->EnableAction('PumpEarlyOffSeconds');

        $this->RegisterVariableInteger('PauseBetweenZonesSeconds', 'Pause zwischen Kreisen', 'IRR.Seconds', 40);
        $this->EnableAction('PauseBetweenZonesSeconds');

        $this->RegisterVariableBoolean('SequenceActive', 'Sequenz aktiv', '~Switch', 50);
        $this->EnableAction('SequenceActive');

        $this->RegisterVariableBoolean('PumpActive', 'Pumpe aktiv', '~Switch', 60);
        $this->RegisterVariableInteger('CurrentZone', 'Aktueller Kreis', '', 70);
        $this->RegisterVariableInteger('QueueCount', 'Wartende Kreise', '', 80);
        $this->RegisterVariableString('DecisionText', 'Sequenzstatus', '', 90);
        $this->RegisterVariableString('LastAction', 'Letzte Aktion', '', 100);
        $this->RegisterVariableString('ZoneOverview', 'Kreisübersicht', '', 110);

        $this->RegisterTimer('StartCurrentZoneAfterPumpTimer', 0, 'IRR_StartCurrentZoneAfterPumpLead($_IPS[\'TARGET\']);');
        $this->RegisterTimer('StopPumpEarlyTimer', 0, 'IRR_StopPumpEarly($_IPS[\'TARGET\']);');
        $this->RegisterTimer('StopCurrentZoneTimer', 0, 'IRR_FinishCurrentZone($_IPS[\'TARGET\']);');
        $this->RegisterTimer('StartNextZoneTimer', 0, 'IRR_StartNextZone($_IPS[\'TARGET\']);');

        $this->SetBuffer('Queue', json_encode([]));
        $this->SetBuffer('CurrentZoneID', '0');

        $this->Debug('Create', 'Master initialisiert');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->Debug('ApplyChanges', 'gestartet');

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->Debug('ApplyChanges', 'Kernel nicht ready');
            return;
        }

        $maxZones = $this->ReadPropertyInteger('MaxZones');
        if ($maxZones < 1 || $maxZones > 10) {
            IPS_SetProperty($this->InstanceID, 'MaxZones', max(1, min(10, $maxZones)));
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $mode = $this->ReadPropertyInteger('Mode');
        if (!in_array($mode, [self::MODE_MANUAL, self::MODE_TIME, self::MODE_AUTO], true)) {
            IPS_SetProperty($this->InstanceID, 'Mode', self::MODE_MANUAL);
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $this->SetValue('Mode', $mode);
        $this->SetValue('PumpLeadTimeSeconds', $this->ReadPropertyInteger('PumpLeadTimeSeconds'));
        $this->SetValue('PumpEarlyOffSeconds', $this->ReadPropertyInteger('PumpEarlyOffSeconds'));
        $this->SetValue('PauseBetweenZonesSeconds', $this->ReadPropertyInteger('PauseBetweenZonesSeconds'));

        $this->MaintainWeekplan('ScheduleTimer', 'Zeitsteuerung');
        $this->MaintainWeekplan('ScheduleAuto', 'Automatik');
        $this->UpdateWeekplanVisibility();

        $this->RefreshZones();
        $this->UpdateStatus();

        $this->Debug('ApplyChanges.Properties', [
            'Mode' => $mode,
            'PumpLeadTimeSeconds' => $this->ReadPropertyInteger('PumpLeadTimeSeconds'),
            'PumpEarlyOffSeconds' => $this->ReadPropertyInteger('PumpEarlyOffSeconds'),
            'PauseBetweenZonesSeconds' => $this->ReadPropertyInteger('PauseBetweenZonesSeconds'),
            'PumpInstance' => $this->ReadPropertyInteger('PumpInstance'),
            'PumpVariable' => $this->ReadPropertyInteger('PumpVariable'),
            'LegacyPump' => $this->ReadPropertyInteger('Pump')
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
            case 'Mode':
                $value = (int) $Value;
                if (!in_array($value, [self::MODE_MANUAL, self::MODE_TIME, self::MODE_AUTO], true)) {
                    $value = self::MODE_MANUAL;
                }
                IPS_SetProperty($this->InstanceID, 'Mode', $value);
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'PumpLeadTimeSeconds':
                IPS_SetProperty($this->InstanceID, 'PumpLeadTimeSeconds', max(0, (int) $Value));
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'PumpEarlyOffSeconds':
                IPS_SetProperty($this->InstanceID, 'PumpEarlyOffSeconds', max(0, (int) $Value));
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'PauseBetweenZonesSeconds':
                IPS_SetProperty($this->InstanceID, 'PauseBetweenZonesSeconds', max(0, (int) $Value));
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'SequenceActive':
                if ((bool) $Value) {
                    if ($this->GetValue('Mode') === self::MODE_AUTO) {
                        $this->StartAutomaticSequence();
                    } else {
                        $this->StartManualSequence();
                    }
                } else {
                    $this->StopSequence();
                }
                break;

            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    public function CreateZone(): void
    {
        $this->Debug('CreateZone', [
            'Step' => 'gestartet',
            'MasterID' => $this->InstanceID,
            'MasterExists' => @IPS_InstanceExists($this->InstanceID),
            'MasterParent' => @IPS_GetParent($this->InstanceID)
        ]);

        if (!@IPS_InstanceExists($this->InstanceID)) {
            $this->WriteLog('Master-Instanz existiert nicht, Kreis kann nicht angelegt werden');
            return;
        }

        $zones = $this->GetZones();
        $maxZones = max(1, min(10, $this->ReadPropertyInteger('MaxZones')));

        if (count($zones) >= $maxZones) {
            $this->WriteLog('Maximale Kreisanzahl erreicht');
            return;
        }

        $used = [];
        foreach ($zones as $existingZoneID) {
            $zoneNumber = @IRRZ_GetZoneNumber($existingZoneID);
            if (is_int($zoneNumber)) {
                $used[] = $zoneNumber;
            }
        }

        $number = 1;
        while (in_array($number, $used, true) && $number <= $maxZones) {
            $number++;
        }

        if ($number > $maxZones) {
            $this->WriteLog('Keine freie Kreisnummer gefunden');
            return;
        }

        $zoneID = @IPS_CreateInstance(self::MODULE_ID_ZONE);

        $this->Debug('CreateZone.Created', [
            'ZoneID' => $zoneID,
            'ZoneExists' => ($zoneID > 0 ? @IPS_InstanceExists($zoneID) : false)
        ]);

        if ($zoneID === false || $zoneID === 0 || !@IPS_InstanceExists($zoneID)) {
            $this->WriteLog('Kreis konnte nicht angelegt werden. Prüfe, ob das Modul "Irrigation Zone" installiert/geladen ist.');
            $this->Debug('CreateZone.Error', [
                'CreatedID' => $zoneID,
                'ZoneModuleID' => self::MODULE_ID_ZONE
            ]);
            return;
        }

        // Wichtig: Parent sofort nach dem Erstellen setzen und danach prüfen.
        $setParentResult = @IPS_SetParent($zoneID, $this->InstanceID);
        $actualParent = @IPS_GetParent($zoneID);

        $this->Debug('CreateZone.ParentSet', [
            'ZoneID' => $zoneID,
            'WantedParent' => $this->InstanceID,
            'ActualParent' => $actualParent,
            'SetParentResult' => $setParentResult
        ]);

        if ($actualParent !== $this->InstanceID) {
            // Zweiter Versuch, falls Symcon direkt nach IPS_CreateInstance noch nicht sauber umgehängt hat.
            IPS_Sleep(200);
            $setParentResult2 = @IPS_SetParent($zoneID, $this->InstanceID);
            $actualParent2 = @IPS_GetParent($zoneID);

            $this->Debug('CreateZone.ParentSetRetry', [
                'ZoneID' => $zoneID,
                'WantedParent' => $this->InstanceID,
                'ActualParent' => $actualParent2,
                'SetParentResult' => $setParentResult2
            ]);

            if ($actualParent2 !== $this->InstanceID) {
                $this->WriteLog('Kreis wurde angelegt, konnte aber nicht unter die Master-Instanz verschoben werden. Zone-ID: ' . $zoneID);
                return;
            }
        }

        IPS_SetName($zoneID, 'Kreis ' . $number);
        IPS_SetProperty($zoneID, 'ZoneNumber', $number);

        // Damit neue Kreise unten unter der Master-Instanz stehen, nur die Objektposition setzen.
        // Es wird keine Objekt-ID beeinflusst.
        @IPS_SetPosition($zoneID, 900 + $number);

        IPS_ApplyChanges($zoneID);

        $this->RefreshZones();
        $this->UpdateStatus();

        $this->WriteLog('Kreis ' . $number . ' angelegt');
        $this->Debug('CreateZone.Done', [
            'ZoneID' => $zoneID,
            'ParentID' => @IPS_GetParent($zoneID),
            'Number' => $number,
            'Name' => @IPS_GetName($zoneID)
        ]);
    }

    public function RefreshZones(): void
    {
        $zones = $this->GetZones();
        $parts = [];

        foreach ($zones as $zoneID) {
            $parts[] = '#' . $zoneID . ' | Kreis ' . @IRRZ_GetZoneNumber($zoneID) . ' | ' . @IPS_GetName($zoneID);
        }

        $this->SetValue('ZoneOverview', count($parts) > 0 ? implode("\n", $parts) : 'Keine Kreise unter dieser Master-Instanz gefunden');
        $this->SetValue('QueueCount', count($this->GetQueue()));
        $this->Debug('RefreshZones', ['Zones' => $zones]);
    }

    public function StartManualSequence(): void
    {
        $this->Debug('StartManualSequence', 'gestartet');
        $zones = $this->GetRunnableZones(false);
        $this->StartQueue($zones, 'Zeitsteuerung / Manuell');
    }

    public function StartAutomaticSequence(): void
    {
        $this->Debug('StartAutomaticSequence', 'gestartet');
        $zones = $this->GetRunnableZones(true);
        $this->StartQueue($zones, 'Automatik');
    }

    public function StopSequence(): void
    {
        $this->Debug('StopSequence', 'gestartet');

        $this->SetTimerInterval('StartCurrentZoneAfterPumpTimer', 0);
        $this->SetTimerInterval('StopPumpEarlyTimer', 0);
        $this->SetTimerInterval('StopCurrentZoneTimer', 0);
        $this->SetTimerInterval('StopPumpEarlyTimer', 0);
        $this->SetTimerInterval('StartNextZoneTimer', 0);

        $currentZoneID = (int) $this->GetBuffer('CurrentZoneID');
        if ($currentZoneID > 0 && @IPS_InstanceExists($currentZoneID)) {
            @IRRZ_StopZone($currentZoneID);
        }

        $this->SetPumpState(false);

        $this->SetBuffer('Queue', json_encode([]));
        $this->SetBuffer('CurrentZoneID', '0');

        $this->SetValue('SequenceActive', false);
        $this->SetValue('PumpActive', false);
        $this->SetValue('CurrentZone', 0);
        $this->SetValue('QueueCount', 0);

        $this->WriteLog('Sequenz gestoppt');
    }

    public function StartNextZone(): void
    {
        $this->Debug('StartNextZone', 'gestartet');

        $this->SetTimerInterval('StartNextZoneTimer', 0);

        $queue = $this->GetQueue();
        if (count($queue) === 0) {
            $this->Debug('StartNextZone', 'Queue leer');
            $this->SetBuffer('CurrentZoneID', '0');
            $this->SetPumpState(false);
            $this->SetValue('SequenceActive', false);
            $this->SetValue('PumpActive', false);
            $this->SetValue('CurrentZone', 0);
            $this->SetValue('QueueCount', 0);
            $this->WriteLog('Sequenz abgeschlossen');
            return;
        }

        $zoneID = array_shift($queue);
        $this->SetQueue($queue);
        $this->SetBuffer('CurrentZoneID', (string) $zoneID);

        if (!@IPS_InstanceExists($zoneID)) {
            $this->Debug('StartNextZone', 'Zone existiert nicht mehr, springe weiter');
            $this->SetTimerInterval('StartNextZoneTimer', 100);
            return;
        }

        $duration = @IRRZ_GetDurationMinutes($zoneID);
        if (!is_int($duration) || $duration <= 0) {
            $this->WriteLog('Kreis ' . @IRRZ_GetZoneNumber($zoneID) . ' hat keine gültige Dauer und wird übersprungen');
            $this->SetTimerInterval('StartNextZoneTimer', 100);
            return;
        }

        $this->SetValue('CurrentZone', @IRRZ_GetZoneNumber($zoneID));
        $this->SetValue('QueueCount', count($queue));

        $this->WriteLog('Bereite Kreis ' . @IRRZ_GetZoneNumber($zoneID) . ' vor: Pumpe EIN, danach Vorlauf');
        $this->SetPumpState(true);

        $leadMs = max(0, $this->ReadPropertyInteger('PumpLeadTimeSeconds')) * 1000;
        $this->SetTimerInterval('StartCurrentZoneAfterPumpTimer', max(100, $leadMs));
    }

    public function StartCurrentZoneAfterPumpLead(): void
    {
        $this->Debug('StartCurrentZoneAfterPumpLead', 'gestartet');
        $this->SetTimerInterval('StartCurrentZoneAfterPumpTimer', 0);
        $this->SetTimerInterval('StopPumpEarlyTimer', 0);

        $zoneID = (int) $this->GetBuffer('CurrentZoneID');
        if ($zoneID <= 0 || !@IPS_InstanceExists($zoneID)) {
            $this->Debug('StartCurrentZoneAfterPumpLead', 'keine gültige aktuelle Zone');
            $this->SetTimerInterval('StartNextZoneTimer', 100);
            return;
        }

        $duration = @IRRZ_GetDurationMinutes($zoneID);
        if (!is_int($duration) || $duration <= 0) {
            $this->WriteLog('Kreis ' . @IRRZ_GetZoneNumber($zoneID) . ' hat keine gültige Dauer und wird übersprungen');
            $this->SetTimerInterval('StartNextZoneTimer', 100);
            return;
        }

        $this->WriteLog('Starte Kreis ' . @IRRZ_GetZoneNumber($zoneID) . ' für ' . $duration . ' Minute(n)');
        @IRRZ_StartZone($zoneID);

        $queue = $this->GetQueue();
        $earlyOffSeconds = max(0, $this->ReadPropertyInteger('PumpEarlyOffSeconds'));
        $durationSeconds = $duration * 60;

        $this->SetTimerInterval('StopPumpEarlyTimer', 0);
        if (count($queue) === 0 && $earlyOffSeconds > 0) {
            if ($earlyOffSeconds >= $durationSeconds) {
                $this->Debug('PumpEarlyOff', 'Frühabschaltung >= Kreisdauer, Pumpe wird sofort nach Kreisstart ausgeschaltet');
                $this->StopPumpEarly();
            } else {
                $pumpOffMs = ($durationSeconds - $earlyOffSeconds) * 1000;
                $this->Debug('PumpEarlyOff.TimerMs', $pumpOffMs);
                $this->SetTimerInterval('StopPumpEarlyTimer', $pumpOffMs);
            }
        }

        $this->SetTimerInterval('StopCurrentZoneTimer', $duration * 60 * 1000);
    }

    public function StopPumpEarly(): void
    {
        $this->Debug('StopPumpEarly', 'Pumpe wird vor Ende des letzten Kreises abgeschaltet');
        $this->SetTimerInterval('StopPumpEarlyTimer', 0);
        $this->SetPumpState(false);
        $this->WriteLog('Pumpe vor Ende des letzten Kreises ausgeschaltet');
    }

    public function FinishCurrentZone(): void
    {
        $this->Debug('FinishCurrentZone', 'gestartet');

        $this->SetTimerInterval('StopCurrentZoneTimer', 0);
        $this->SetTimerInterval('StopPumpEarlyTimer', 0);

        $currentZoneID = (int) $this->GetBuffer('CurrentZoneID');
        if ($currentZoneID > 0 && @IPS_InstanceExists($currentZoneID)) {
            @IRRZ_StopZone($currentZoneID);
            $this->WriteLog('Kreis ' . @IRRZ_GetZoneNumber($currentZoneID) . ' beendet');
        }

        $this->SetBuffer('CurrentZoneID', '0');

        $pauseSeconds = max(0, $this->ReadPropertyInteger('PauseBetweenZonesSeconds'));
        $this->Debug('FinishCurrentZone.PauseSeconds', $pauseSeconds);
        $this->SetTimerInterval('StartNextZoneTimer', max(100, $pauseSeconds * 1000));
    }

    private function StartQueue(array $zones, string $source): void
    {
        $this->Debug('StartQueue', ['Source' => $source, 'Zones' => $zones]);

        if ($this->GetValue('SequenceActive')) {
            $this->Debug('StartQueue', 'Sequenz läuft bereits, stoppe zuerst');
            $this->StopSequence();
        }

        if (count($zones) === 0) {
            $this->SetBuffer('Queue', json_encode([]));
            $this->SetValue('QueueCount', 0);
            $this->SetValue('SequenceActive', false);
            $this->SetPumpState(false);
            $this->WriteLog($source . ': keine Kreise zum Bewässern');
            return;
        }

        $this->SetQueue($zones);
        $this->SetValue('QueueCount', count($zones));
        $this->SetValue('SequenceActive', true);
        $this->WriteLog($source . ': Sequenz mit ' . count($zones) . ' Kreis(en) gestartet');

        $this->SetTimerInterval('StartNextZoneTimer', 100);
    }

    private function GetRunnableZones(bool $automatic): array
    {
        $result = [];
        $zones = $this->GetZones();

        foreach ($zones as $zoneID) {
            $enabled = @IRRZ_IsEnabled($zoneID);
            $number = @IRRZ_GetZoneNumber($zoneID);

            $this->Debug('GetRunnableZones.Zone', [
                'ID' => $zoneID,
                'Number' => $number,
                'Enabled' => $enabled,
                'Automatic' => $automatic
            ]);

            if (!$enabled) {
                continue;
            }

            if ($automatic) {
                $shouldWater = @IRRZ_ShouldWater($zoneID);
                $this->Debug('GetRunnableZones.ShouldWater', [
                    'ID' => $zoneID,
                    'Number' => $number,
                    'ShouldWater' => $shouldWater
                ]);

                if (!$shouldWater) {
                    continue;
                }
            }

            $result[] = $zoneID;
        }

        return $result;
    }

    private function GetZones(): array
    {
        $maxZones = max(1, min(10, $this->ReadPropertyInteger('MaxZones')));
        $children = IPS_GetChildrenIDs($this->InstanceID);
        $zones = [];

        foreach ($children as $childID) {
            if (!@IPS_InstanceExists($childID)) {
                continue;
            }

            $instance = IPS_GetInstance($childID);
            if (!isset($instance['ModuleInfo']['ModuleID'])) {
                continue;
            }

            if (strtoupper($instance['ModuleInfo']['ModuleID']) !== strtoupper(self::MODULE_ID_ZONE)) {
                continue;
            }

            $number = @IRRZ_GetZoneNumber($childID);
            if (!is_int($number) || $number < 1 || $number > $maxZones) {
                continue;
            }

            $zones[$number . '_' . $childID] = $childID;
        }

        ksort($zones, SORT_NATURAL);
        return array_values($zones);
    }

    private function GetQueue(): array
    {
        $queue = json_decode($this->GetBuffer('Queue'), true);
        if (!is_array($queue)) {
            return [];
        }

        return array_values(array_map('intval', $queue));
    }

    private function SetQueue(array $queue): void
    {
        $queue = array_values(array_map('intval', $queue));
        $this->SetBuffer('Queue', json_encode($queue));
        $this->SetValue('QueueCount', count($queue));
        $this->Debug('SetQueue', $queue);
    }

    private function SetPumpState(bool $state): void
    {
        $pumpVariable = $this->ReadPropertyInteger('PumpVariable');
        $pumpInstance = $this->ReadPropertyInteger('PumpInstance');
        $legacyPump = $this->ReadPropertyInteger('Pump');

        $this->Debug('SetPumpState', [
            'PumpVariable' => $pumpVariable,
            'PumpInstance' => $pumpInstance,
            'LegacyPump' => $legacyPump,
            'State' => $state
        ]);

        if ($pumpInstance > 0) {
            $this->SetActuatorState($pumpInstance, $state);
            $this->SetValue('PumpActive', $state);
            return;
        }

        if ($pumpVariable > 0) {
            // Kompatibilität zu V3.2/V3.3
            $this->SetActuatorState($pumpVariable, $state);
            $this->SetValue('PumpActive', $state);
            return;
        }

        if ($legacyPump > 0) {
            $this->SetActuatorState($legacyPump, $state);
            $this->SetValue('PumpActive', $state);
            return;
        }

        $this->Debug('SetPumpState', 'keine Pumpe konfiguriert');
        $this->SetValue('PumpActive', false);
    }

    private function HasPumpConfigured(): bool
    {
        return $this->ReadPropertyInteger('PumpInstance') > 0
            || $this->ReadPropertyInteger('PumpVariable') > 0
            || $this->ReadPropertyInteger('Pump') > 0;
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

    private function MaintainWeekplan(string $Ident, string $Name): void
    {
        $eventID = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
        if ($eventID === false) {
            $this->Debug('MaintainWeekplan', 'erstelle ' . $Name);
            $eventID = IPS_CreateEvent(2);
            IPS_SetParent($eventID, $this->InstanceID);
            IPS_SetIdent($eventID, $Ident);
            IPS_SetName($eventID, $Name);

            IPS_SetEventScheduleAction($eventID, 0, 'Aus', 0x808080, false);
            IPS_SetEventScheduleAction($eventID, 1, 'Ein', 0x27AE60, true);

            for ($day = 0; $day <= 6; $day++) {
                @IPS_SetEventScheduleGroup($eventID, $day, 1 << $day);
            }

            IPS_SetEventActive($eventID, false);
        }
    }

    private function UpdateWeekplanVisibility(): void
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

    private function UpdateStatus(): void
    {
        $zones = $this->GetZones();

        $this->Debug('UpdateStatus', [
            'ZoneCount' => count($zones),
            'HasPumpConfigured' => $this->HasPumpConfigured()
        ]);

        if (count($zones) === 0 || !$this->HasPumpConfigured()) {
            $this->SetStatus(200);
            return;
        }

        $this->SetStatus(102);
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('IRR.Mode')) {
            IPS_CreateVariableProfile('IRR.Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('IRR.Mode', self::MODE_MANUAL, 'Manuell', '', 0x2D8CFF);
            IPS_SetVariableProfileAssociation('IRR.Mode', self::MODE_TIME, 'Zeitsteuerung', '', 0xFFB300);
            IPS_SetVariableProfileAssociation('IRR.Mode', self::MODE_AUTO, 'Automatik', '', 0x27AE60);
        }

        if (!IPS_VariableProfileExists('IRR.Minutes')) {
            IPS_CreateVariableProfile('IRR.Minutes', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.Minutes', 0, 720, 1);
            IPS_SetVariableProfileText('IRR.Minutes', '', ' min');
        }

        if (!IPS_VariableProfileExists('IRR.Seconds')) {
            IPS_CreateVariableProfile('IRR.Seconds', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('IRR.Seconds', 0, 3600, 1);
            IPS_SetVariableProfileText('IRR.Seconds', '', ' s');
        }
    }

    private function WriteLog(string $message): void
    {
        $text = date('d.m.Y H:i:s') . ' - ' . $message;
        $this->SetValue('LastAction', $text);
        $this->SetValue('DecisionText', $message);
        IPS_LogMessage('IRR[' . $this->InstanceID . ']', $message);
        $this->Debug('WriteLog', $message);
    }

    private function Debug(string $Message, $Data = null): void
    {
        if ($Data === null) {
            $this->SendDebug('IRR', $Message, 0);
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
