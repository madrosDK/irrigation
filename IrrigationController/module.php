<?php

declare(strict_types=1);

class IrrigationController extends IPSModule
{

    private const MODULE_ID_AREA = '{C6A0D3B7-3E8B-4B74-9F1D-7E5F1D5A9A21}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('PumpLeadTimeSeconds', 5);
        $this->RegisterPropertyInteger('PumpEarlyOffSeconds', 0);
        $this->RegisterPropertyInteger('PauseBetweenZonesSeconds', 5);
        $this->RegisterPropertyInteger('MaxZones', 10);

        $this->RegisterPropertyInteger('PumpInstance', 0);
        $this->RegisterPropertyInteger('PumpVariable', 0);
        $this->RegisterPropertyInteger('Pump', 0); // Legacy

        $this->RegisterProfiles();

        $this->RegisterVariableInteger('PumpLeadTimeSeconds', 'Pumpenvorlauf', 'IRR.Seconds', 20);
        $this->EnableAction('PumpLeadTimeSeconds');

        $this->RegisterVariableInteger('PumpEarlyOffSeconds', 'Pumpe früher aus', 'IRR.Seconds', 30);
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

        $this->RegisterTimer('StartFirstAreaAfterPumpTimer', 0, 'IRR_StartFirstAreaAfterPumpLead($_IPS[\'TARGET\']);');
        $this->RegisterTimer('StartNextAreaTimer', 0, 'IRR_StartNextArea($_IPS[\'TARGET\']);');
        $this->RegisterTimer('StopPumpEarlyTimer', 0, 'IRR_StopPumpEarly($_IPS[\'TARGET\']);');

        $this->SetBuffer('AreaQueue', json_encode([]));
        $this->SetBuffer('CurrentAreaID', '0');
        $this->SetBuffer('LastActionLog', json_encode([]));

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
        $this->SetValue('PumpEarlyOffSeconds', $this->ReadPropertyInteger('PumpEarlyOffSeconds'));
        $this->SetValue('PauseBetweenZonesSeconds', $this->ReadPropertyInteger('PauseBetweenZonesSeconds'));

        $this->RefreshAreas();
        $this->UpdateStatus();
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'PumpLeadTimeSeconds':
                IPS_SetProperty($this->InstanceID, 'PumpLeadTimeSeconds', max(0, (int)$Value));
                IPS_ApplyChanges($this->InstanceID);
                break;

            case 'PumpEarlyOffSeconds':
                IPS_SetProperty($this->InstanceID, 'PumpEarlyOffSeconds', max(0, (int)$Value));
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

        $currentAreaID = (int)$this->GetBuffer('CurrentAreaID');
        if ($currentAreaID > 0 && @IPS_InstanceExists($currentAreaID)) {
            @IRRA_StopArea($currentAreaID, true);
        }

        $this->SetAreaQueue([]);
        $this->SetBuffer('CurrentAreaID', '0');

        $this->SetPumpState(false);
        $this->SetValue('PumpActive', false);
        $this->SetValue('SequenceActive', false);
        $this->SetValue('CurrentArea', 0);
        $this->SetValue('QueueCount', 0);

        $this->WriteLog('Sequenz gestoppt');
    }

    public function StartFirstAreaAfterPumpLead(): void
    {
        $this->SetTimerInterval('StartFirstAreaAfterPumpTimer', 0);
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
    }

    public function FinishCurrentArea(): void
    {
        $currentAreaID = (int)$this->GetBuffer('CurrentAreaID');

        if ($currentAreaID > 0 && @IPS_InstanceExists($currentAreaID)) {
            $this->WriteLog('Zone ' . $this->GetAreaNumberSafe($currentAreaID) . ' abgeschlossen');
        }

        $this->SetBuffer('CurrentAreaID', '0');

        $queue = $this->GetAreaQueue();
        if (count($queue) === 0) {
            $this->FinishSequence();
            return;
        }

        $pauseSeconds = max(0, $this->ReadPropertyInteger('PauseBetweenZonesSeconds'));
        $this->SetTimerInterval('StartNextAreaTimer', max(100, $pauseSeconds * 1000));
    }

    public function StopPumpEarly(): void
    {
        $this->SetTimerInterval('StopPumpEarlyTimer', 0);
        $this->SetPumpState(false);
        $this->WriteLog('Pumpe vor Ende ausgeschaltet');
    }

    public function GetPumpEarlyOffSeconds(): int
    {
        return max(0, $this->ReadPropertyInteger('PumpEarlyOffSeconds'));
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

        $this->SetPumpState(true);

        $leadMs = max(0, $this->ReadPropertyInteger('PumpLeadTimeSeconds')) * 1000;
        $this->SetTimerInterval('StartFirstAreaAfterPumpTimer', max(100, $leadMs));
    }

    private function FinishSequence(): void
    {
        $this->SetTimerInterval('StartFirstAreaAfterPumpTimer', 0);
        $this->SetTimerInterval('StartNextAreaTimer', 0);
        $this->SetTimerInterval('StopPumpEarlyTimer', 0);

        $this->SetAreaQueue([]);
        $this->SetBuffer('CurrentAreaID', '0');

        $this->SetPumpState(false);
        $this->SetValue('PumpActive', false);
        $this->SetValue('SequenceActive', false);
        $this->SetValue('CurrentArea', 0);
        $this->SetValue('QueueCount', 0);

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

        if ($target <= 0) {
            $this->WriteLog('Keine Pumpen-Schaltvariable gefunden');
            $this->SetValue('PumpActive', false);
            return;
        }

        try {
            RequestAction($target, $state);
            $this->SetValue('PumpActive', $state);
            $this->Debug('SetPumpState', [
                'Target' => $target,
                'State' => $state
            ]);
        } catch (Throwable $e) {
            $this->WriteLog('Pumpe konnte nicht geschaltet werden: ' . $e->getMessage());
            $this->Debug('SetPumpState.Exception', [
                'Target' => $target,
                'State' => $state,
                'Error' => $e->getMessage()
            ]);
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
        $html = '<div style="font-family:Tahoma, Arial, sans-serif; font-size:12px; line-height:1.35; text-align:right;">';

        foreach ($entries as $entry) {
            $time = htmlspecialchars((string)($entry['time'] ?? ''), ENT_QUOTES, 'UTF-8');
            $message = htmlspecialchars((string)($entry['message'] ?? ''), ENT_QUOTES, 'UTF-8');

            $html .= '<div>';
            $html .= '<span style="color:#4da6ff; font-weight:bold;">' . $time . '</span>';
            $html .= '<span style="color:#ffffff;"> &ndash; ' . $message . '</span>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private function RenderAreaOverviewHtml(array $areas): string
    {
        $html = '<div style="font-family:Tahoma, Arial, sans-serif; font-size:12px; line-height:1.35; text-align:right;">';

        if (count($areas) === 0) {
            $html .= '<span style="color:#ffffff;">Keine Zonen vorhanden</span>';
        } else {
            foreach ($areas as $area) {
                $id = htmlspecialchars((string)($area['id'] ?? ''), ENT_QUOTES, 'UTF-8');
                $name = htmlspecialchars((string)($area['name'] ?? ''), ENT_QUOTES, 'UTF-8');

                $html .= '<div>';
                $html .= '<span style="color:#4da6ff;">' . $id . '</span> ';
                $html .= '<span style="color:#ffffff;">' . $name . '</span>';
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
