<?php

declare(strict_types=1);

class IrrigationController extends IPSModule
{

    private const MODULE_ID_AREA = '{C6A0D3B7-3E8B-4B74-9F1D-7E5F1D5A9A21}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('HtmlFontFamily', 'Tahoma');
        $this->RegisterPropertyInteger('HtmlFontSize', 12);
        $this->RegisterPropertyString('HtmlAccentColor', '#4da6ff');
        $this->RegisterPropertyString('HtmlTextColor', '#ffffff');

        $this->RegisterPropertyInteger('PumpLeadTimeSeconds', 5);
        $this->RegisterPropertyInteger('PumpEarlyOffSeconds', 0);
        $this->RegisterPropertyInteger('PauseBetweenZonesSeconds', 5);
        $this->RegisterPropertyInteger('MaxZones', 10);

        $this->RegisterPropertyInteger('PumpInstance', 0);
        $this->RegisterPropertyInteger('PumpVariable', 0);
        $this->RegisterPropertyInteger('Pump', 0); // Legacy

        $this->RegisterProfiles();

        $this->RegisterVariableInteger('PumpLeadTimeSeconds', 'Pumpenvorlauf', 'IRR.SignedSeconds', 20);
        $this->EnableAction('PumpLeadTimeSeconds');

        $this->RegisterVariableInteger('PumpEarlyOffSeconds', 'Pumpennachlauf', 'IRR.SignedSeconds', 30);
        $this->EnableAction('PumpEarlyOffSeconds');

        $this->RegisterVariableInteger('PauseBetweenZonesSeconds', 'Pause zwischen Zonen', 'IRR.Seconds', 40);
        $this->EnableAction('PauseBetweenZonesSeconds');

        $this->RegisterVariableBoolean('SequenceActive', 'Sequenz aktiv', '~Switch', 50);
        $this->EnableAction('SequenceActive');

        $this->RegisterVariableBoolean('PumpActive', 'Pumpe aktiv', '~Switch', 60);
        $this->RegisterVariableInteger('CurrentArea', 'Aktuelle Zone', '', 70);
        $this->RegisterVariableInteger('QueueCount', 'Wartende Zonen', '', 80);
        $this->RegisterVariableString('DecisionText', 'Sequenzstatus', '', 90);
        $this->RegisterVariableString('LastAction', 'Letzte 10 Aktionen', '~HTMLBox', 100);
        $this->RegisterVariableString('ZoneOverview', 'Zonenübersicht', '~HTMLBox', 110);

        $this->RegisterVariableString('EstimatedEnd', 'Voraussichtliches Ende', '~HTMLBox', 120);
        $this->RegisterVariableString('LastDurations', 'Letzte 10 Beregnungsdauern', '~HTMLBox', 130);

        $this->RegisterTimer('StartFirstAreaAfterPumpTimer', 0, 'IRR_StartFirstAreaAfterPumpLead($_IPS[\'TARGET\']);');
        $this->RegisterTimer('StartNextAreaTimer', 0, 'IRR_StartNextArea($_IPS[\'TARGET\']);');
        $this->RegisterTimer('StopPumpEarlyTimer', 0, 'IRR_StopPumpEarly($_IPS[\'TARGET\']);');
        $this->RegisterTimer('PumpAfterrunTimer', 0, 'IRR_StopPumpEarly($_IPS[\'TARGET\']);');
        $this->RegisterTimer('PumpDelayedStartTimer', 0, 'IRR_StartPumpDelayed($_IPS[\'TARGET\']);');
        $this->RegisterTimer('PumpVerifyTimer', 0, 'IRR_VerifyPumpStart($_IPS[\'TARGET\']);');

        $this->RegisterTimer('StructureRefreshTimer', 0, 'IRR_RefreshStructureAfterUpdate($_IPS[\'TARGET\']);');

        $this->SetBuffer('AreaQueue', json_encode([]));
        $this->SetBuffer('CurrentAreaID', '0');
        $this->SetBuffer('LastActionLog', json_encode([]));

        $this->SetBuffer('SequenceStartTime', '0');
        $this->SetBuffer('PlannedEndTime', '0');
        $this->SetBuffer('LastDurationsLog', json_encode([]));

        $this->Debug('Create', 'Master v5 initialisiert');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $maxZones = $this->ReadPropertyInteger('MaxZones');
        if ($maxZones < 1 || $maxZones > 10) {
            IPS_SetProperty($this->InstanceID, 'MaxZones', max(1, min(10, $maxZones)));
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $this->SetValue('PumpLeadTimeSeconds', $this->ReadPropertyInteger('PumpLeadTimeSeconds'));
        // Einmalige Migration: bisher positive Sekunden = frueher AUS.
        if ($this->GetBuffer('PumpAfterrunMigrated') !== '1') {
            $this->SetBuffer('PumpAfterrunMigrated', '1');
            $old = $this->ReadPropertyInteger('PumpEarlyOffSeconds');
            if ($old > 0) {
                IPS_SetProperty($this->InstanceID, 'PumpEarlyOffSeconds', -$old);
                IPS_ApplyChanges($this->InstanceID);
                return;
            }
        }
        // Bestehende Variablen behalten ihren alten Namen nach RegisterVariableInteger().
        // Deshalb die Bezeichnung auch bei vorhandenen Installationen aktualisieren.
        $pumpAfterrunID = @$this->GetIDForIdent('PumpEarlyOffSeconds');
        if ($pumpAfterrunID !== false && @IPS_VariableExists($pumpAfterrunID)) {
            IPS_SetName($pumpAfterrunID, 'Pumpennachlauf');
        }
        $this->SetValue('PumpEarlyOffSeconds', $this->ReadPropertyInteger('PumpEarlyOffSeconds'));
        $this->SetValue('PauseBetweenZonesSeconds', $this->ReadPropertyInteger('PauseBetweenZonesSeconds'));

        $this->RefreshAreas();
        $this->UpdateStatus();
        // Nach Modulupdates sind Kindmodule unter Umstaenden noch nicht geladen.
        $this->SetBuffer('StructureRefreshAttempts', '0');
        $this->SetTimerInterval('StructureRefreshTimer', 2000);
    }

    // Nach einem Modulupdate verzögert erneut erkennen, damit die Kindmodule
    // ihre generierten Funktionen und Eigenschaften bereits bereitgestellt haben.
    public function RefreshStructureAfterUpdate(): void
    {
        $this->SetTimerInterval('StructureRefreshTimer', 0);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->SetTimerInterval('StructureRefreshTimer', 2000);
            return;
        }

        $this->RefreshAreas();
        $attempt = (int)$this->GetBuffer('StructureRefreshAttempts') + 1;
        $this->SetBuffer('StructureRefreshAttempts', (string)$attempt);

        // Maximal 5 Nachpruefungen; vorhandene Objekte werden nicht geloescht.
        if (count($this->GetAreas()) === 0 && $attempt < 5) {
            $this->SetTimerInterval('StructureRefreshTimer', 2000);
        }
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'PumpLeadTimeSeconds':
                IPS_SetProperty($this->InstanceID, 'PumpLeadTimeSeconds', max(-3600, min(3600, (int)$Value)));
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'PumpEarlyOffSeconds':
                IPS_SetProperty($this->InstanceID, 'PumpEarlyOffSeconds', max(-3600, min(3600, (int)$Value)));
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'PauseBetweenZonesSeconds':
                IPS_SetProperty($this->InstanceID, 'PauseBetweenZonesSeconds', max(0, (int)$Value));
                IPS_ApplyChanges($this->InstanceID);
                break;

                case 'SequenceActive':
                    if ((bool)$Value) {
                        $this->StartManualSequence();
                    } else {
                        $this->StopSequence();
                    }
                    break;

            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    public function CreateArea(): void
    {
        $areas = $this->GetAreas();
        $maxAreas = max(1, min(10, $this->ReadPropertyInteger('MaxZones')));

        if (count($areas) >= $maxAreas) {
            $this->WriteLog('Maximale Zonenanzahl erreicht');
            return;
        }

        $used = [];
        foreach ($areas as $areaID) {
            $number = $this->GetAreaNumberSafe($areaID);
            if ($number > 0) {
                $used[] = $number;
            }
        }

        $number = 1;
        while (in_array($number, $used, true) && $number <= $maxAreas) {
            $number++;
        }

        if ($number > $maxAreas) {
            $this->WriteLog('Keine freie Zonennummer gefunden');
            return;
        }

        try {
            $areaID = IPS_CreateInstance(self::MODULE_ID_AREA);
            IPS_SetParent($areaID, $this->InstanceID);
            IPS_SetName($areaID, 'Zone ' . $number);
            IPS_SetPosition($areaID, 900 + $number);
            IPS_SetProperty($areaID, 'AreaNumber', $number);
            IPS_ApplyChanges($areaID);
        } catch (Throwable $e) {
            $this->WriteLog('Zone konnte nicht angelegt werden: ' . $e->getMessage());
            if (isset($areaID) && @IPS_ObjectExists($areaID)) {
                @IPS_DeleteInstance($areaID);
            }
            return;
        }

        $this->RefreshAreas();
        $this->WriteLog('Zone ' . $number . ' angelegt');
    }

    public function RefreshAreas(): void
    {
        $areas = $this->GetAreas();
        $parts = [];

        foreach ($areas as $areaID) {
            $number = $this->GetAreaNumberSafe($areaID);
            if ($number > 0) {
                @IPS_SetPosition($areaID, 900 + $number);
            }

            $name = @IPS_GetName($areaID);
            $standardName = 'Zone ' . $number;

            if ($name === $standardName || $name === '') {
                $parts[] = [
                    'id' => $areaID,
                    'number' => $number,
                    'name' => 'Zone ' . $number
                ];
            } else {
                $parts[] = [
                    'id' => $areaID,
                    'number' => $number,
                    'name' => $name
                ];
            }
        }

        $this->SetValue('ZoneOverview', $this->RenderAreaOverviewHtml($parts));
        $this->SetValue('QueueCount', count($this->GetAreaQueue()));
        $this->UpdateEstimatedEnd();
        $this->UpdateStatus();

        $this->Debug('RefreshAreas', [
            'Areas' => $areas,
            'Count' => count($areas)
        ]);
    }

    public function StartManualSequence(): void
    {
        $areas = $this->GetRunnableAreas(false);
        $this->StartAreaQueue($areas, 'Sequenz');
    }

    public function StartAutomaticSequence(): void
    {
        $areas = $this->GetRunnableAreas(true);
        $this->StartAreaQueue($areas, 'Automatik');
    }

    public function StopSequence(): void
    {
        $this->SetTimerInterval('StartFirstAreaAfterPumpTimer', 0);
        $this->SetTimerInterval('StartNextAreaTimer', 0);
        $this->SetTimerInterval('StopPumpEarlyTimer', 0);
        $this->SetTimerInterval('PumpAfterrunTimer', 0);
        $this->SetTimerInterval('PumpDelayedStartTimer', 0);
        $this->SetTimerInterval('PumpVerifyTimer', 0);

        $currentAreaID = (int)$this->GetBuffer('CurrentAreaID');
        if ($currentAreaID > 0 && @IPS_InstanceExists($currentAreaID)) {
            @IRRA_StopArea($currentAreaID, true);
        }

        $this->SetAreaQueue([]);
        $this->SetBuffer('CurrentAreaID', '0');

        $this->SetPumpState(false);
        $this->SetValue('SequenceActive', false);
        $this->SetValue('CurrentArea', 0);
        $this->SetValue('QueueCount', 0);

        $this->SetBuffer('PlannedEndTime', '0');
        $this->UpdateEstimatedEnd();

        $this->WriteLog('Sequenz gestoppt');
    }

    public function StartFirstAreaAfterPumpLead(): void
    {
        $this->SetTimerInterval('StartFirstAreaAfterPumpTimer', 0);
        if ($this->ReadPropertyInteger('PumpLeadTimeSeconds') >= 0 && $this->GetBuffer('PumpStartConfirmed') !== '1') {
            $this->SetTimerInterval('StartFirstAreaAfterPumpTimer', 500);
            return;
        }
        $this->StartNextArea();
    }

    public function StartNextArea(): void
    {
        $this->SetTimerInterval('StartNextAreaTimer', 0);

        $queue = $this->GetAreaQueue();
        if (count($queue) === 0) {
            $this->FinishSequence();
            return;
        }

        $areaID = array_shift($queue);
        $this->SetAreaQueue($queue);
        $this->SetBuffer('CurrentAreaID', (string)$areaID);

        $this->RecalculatePlannedEnd();

        if (!@IPS_InstanceExists($areaID)) {
            $this->WriteLog('Zone existiert nicht mehr - überspringe');
            $this->SetTimerInterval('StartNextAreaTimer', 100);
            return;
        }

        $areaNumber = $this->GetAreaNumberSafe($areaID);
        $this->SetValue('CurrentArea', $areaNumber);
        $this->SetValue('QueueCount', count($queue));

        $this->WriteLog('Starte Zone ' . $areaNumber);
        @IRRA_StartArea($areaID, true);
        $this->SchedulePumpEarlyOff();
    }

    private function RecalculatePlannedEnd(): void
    {
        if (!$this->GetValue('SequenceActive')) {
            $this->SetBuffer('PlannedEndTime', '0');
            $this->UpdateEstimatedEnd();
            return;
        }

        $totalSeconds = 0;

        $currentAreaID = (int)$this->GetBuffer('CurrentAreaID');

        // Aktuelle Zone nur grob mitrechnen, wenn sie läuft.
        // Exakte Restzeit der laufenden Zone wäre ein weiterer Ausbauschritt.
        if ($currentAreaID > 0 && @IPS_InstanceExists($currentAreaID) && function_exists('IRRA_GetPlannedDurationSeconds')) {
            $totalSeconds += (int)@IRRA_GetPlannedDurationSeconds($currentAreaID, false);
        }

        $queue = $this->GetAreaQueue();

        foreach ($queue as $areaID) {
            if ($areaID <= 0 || !@IPS_InstanceExists($areaID)) {
                continue;
            }

            if (function_exists('IRRA_GetPlannedDurationSeconds')) {
                $duration = (int)@IRRA_GetPlannedDurationSeconds($areaID, false);
                if ($duration > 0) {
                    $totalSeconds += $duration;
                }
            }
        }

        // Pausen zwischen wartenden Zonen
        $areaCount = count($queue);
        if ($currentAreaID > 0) {
            $areaCount++;
        }

        if ($areaCount > 1) {
            $totalSeconds += ($areaCount - 1) * max(0, $this->ReadPropertyInteger('PauseBetweenZonesSeconds'));
        }

        if ($totalSeconds <= 0) {
            $this->SetBuffer('PlannedEndTime', '0');
        } else {
            $this->SetBuffer('PlannedEndTime', (string)(time() + $totalSeconds));
        }

        $this->UpdateEstimatedEnd();
    }

    public function FinishCurrentArea(): void
    {
        $currentAreaID = (int)$this->GetBuffer('CurrentAreaID');

        if ($currentAreaID > 0 && @IPS_InstanceExists($currentAreaID)) {
            $this->WriteLog('Zone ' . $this->GetAreaNumberSafe($currentAreaID) . ' abgeschlossen');
        }

        $this->SetBuffer('CurrentAreaID', '0');
        $this->RecalculatePlannedEnd();

        $queue = $this->GetAreaQueue();
        if (count($queue) === 0) {
            $this->FinishSequence();
            return;
        }

        $pauseSeconds = max(0, $this->ReadPropertyInteger('PauseBetweenZonesSeconds'));
        $this->SetTimerInterval('StartNextAreaTimer', max(100, $pauseSeconds * 1000));
    }

    private function SchedulePumpEarlyOff(): void
    {
        $this->SetTimerInterval('StopPumpEarlyTimer', 0);
        $early = max(0, -$this->ReadPropertyInteger('PumpEarlyOffSeconds'));
        if ($early === 0 || count($this->GetAreaQueue()) > 0) {
            return;
        }
        $areaID = (int)$this->GetBuffer('CurrentAreaID');
        if ($areaID <= 0 || !function_exists('IRRA_GetPlannedDurationSeconds')) {
            return;
        }
        $seconds = (int)@IRRA_GetPlannedDurationSeconds($areaID, false);
        if ($seconds > 0) {
            $this->SetTimerInterval('StopPumpEarlyTimer', max(100, ($seconds - $early) * 1000));
        }
    }

    public function StopPumpEarly(): void
    {
        $this->SetTimerInterval('StopPumpEarlyTimer', 0);
        $this->SetPumpState(false);
        $this->WriteLog('Pumpe ausgeschaltet (Nachlauf / Vorabschaltung)');
    }

    public function GetPumpEarlyOffSeconds(): int
    {
        return max(0, -$this->ReadPropertyInteger('PumpEarlyOffSeconds'));
    }

    public function StartPumpFromZone(): void
    {
        $this->SetPumpState(true);
    }

    public function StopPumpFromZone(): void
    {
        $this->SetPumpState(false);
    }

    private function StartAreaQueue(array $areas, string $source): void
    {
        if ($this->GetValue('SequenceActive')) {
            $this->WriteLog('Sequenz läuft bereits - Start ignoriert');
            return;
        }

        if (count($areas) === 0) {
            $this->SetAreaQueue([]);
            $this->SetValue('QueueCount', 0);
            $this->SetValue('SequenceActive', false);
            $this->WriteLog($source . ': keine Zonen zum Bewässern');
            return;
        }

        if (!$this->HasPumpConfigured()) {
            $this->WriteLog($source . ': keine Pumpe konfiguriert');
            $this->UpdateStatus();
            return;
        }

        $this->SetAreaQueue($areas);
        $this->SetBuffer('CurrentAreaID', '0');
        $this->SetValue('QueueCount', count($areas));
        $this->SetValue('CurrentArea', 0);
        $this->SetValue('SequenceActive', true);

        $this->WriteLog($source . ' mit ' . count($areas) . ' Zone(n) gestartet');

        $this->StartPumpWithSignedLead();
    }

    private function StartPumpWithSignedLead(): void
    {
        $this->SetTimerInterval('PumpAfterrunTimer', 0);
        $this->SetTimerInterval('PumpDelayedStartTimer', 0);
        $this->SetTimerInterval('PumpVerifyTimer', 0);
        $lead = $this->ReadPropertyInteger('PumpLeadTimeSeconds');
        if ($lead >= 0) {
            $this->SetPumpState(true);
            $this->SetTimerInterval('StartFirstAreaAfterPumpTimer', max(100, $lead * 1000));
        } else {
            // Negativ: erste Zone sofort starten, Pumpe erst |lead| Sekunden später.
            $this->SetTimerInterval('StartFirstAreaAfterPumpTimer', 100);
            $this->SetTimerInterval('PumpDelayedStartTimer', -$lead * 1000);
        }
    }

    public function StartPumpDelayed(): void
    {
        $this->SetTimerInterval('PumpDelayedStartTimer', 0);
        if ($this->GetValue('SequenceActive')) {
            $this->SetPumpState(true);
        }
    }

    private function FinishSequence(): void
    {
        $this->SetTimerInterval('PumpVerifyTimer', 0);
        $this->SetTimerInterval('StartFirstAreaAfterPumpTimer', 0);
        $this->SetTimerInterval('StartNextAreaTimer', 0);
        $this->SetTimerInterval('StopPumpEarlyTimer', 0);
        $this->SetTimerInterval('PumpDelayedStartTimer', 0);

        $this->SetAreaQueue([]);
        $this->SetBuffer('CurrentAreaID', '0');

        $afterrun = max(0, $this->ReadPropertyInteger('PumpEarlyOffSeconds'));
        if ($afterrun > 0) {
            $this->SetTimerInterval('PumpAfterrunTimer', $afterrun * 1000);
        } else {
            $this->SetPumpState(false);
        }
        $this->SetValue('SequenceActive', false);
        $this->SetValue('CurrentArea', 0);
        $this->SetValue('QueueCount', 0);

        $this->StoreCompletedDuration();

        $this->WriteLog('Sequenz abgeschlossen');
    }

    private function GetRunnableAreas(bool $automatic): array
    {
        $result = [];

        foreach ($this->GetAreas() as $areaID) {
            if (function_exists('IRRA_IsEnabled')) {
                if (!@IRRA_IsEnabled($areaID)) {
                    continue;
                }
            }

            $result[] = $areaID;
        }

        return $result;
    }

    private function GetAreas(): array
    {
        $maxAreas = max(1, min(10, $this->ReadPropertyInteger('MaxZones')));
        $areas = [];

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            if (!@IPS_InstanceExists($childID)) {
                continue;
            }

            $instance = IPS_GetInstance($childID);
            if (strtoupper($instance['ModuleInfo']['ModuleID'] ?? '') !== strtoupper(self::MODULE_ID_AREA)) {
                continue;
            }

            $number = $this->GetAreaNumberSafe($childID);
            if ($number < 1 || $number > $maxAreas) {
                continue;
            }

            $areas[$number . '_' . $childID] = $childID;
        }

        ksort($areas, SORT_NATURAL);
        return array_values($areas);
    }

    private function GetAreaNumberSafe(int $areaID): int
    {
        try {
            if (function_exists('IRRA_GetAreaNumber')) {
                $number = @IRRA_GetAreaNumber($areaID);
                if (is_int($number)) {
                    return $number;
                }
            }

            if (@IPS_InstanceExists($areaID)) {
                $number = @IPS_GetProperty($areaID, 'AreaNumber');
                if (is_numeric($number)) {
                    return (int)$number;
                }
            }
        } catch (Throwable $e) {
            $this->Debug('GetAreaNumberSafe.Exception', [
                'AreaID' => $areaID,
                'Error' => $e->getMessage()
            ]);
        }

        return 0;
    }

    private function GetAreaQueue(): array
    {
        $queue = json_decode($this->GetBuffer('AreaQueue'), true);
        if (!is_array($queue)) {
            return [];
        }

        return array_values(array_map('intval', $queue));
    }

    private function SetAreaQueue(array $queue): void
    {
        $queue = array_values(array_map('intval', $queue));
        $this->SetBuffer('AreaQueue', json_encode($queue));
        $this->SetValue('QueueCount', count($queue));
        $this->RecalculatePlannedEnd();
    }

    private function UpdateStatus(): void
    {
        $areas = $this->GetAreas();

        if (count($areas) === 0) {
            $this->SetStatus(200);
            $this->SetValue('DecisionText', 'Keine Zonen vorhanden');
            return;
        }

        if (!$this->HasPumpConfigured()) {
            $this->SetStatus(200);
            $this->SetValue('DecisionText', 'Keine Pumpe konfiguriert');
            return;
        }

        $this->SetStatus(102);
        $this->SetValue('DecisionText', 'Master bereit');
    }

    private function HasPumpConfigured(): bool
    {
        $direct = $this->ReadPropertyInteger('PumpVariable');
        if ($direct > 0 && @IPS_VariableExists($direct)) {
            return true;
        }

        $instance = $this->ReadPropertyInteger('PumpInstance');
        if ($instance > 0 && @IPS_InstanceExists($instance)) {
            return $this->FindSwitchVariable($instance) > 0;
        }

        $legacy = $this->ReadPropertyInteger('Pump');
        if ($legacy > 0) {
            if (@IPS_VariableExists($legacy)) {
                return true;
            }

            if (@IPS_InstanceExists($legacy)) {
                return $this->FindSwitchVariable($legacy) > 0;
            }
        }

        return false;
    }

    private function SetPumpState(bool $state): void
    {
        $target = $this->GetPumpSwitchTarget();
        if ($target <= 0 || !@IPS_VariableExists($target)) {
            $this->WriteLog('Keine Pumpen-Schaltvariable gefunden');
            $this->SetValue('PumpActive', false);
            if ($state) {
                $this->AbortSequenceOnPumpFailure('Pumpenaktor nicht verfügbar');
            }
            return;
        }

        // Ein Timeout bedeutet bei xComfort nicht, dass der Aktor nicht geschaltet hat.
        // Deshalb niemals automatisch denselben Schaltbefehl wiederholen.
        try {
            RequestAction($target, $state);
        } catch (Throwable $e) {
            $this->WriteLog('Pumpenaktor ' . $target . ': Schaltantwort fehlt: ' . $e->getMessage());
        }

        // Rückmeldung aus der vorhandenen Statusvariable lesen, ohne neuen Befehl.
        $actual = (bool)@GetValue($target);
        $this->SetValue('PumpActive', $actual);
        if ($state) {
            // Auch nach scheinbar erfolgreichem RequestAction den Ist-Zustand prüfen.
            // Die Rückmeldung kann zeitverzögert eintreffen.
            $this->SetBuffer('PumpStartConfirmed', '0');
            $this->SetTimerInterval('PumpVerifyTimer', 2000);
            if (!$actual) {
                $this->WriteLog('Pumpeneinschaltung: Rückmeldung wird geprüft');
            }
        } else {
            $this->SetTimerInterval('PumpVerifyTimer', 0);
            if ($actual) {
                $this->WriteLog('Pumpenausschaltung noch nicht bestätigt (Aktor ' . $target . ')');
            }
        }
    }

    public function VerifyPumpStart(): void
    {
        $this->SetTimerInterval('PumpVerifyTimer', 0);
        if (!$this->GetValue('SequenceActive')) {
            return;
        }
        $target = $this->GetPumpSwitchTarget();
        if ($target > 0 && @IPS_VariableExists($target) && (bool)@GetValue($target)) {
            $this->SetValue('PumpActive', true);
            $this->SetBuffer('PumpStartConfirmed', '1');
            $this->WriteLog('Pumpeneinschaltung bestätigt (Aktor ' . $target . ')');
            return;
        }
        $this->AbortSequenceOnPumpFailure('Pumpeneinschaltung nicht bestätigt (Aktor ' . $target . ')');
    }

    private function AbortSequenceOnPumpFailure(string $reason): void
    {
        $this->WriteLog($reason . ' – Beregnung wird gestoppt');
        if ($this->GetValue('SequenceActive')) {
            $this->StopSequence();
        }
    }

    private function GetPumpSwitchTarget(): int
    {
        $direct = $this->ReadPropertyInteger('PumpVariable');
        if ($direct > 0 && @IPS_VariableExists($direct)) {
            return $direct;
        }

        $instance = $this->ReadPropertyInteger('PumpInstance');
        if ($instance > 0 && @IPS_InstanceExists($instance)) {
            $found = $this->FindSwitchVariable($instance);
            if ($found > 0) {
                return $found;
            }
        }

        $legacy = $this->ReadPropertyInteger('Pump');
        if ($legacy > 0) {
            if (@IPS_VariableExists($legacy)) {
                return $legacy;
            }

            if (@IPS_InstanceExists($legacy)) {
                $found = $this->FindSwitchVariable($legacy);
                if ($found > 0) {
                    return $found;
                }
            }
        }

        return 0;
    }

    private function FindSwitchVariable(int $instanceID): int
    {
        $bestID = 0;
        $bestScore = -1;

        foreach (IPS_GetChildrenIDs($instanceID) as $childID) {
            if (!@IPS_VariableExists($childID)) {
                continue;
            }

            $var = IPS_GetVariable($childID);
            if (($var['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN) {
                continue;
            }

            if (($var['VariableAction'] ?? 0) <= 0) {
                continue;
            }

            $name = strtolower(IPS_GetName($childID));
            $ident = strtolower($var['VariableIdent'] ?? '');

            if (
                str_contains($name, 'battery') ||
                str_contains($name, 'batterie') ||
                str_contains($name, 'online') ||
                str_contains($name, 'error') ||
                str_contains($name, 'fehler') ||
                str_contains($ident, 'battery') ||
                str_contains($ident, 'batterie') ||
                str_contains($ident, 'online') ||
                str_contains($ident, 'error') ||
                str_contains($ident, 'fehler')
            ) {
                continue;
            }

            $score = 0;

            foreach (['state', 'status', 'switch', 'output', 'relay', 'power', 'onoff', 'ein'] as $keyword) {
                if (str_contains($name, $keyword) || str_contains($ident, $keyword)) {
                    $score += 10;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestID = $childID;
            }
        }

        return $bestID;
    }

    private function StoreCompletedDuration(): void
    {
        $start = (int)$this->GetBuffer('SequenceStartTime');
        if ($start <= 0) {
            return;
        }

        $minutes = (int)ceil((time() - $start) / 60);
        if ($minutes <= 0) {
            $minutes = 1;
        }

        $entries = [];
        $buffer = $this->GetBuffer('LastDurationsLog');
        if (is_string($buffer) && trim($buffer) !== '') {
            $decoded = json_decode($buffer, true);
            if (is_array($decoded)) {
                $entries = $decoded;
            }
        }

        array_unshift($entries, [
            'time' => date('d.m.Y H:i:s'),
            'minutes' => $minutes
        ]);

        $entries = array_slice($entries, 0, 10);

        $this->SetBuffer('LastDurationsLog', json_encode($entries));
        $this->SetValue('LastDurations', $this->RenderDurationsHtml($entries));

        $this->SetBuffer('SequenceStartTime', '0');
        $this->SetBuffer('PlannedEndTime', '0');
        $this->UpdateEstimatedEnd();
    }

    private function RenderDurationsHtml(array $entries): string
    {
        $fontFamily  = htmlspecialchars($this->ReadPropertyString('HtmlFontFamily'), ENT_QUOTES, 'UTF-8');
        $fontSize    = max(8, min(40, $this->ReadPropertyInteger('HtmlFontSize')));
        $accentColor = htmlspecialchars($this->ReadPropertyString('HtmlAccentColor'), ENT_QUOTES, 'UTF-8');
        $textColor   = htmlspecialchars($this->ReadPropertyString('HtmlTextColor'), ENT_QUOTES, 'UTF-8');

        $html = '<div style="font-family:' . $fontFamily . ', Arial, sans-serif; font-size:' . $fontSize . 'px; line-height:1.35; text-align:right;">';

        if (count($entries) === 0) {
            $html .= '<span style="color:' . $textColor . ';">Noch keine Beregnungsdauer erfasst</span>';
        } else {
            foreach ($entries as $entry) {
                $time = htmlspecialchars((string)($entry['time'] ?? ''), ENT_QUOTES, 'UTF-8');
                $minutes = (int)($entry['minutes'] ?? 0);

                $html .= '<div>';
                $html .= '<span style="color:' . $accentColor . '; font-weight:bold;">' . $time . '</span>';
                $html .= '<span style="color:' . $textColor . ';"> &ndash; ' . $minutes . ' min</span>';
                $html .= '</div>';
            }
        }

        $html .= '</div>';
        return $html;
    }

    public function QueueArea(int $areaID, bool $automatic = false): void
    {
        if ($areaID <= 0 || !@IPS_InstanceExists($areaID)) {
            $this->WriteLog('Zone konnte nicht eingereiht werden: ungültige ID');
            return;
        }

        if (function_exists('IRRA_IsEnabled') && !@IRRA_IsEnabled($areaID)) {
            $this->WriteLog('Zone ' . $this->GetAreaNumberSafe($areaID) . ' ist deaktiviert - nicht eingereiht');
            return;
        }

        $durationSeconds = 0;
        if (function_exists('IRRA_GetPlannedDurationSeconds')) {
            $durationSeconds = (int)@IRRA_GetPlannedDurationSeconds($areaID, $automatic);
        }

        if ($durationSeconds <= 0) {
            $this->WriteLog('Zone ' . $this->GetAreaNumberSafe($areaID) . ' hat keine berechenbare Laufzeit - nicht eingereiht');
            return;
        }

        $queue = $this->GetAreaQueue();

        $currentAreaID = (int)$this->GetBuffer('CurrentAreaID');
        if ($currentAreaID === $areaID || in_array($areaID, $queue, true)) {
            $this->WriteLog('Zone ' . $this->GetAreaNumberSafe($areaID) . ' ist bereits in Ausführung oder Warteschlange');
            return;
        }

        if (!$this->GetValue('SequenceActive')) {
            $this->SetBuffer('SequenceStartTime', (string)time());
            $this->SetBuffer('PlannedEndTime', (string)(time() + max(0, $this->ReadPropertyInteger('PumpLeadTimeSeconds')) + $durationSeconds));
            $this->SetAreaQueue([$areaID]);
            $this->SetValue('SequenceActive', true);
            $this->SetValue('CurrentArea', 0);

            $this->WriteLog('Zone ' . $this->GetAreaNumberSafe($areaID) . ' eingereiht und Sequenz gestartet');
            $this->UpdateEstimatedEnd();

            $this->StartPumpWithSignedLead();
            return;
        }

        $queue[] = $areaID;
        $this->SetTimerInterval('StopPumpEarlyTimer', 0);
        $this->SetAreaQueue($queue);

        $this->RecalculatePlannedEnd();

        $this->WriteLog('Zone ' . $this->GetAreaNumberSafe($areaID) . ' hinten angehängt');
        $this->UpdateEstimatedEnd();
    }

    private function UpdateEstimatedEnd(): void
    {
        $plannedEnd = (int)$this->GetBuffer('PlannedEndTime');

        $fontFamily  = htmlspecialchars($this->ReadPropertyString('HtmlFontFamily'), ENT_QUOTES, 'UTF-8');
        $fontSize    = max(8, min(40, $this->ReadPropertyInteger('HtmlFontSize')));
        $accentColor = htmlspecialchars($this->ReadPropertyString('HtmlAccentColor'), ENT_QUOTES, 'UTF-8');
        $textColor   = htmlspecialchars($this->ReadPropertyString('HtmlTextColor'), ENT_QUOTES, 'UTF-8');

        if ($plannedEnd <= 0 || !$this->GetValue('SequenceActive')) {
            $html = '<div style="font-family:' . $fontFamily . ', Arial, sans-serif; font-size:' . $fontSize . 'px; text-align:right;">';
            $html .= '<span style="color:' . $textColor . ';">Keine aktive Planung</span>';
            $html .= '</div>';
            $this->SetValue('EstimatedEnd', $html);
            return;
        }

        $remaining = max(0, $plannedEnd - time());
        $minutes = (int)ceil($remaining / 60);

        $html = '<div style="font-family:' . $fontFamily . ', Arial, sans-serif; font-size:' . $fontSize . 'px; text-align:right;">';
        $html .= '<span style="color:' . $accentColor . '; font-weight:bold;">Ende ca. ' . date('H:i', $plannedEnd) . '</span>';
        $html .= '<span style="color:' . $textColor . ';"> | Rest ca. ' . $minutes . ' min</span>';
        $html .= '</div>';

        $this->SetValue('EstimatedEnd', $html);
    }

    public function AddActionLog(string $message): void
    {
        $this->WriteLog($message);
    }

    public function GetHtmlStyle(): string
    {
        return json_encode([
            'fontFamily' => $this->ReadPropertyString('HtmlFontFamily'),
            'fontSize'   => $this->ReadPropertyInteger('HtmlFontSize'),
            'accentColor'=> $this->ReadPropertyString('HtmlAccentColor'),
            'textColor'  => $this->ReadPropertyString('HtmlTextColor')
        ]);
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
            'time' => date('d.m.Y H:i:s'),
            'message' => $message
        ]);

        $entries = array_slice($entries, 0, 10);

        $this->SetBuffer('LastActionLog', json_encode($entries));
        $this->SetValue('LastAction', $this->RenderLastActionHtml($entries));
        $this->SetValue('DecisionText', $message);

        IPS_LogMessage('IRR[' . $this->InstanceID . ']', $message);
    }

    private function RenderLastActionHtml(array $entries): string
    {
        $fontFamily  = htmlspecialchars($this->ReadPropertyString('HtmlFontFamily'), ENT_QUOTES, 'UTF-8');
        $fontSize    = max(8, min(40, $this->ReadPropertyInteger('HtmlFontSize')));
        $accentColor = htmlspecialchars($this->ReadPropertyString('HtmlAccentColor'), ENT_QUOTES, 'UTF-8');
        $textColor   = htmlspecialchars($this->ReadPropertyString('HtmlTextColor'), ENT_QUOTES, 'UTF-8');

        $html = '<div style="font-family:' . $fontFamily . ', Arial, sans-serif; font-size:' . $fontSize . 'px; line-height:1.35; text-align:right;">';

        foreach ($entries as $entry) {
            $time = htmlspecialchars((string)($entry['time'] ?? ''), ENT_QUOTES, 'UTF-8');
            $message = htmlspecialchars((string)($entry['message'] ?? ''), ENT_QUOTES, 'UTF-8');

            $html .= '<div>';
            $html .= '<span style="color:' . $accentColor . '; font-weight:bold;">' . $time . '</span>';
            $html .= '<span style="color:' . $textColor . ';"> &ndash; ' . $message . '</span>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private function RenderAreaOverviewHtml(array $areas): string
    {
        $fontFamily  = htmlspecialchars($this->ReadPropertyString('HtmlFontFamily'), ENT_QUOTES, 'UTF-8');
        $fontSize    = max(8, min(40, $this->ReadPropertyInteger('HtmlFontSize')));
        $accentColor = htmlspecialchars($this->ReadPropertyString('HtmlAccentColor'), ENT_QUOTES, 'UTF-8');
        $textColor   = htmlspecialchars($this->ReadPropertyString('HtmlTextColor'), ENT_QUOTES, 'UTF-8');

        $html = '<div style="font-family:' . $fontFamily . ', Arial, sans-serif; font-size:' . $fontSize . 'px; line-height:1.35; text-align:right;">';

        if (count($areas) === 0) {
            $html .= '<span style="color:' . $textColor . ';">Keine Zonen vorhanden</span>';
        } else {
            foreach ($areas as $area) {
                $id = htmlspecialchars((string)($area['id'] ?? ''), ENT_QUOTES, 'UTF-8');
                $name = htmlspecialchars((string)($area['name'] ?? ''), ENT_QUOTES, 'UTF-8');

                $html .= '<div>';
                $html .= '<span style="color:' . $accentColor . ';">ID ' . $id . '</span>';
                $html .= '<span style="color:' . $textColor . ';"> | ' . $name . '</span>';
                $html .= '</div>';
            }
        }

        $html .= '</div>';
        return $html;
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('IRR.Mode')) {
            IPS_CreateVariableProfile('IRR.Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('IRR.Mode', 1, 'Manuell', '', -1);
            IPS_SetVariableProfileAssociation('IRR.Mode', 2, 'Zeitsteuerung', '', -1);
            IPS_SetVariableProfileAssociation('IRR.Mode', 3, 'Automatik', '', -1);
        }

        if (!IPS_VariableProfileExists('IRR.SignedSeconds')) {
            IPS_CreateVariableProfile('IRR.SignedSeconds', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('IRR.SignedSeconds', '', ' s');
            IPS_SetVariableProfileValues('IRR.SignedSeconds', -3600, 3600, 1);
        }
        if (!IPS_VariableProfileExists('IRR.Seconds')) {
            IPS_CreateVariableProfile('IRR.Seconds', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('IRR.Seconds', '', ' s');
            IPS_SetVariableProfileValues('IRR.Seconds', 0, 3600, 1);
        }
    }

    private function Debug(string $message, $data = null): void
    {
        if ($data === null) {
            $this->SendDebug($message, '', 0);
            return;
        }

        if (is_scalar($data)) {
            $this->SendDebug($message, (string)$data, 0);
            return;
        }

        $this->SendDebug($message, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
    }
}
