<?php

declare(strict_types=1);

class IrrigationArea extends IPSModule
{
    private const MODE_MANUAL = 1;
    private const MODE_TIME = 2;
    private const MODE_AUTO = 3;
    private const MODULE_ID_ZONE = '{B69A3F87-2E64-4AA0-B67E-7D84587B8A11}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Enabled', true);
        $this->RegisterPropertyInteger('AreaNumber', 1);
        $this->RegisterPropertyInteger('Mode', self::MODE_MANUAL);
        $this->RegisterPropertyInteger('PauseBetweenZonesSeconds', 5);
        $this->RegisterPropertyInteger('MaxZones', 10);

        $this->RegisterProfiles();

        $this->RegisterVariableBoolean('Enabled', 'Zone aktiv', '~Switch', 10);
        $this->EnableAction('Enabled');
        $this->RegisterVariableInteger('AreaNumber', 'Zonennummer', '', 20);
        $this->RegisterVariableInteger('Mode', 'Betriebsmodus', 'IRR.Mode', 30);
        $this->EnableAction('Mode');
        $this->RegisterVariableInteger('PauseBetweenZonesSeconds', 'Pause zwischen Kreisen', 'IRR.Seconds', 40);
        $this->EnableAction('PauseBetweenZonesSeconds');
        $this->RegisterVariableBoolean('AreaActive', 'Zone läuft', '~Switch', 50);
        $this->EnableAction('AreaActive');
        $this->RegisterVariableInteger('CurrentZone', 'Aktueller Kreis', '', 60);
        $this->RegisterVariableInteger('QueueCount', 'Wartende Kreise', '', 70);
        $this->RegisterVariableString('DecisionText', 'Zonenstatus', '', 80);
        $this->RegisterVariableString('LastAction', 'Letzte 10 Aktionen', '~HTMLBox', 90);
        $this->RegisterVariableString('ZoneOverview', 'Kreisübersicht', '~HTMLBox', 100);

        $this->RegisterTimer('StopCurrentZoneTimer', 0, 'IRRA_FinishCurrentZone($_IPS[\'TARGET\']);');
        $this->RegisterTimer('StartNextZoneTimer', 0, 'IRRA_StartNextZone($_IPS[\'TARGET\']);');

        $this->SetBuffer('Queue', json_encode([]));
        $this->SetBuffer('CurrentZoneID', '0');
        $this->SetBuffer('LastActionLog', json_encode([]));
        $this->SetBuffer('StartedFromMaster', '0');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $mode = $this->ReadPropertyInteger('Mode');
        if (!in_array($mode, [self::MODE_MANUAL, self::MODE_TIME, self::MODE_AUTO], true)) {
            IPS_SetProperty($this->InstanceID, 'Mode', self::MODE_MANUAL);
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $this->SetValue('Enabled', $this->ReadPropertyBoolean('Enabled'));
        $this->SetValue('AreaNumber', $this->ReadPropertyInteger('AreaNumber'));
        $this->SetValue('Mode', $mode);
        $this->SetValue('PauseBetweenZonesSeconds', $this->ReadPropertyInteger('PauseBetweenZonesSeconds'));

        $this->MaintainWeekplan('ScheduleTimer', 'Zeitsteuerung');
        $this->MaintainWeekplan('ScheduleAuto', 'Automatik');
        $this->UpdateWeekplanVisibility();

        $this->RefreshZones();
        $this->UpdateStatus();
        $lastActionID = @$this->GetIDForIdent('LastAction');
        if ($lastActionID !== false) {
            IPS_SetHidden($lastActionID, true);
        }
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Enabled':
                IPS_SetProperty($this->InstanceID, 'Enabled', (bool)$Value);
                IPS_ApplyChanges($this->InstanceID);
                break;
            case 'Mode':
                $mode = (int)$Value;
                if (!in_array($mode, [self::MODE_MANUAL, self::MODE_TIME, self::MODE_AUTO], true)) {
                    $mode = self::MODE_MANUAL;
                }
                IPS_SetProperty($this->InstanceID, 'Mode', $mode);
                IPS_ApplyChanges($this->InstanceID);
                break;
            case 'PauseBetweenZonesSeconds':
                IPS_SetProperty($this->InstanceID, 'PauseBetweenZonesSeconds', max(0, (int)$Value));
                IPS_ApplyChanges($this->InstanceID);
                break;
            case 'AreaActive':
                if ((bool)$Value) {
                    if ($this->IsAnotherAreaOrZoneActiveGlobally()) {
                        $this->WriteLog('Start blockiert: Eine andere Zone oder ein anderer Kreis läuft bereits');
                        $this->SetValue('AreaActive', false);
                        return;
                    }

                    $this->StartArea(false);
                } else {
                    $this->StopArea(false);
                }
                break;
            case 'CreateZone':
                $this->CreateZone();
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    public function CreateZone(): void
    {
        $zones = $this->GetZones();
        $maxZones = max(1, min(10, $this->ReadPropertyInteger('MaxZones')));
        if (count($zones) >= $maxZones) {
            $this->WriteLog('Maximale Kreisanzahl erreicht');
            return;
        }
        $used = [];
        foreach ($zones as $zoneID) {
            $number = $this->GetZoneNumberSafe($zoneID);
            if ($number > 0) {
                $used[] = $number;
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
        $zoneID = IPS_CreateInstance(self::MODULE_ID_ZONE);
        IPS_SetParent($zoneID, $this->InstanceID);
        IPS_SetName($zoneID, 'Kreis ' . $number);
        IPS_SetPosition($zoneID, 1000 + $number);
        IPS_SetProperty($zoneID, 'ZoneNumber', $number);
        IPS_ApplyChanges($zoneID);
        $this->RefreshZones();
        $this->WriteLog('Kreis ' . $number . ' angelegt');
    }

    public function RefreshZones(): void
    {
        $zones = $this->GetZones();
        $parts = [];
        foreach ($zones as $zoneID) {
            $number = $this->GetZoneNumberSafe($zoneID);
            if ($number > 0) {
                @IPS_SetPosition($zoneID, 1000 + $number);
            }
            $name = @IPS_GetName($zoneID);
            $standardName = 'Kreis ' . $number;
            $parts[] = ($name === $standardName || $name === '') ? 'Kreis ' . $number . ' (#' . $zoneID . ')' : 'Kreis ' . $number . ' - ' . $name . ' (#' . $zoneID . ')';
        }
        $this->SetValue('ZoneOverview', $this->RenderOverviewHtml($parts, 'Keine Kreise in dieser Zone gefunden'));
        $this->SetValue('QueueCount', count($this->GetQueue()));
        $this->UpdateStatus();
    }

    public function StartArea(bool $FromMaster = false): void
    {
      if (!$FromMaster && $this->IsAnotherAreaOrZoneActiveGlobally()) {
            $this->WriteLog('Start blockiert: Eine andere Zone oder ein anderer Kreis läuft bereits');
            $this->SetValue('AreaActive', false);
            return;
        }
        $this->SetBuffer('StartedFromMaster', $FromMaster ? '1' : '0');
        if (!$this->ReadPropertyBoolean('Enabled')) {
            $this->WriteLog('Zone deaktiviert - Start ignoriert');
            if ($FromMaster) { $this->NotifyMasterFinished(); }
            return;
        }
        if ($this->GetValue('AreaActive')) {
            $this->WriteLog('Zone läuft bereits - Start ignoriert');
            return;
        }
        $automatic = $this->ReadPropertyInteger('Mode') === self::MODE_AUTO;
        $zones = $this->GetRunnableZones($automatic);
        if (count($zones) === 0) {
            $this->WriteLog('Keine Kreise zum Bewässern');
            $this->SetValue('AreaActive', false);
            $this->SetQueue([]);
            if ($FromMaster) { $this->NotifyMasterFinished(); }
            return;
        }
        $this->SetQueue($zones);
        $this->SetBuffer('CurrentZoneID', '0');
        $this->SetValue('AreaActive', true);
        $this->SetValue('CurrentZone', 0);
        $this->SetValue('QueueCount', count($zones));
        $this->WriteLog('Zone startet mit ' . count($zones) . ' Kreis(en)');
        $this->SetTimerInterval('StartNextZoneTimer', 100);
    }

    public function StopArea(bool $FromMaster = false): void
    {
        $this->SetTimerInterval('StartNextZoneTimer', 0);
        $this->SetTimerInterval('StopCurrentZoneTimer', 0);
        $currentZoneID = (int)$this->GetBuffer('CurrentZoneID');
        if ($currentZoneID > 0 && @IPS_InstanceExists($currentZoneID)) {
            @IRRZ_StopZone($currentZoneID, true);
        }
        $this->SetQueue([]);
        $this->SetBuffer('CurrentZoneID', '0');
        $this->SetBuffer('StartedFromMaster', '0');
        $this->SetValue('AreaActive', false);
        $this->SetValue('CurrentZone', 0);
        $this->SetValue('QueueCount', 0);
        $this->WriteLog('Zone gestoppt');
    }

    public function StartNextZone(): void
    {
        $this->SetTimerInterval('StartNextZoneTimer', 0);
        $queue = $this->GetQueue();
        if (count($queue) === 0) {
            $this->FinishArea();
            return;
        }
        $zoneID = array_shift($queue);
        $this->SetQueue($queue);
        $this->SetBuffer('CurrentZoneID', (string)$zoneID);
        if (!@IPS_InstanceExists($zoneID)) {
            $this->WriteLog('Kreis existiert nicht mehr - überspringe');
            $this->SetTimerInterval('StartNextZoneTimer', 100);
            return;
        }
        $duration = $this->GetDurationMinutesSafe($zoneID);
        $number = $this->GetZoneNumberSafe($zoneID);
        if ($duration <= 0) {
            $this->WriteLog('Kreis ' . $number . ' hat keine gültige Dauer - übersprungen');
            $this->SetTimerInterval('StartNextZoneTimer', 100);
            return;
        }
        $this->SetValue('CurrentZone', $number);
        $this->SetValue('QueueCount', count($queue));
        $this->WriteLog('Starte Kreis ' . $number . ' für ' . $duration . ' Minute(n)');
        @IRRZ_StartZone($zoneID, true);
        $this->SetTimerInterval('StopCurrentZoneTimer', $duration * 60 * 1000);
    }

    public function FinishCurrentZone(): void
    {
        $this->SetTimerInterval('StopCurrentZoneTimer', 0);
        $currentZoneID = (int)$this->GetBuffer('CurrentZoneID');
        if ($currentZoneID > 0 && @IPS_InstanceExists($currentZoneID)) {
            @IRRZ_StopZone($currentZoneID, true);
            $this->WriteLog('Kreis ' . $this->GetZoneNumberSafe($currentZoneID) . ' beendet');
        }
        $this->SetBuffer('CurrentZoneID', '0');
        $queue = $this->GetQueue();
        if (count($queue) === 0) {
            $this->FinishArea();
            return;
        }
        $pauseSeconds = max(0, $this->ReadPropertyInteger('PauseBetweenZonesSeconds'));
        $this->SetTimerInterval('StartNextZoneTimer', max(100, $pauseSeconds * 1000));
    }

    private function FinishArea(): void
    {
        $fromMaster = $this->GetBuffer('StartedFromMaster') === '1';
        $this->SetTimerInterval('StartNextZoneTimer', 0);
        $this->SetTimerInterval('StopCurrentZoneTimer', 0);
        $this->SetQueue([]);
        $this->SetBuffer('CurrentZoneID', '0');
        $this->SetBuffer('StartedFromMaster', '0');
        $this->SetValue('AreaActive', false);
        $this->SetValue('CurrentZone', 0);
        $this->SetValue('QueueCount', 0);
        $this->WriteLog('Zone abgeschlossen');
        if ($fromMaster) {
            $this->NotifyMasterFinished();
        }
    }

    private function NotifyMasterFinished(): void
    {
        $parentID = @IPS_GetParent($this->InstanceID);
        if ($parentID > 0 && @IPS_InstanceExists($parentID) && function_exists('IRR_FinishCurrentArea')) {
            @IRR_FinishCurrentArea($parentID);
        }
    }

    public function IsEnabled(): bool { return $this->ReadPropertyBoolean('Enabled'); }
    public function GetAreaNumber(): int { return $this->ReadPropertyInteger('AreaNumber'); }

    private function IsAnotherAreaOrZoneActiveGlobally(): bool
    {
        $masterID = @IPS_GetParent($this->InstanceID);

        if ($masterID <= 0 || !@IPS_ObjectExists($masterID)) {
            return false;
        }

        foreach (IPS_GetChildrenIDs($masterID) as $areaID) {
            if (!@IPS_InstanceExists($areaID)) {
                continue;
            }

            // Andere aktive Area sperrt
            if ($areaID !== $this->InstanceID) {
                $areaActiveID = @IPS_GetObjectIDByIdent('AreaActive', $areaID);
                if ($areaActiveID !== false && $areaActiveID > 0 && @IPS_VariableExists($areaActiveID)) {
                    if ((bool)@GetValue($areaActiveID)) {
                        return true;
                    }
                }
            }

            // Jeder aktive Kreis in jeder Area sperrt
            foreach (IPS_GetChildrenIDs($areaID) as $zoneID) {
                if (!@IPS_InstanceExists($zoneID)) {
                    continue;
                }

                $zoneActiveID = @IPS_GetObjectIDByIdent('ZoneActive', $zoneID);
                if ($zoneActiveID === false || $zoneActiveID <= 0 || !@IPS_VariableExists($zoneActiveID)) {
                    continue;
                }

                if ((bool)@GetValue($zoneActiveID)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function GetZones(): array
    {
        $maxZones = max(1, min(10, $this->ReadPropertyInteger('MaxZones')));
        $zones = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            if (!@IPS_InstanceExists($childID)) { continue; }
            $instance = IPS_GetInstance($childID);
            if (strtoupper($instance['ModuleInfo']['ModuleID'] ?? '') !== strtoupper(self::MODULE_ID_ZONE)) { continue; }
            $number = $this->GetZoneNumberSafe($childID);
            if ($number < 1 || $number > $maxZones) { continue; }
            $zones[$number . '_' . $childID] = $childID;
        }
        ksort($zones, SORT_NATURAL);
        return array_values($zones);
    }

    private function GetRunnableZones(bool $automatic): array
    {
        $result = [];
        foreach ($this->GetZones() as $zoneID) {
            if (function_exists('IRRZ_IsEnabled') && !@IRRZ_IsEnabled($zoneID)) { continue; }
            if ($automatic) {
                @IRRZ_RefreshValues($zoneID);
                if (function_exists('IRRZ_ShouldWater') && !@IRRZ_ShouldWater($zoneID)) { continue; }
            }
            $result[] = $zoneID;
        }
        return $result;
    }

    private function GetQueue(): array
    {
        $queue = json_decode($this->GetBuffer('Queue'), true);
        return is_array($queue) ? array_values(array_map('intval', $queue)) : [];
    }

    private function SetQueue(array $queue): void
    {
        $queue = array_values(array_map('intval', $queue));
        $this->SetBuffer('Queue', json_encode($queue));
        $this->SetValue('QueueCount', count($queue));
    }

    private function GetZoneNumberSafe(int $zoneID): int
    {
        try {
            if (function_exists('IRRZ_GetZoneNumber')) {
                $number = @IRRZ_GetZoneNumber($zoneID);
                if (is_int($number)) { return $number; }
            }
            if (@IPS_InstanceExists($zoneID)) {
                $number = @IPS_GetProperty($zoneID, 'ZoneNumber');
                if (is_numeric($number)) { return (int)$number; }
            }
        } catch (Throwable $e) {}
        return 0;
    }

    private function GetDurationMinutesSafe(int $zoneID): int
    {
        try {
            if (function_exists('IRRZ_GetDurationMinutes')) {
                $duration = @IRRZ_GetDurationMinutes($zoneID);
                if (is_int($duration)) { return $duration; }
            }
            if (@IPS_InstanceExists($zoneID)) {
                $duration = @IPS_GetProperty($zoneID, 'Duration');
                if (is_numeric($duration)) { return (int)$duration; }
            }
        } catch (Throwable $e) {}
        return 0;
    }

    private function WriteLog(string $message): void
    {
        $masterID = @IPS_GetParent($this->InstanceID);
        $areaNumber = $this->ReadPropertyInteger('AreaNumber');

        $prefix = 'Zone ' . $areaNumber . ': ';

        if ($masterID > 0 && @IPS_InstanceExists($masterID) && function_exists('IRR_AddActionLog')) {
            @IRR_AddActionLog($masterID, $prefix . $message);
        }

        $this->SetValue('DecisionText', $message);
        IPS_LogMessage('IRRA[' . $this->InstanceID . ']', $message);
    }

    private function RenderLastActionHtml(array $entries): string
    {
        $html = '<div style="font-family:Tahoma, Arial, sans-serif; font-size:12px; line-height:1.35; text-align:right;">';
        foreach ($entries as $entry) {
            $time = htmlspecialchars((string)($entry['time'] ?? ''), ENT_QUOTES, 'UTF-8');
            $message = htmlspecialchars((string)($entry['message'] ?? ''), ENT_QUOTES, 'UTF-8');
            $html .= '<div><span style="color:#4da6ff; font-weight:bold;">' . $time . '</span><span style="color:#ffffff;"> &ndash; ' . $message . '</span></div>';
        }
        return $html . '</div>';
    }

    private function RenderOverviewHtml(array $parts, string $empty): string
    {
        $style = $this->GetHtmlStyleFromMaster();

        $fontFamily  = htmlspecialchars((string)($style['fontFamily'] ?? 'Tahoma'), ENT_QUOTES, 'UTF-8');
        $fontSize    = (int)($style['fontSize'] ?? 12);
        $accentColor = htmlspecialchars((string)($style['accentColor'] ?? '#4da6ff'), ENT_QUOTES, 'UTF-8');
        $textColor   = htmlspecialchars((string)($style['textColor'] ?? '#ffffff'), ENT_QUOTES, 'UTF-8');

        $html = '<div style="font-family:' . $fontFamily . ', Arial, sans-serif; font-size:' . $fontSize . 'px; line-height:1.35; text-align:right;">';

        if (count($parts) === 0) {
            $html .= '<span style="color:' . $textColor . ';">' . htmlspecialchars($empty, ENT_QUOTES, 'UTF-8') . '</span>';
        } else {
            foreach ($parts as $part) {
                $part = (string)$part;

                // Erwartet z.B.: "Kreis 1 (#12345)" oder "Kreis 1 - Vorgarten (#12345)"
                $id = '';
                $name = $part;

                if (preg_match('/#(\d+)/', $part, $matches)) {
                    $id = $matches[1];
                }

                if (preg_match('/^(.*?)\s*\(#\d+\)$/', $part, $matches)) {
                    $name = trim($matches[1]);
                }

                $html .= '<div>';
                $html .= '<span style="color:' . $accentColor . ';">' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . ' .....</span> ';
                $html .= '<span style="color:' . $textColor . ';">|</span> ';
                $html .= '<span style="color:' . $textColor . ';">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>';
                $html .= '</div>';
            }
        }

        return $html . '</div>';
    }

    private function UpdateStatus(): void
    {
        if (!$this->ReadPropertyBoolean('Enabled')) { $this->SetStatus(104); return; }
        $this->SetStatus(count($this->GetZones()) > 0 ? 102 : 200);
    }

    public function HandleScheduleTimer(bool $state): void
    {
        if (!$state) {
            return;
        }

        if (!$this->ReadPropertyBoolean('Enabled')) {
            $this->WriteLog('Zeitsteuerung ignoriert - Zone deaktiviert');
            return;
        }

        if ($this->ReadPropertyInteger('Mode') !== self::MODE_TIME) {
            $this->WriteLog('Zeitsteuerung ignoriert - Betriebsmodus ist nicht Zeitsteuerung');
            return;
        }

        $this->StartArea(false);
    }

    public function HandleScheduleAuto(bool $state): void
    {
        if (!$state) {
            return;
        }

        if (!$this->ReadPropertyBoolean('Enabled')) {
            $this->WriteLog('Automatik ignoriert - Zone deaktiviert');
            return;
        }

        if ($this->ReadPropertyInteger('Mode') !== self::MODE_AUTO) {
            $this->WriteLog('Automatik ignoriert - Betriebsmodus ist nicht Automatik');
            return;
        }

        $this->StartArea(false);
    }

    private function MaintainWeekplan(string $ident, string $name): void
    {
        $eid = @$this->GetIDForIdent($ident);

        if ($eid === false) {
            $eid = IPS_CreateEvent(2);
            IPS_SetParent($eid, $this->InstanceID);
            IPS_SetIdent($eid, $ident);
            IPS_SetName($eid, $name);
            IPS_SetEventActive($eid, false);
        }

        $handler = ($ident === 'ScheduleAuto') ? 'HandleScheduleAuto' : 'HandleScheduleTimer';
        IPS_SetEventScript(
            $eid,
            'if ($_IPS[\'ACTION\'] == 1) { IRRA_' . $handler . '($_IPS[\'TARGET\'], $_IPS[\'VALUE\']); }'
        );
    }

    private function UpdateWeekplanVisibility(): void
    {
        $mode = $this->ReadPropertyInteger('Mode');

        $timerID = @$this->GetIDForIdent('ScheduleTimer');
        if ($timerID !== false) {
            IPS_SetHidden($timerID, $mode !== self::MODE_TIME);
        }

        $autoID = @$this->GetIDForIdent('ScheduleAuto');
        if ($autoID !== false) {
            IPS_SetHidden($autoID, $mode !== self::MODE_AUTO);
        }
    }

    private function GetHtmlStyleFromMaster(): array
    {
        $masterID = @IPS_GetParent($this->InstanceID);

        if ($masterID > 0 && function_exists('IRR_GetHtmlStyle')) {
            $json = @IRR_GetHtmlStyle($masterID);
            $data = json_decode($json, true);

            if (is_array($data)) {
                return $data;
            }
        }

        return [
            'fontFamily' => 'Tahoma',
            'fontSize' => 12,
            'accentColor' => '#4da6ff',
            'textColor' => '#ffffff'
        ];
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('IRR.Mode')) {
            IPS_CreateVariableProfile('IRR.Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('IRR.Mode', self::MODE_MANUAL, 'Manuell', '', -1);
            IPS_SetVariableProfileAssociation('IRR.Mode', self::MODE_TIME, 'Zeitsteuerung', '', -1);
            IPS_SetVariableProfileAssociation('IRR.Mode', self::MODE_AUTO, 'Automatik', '', -1);
        }
        if (!IPS_VariableProfileExists('IRR.Seconds')) {
            IPS_CreateVariableProfile('IRR.Seconds', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('IRR.Seconds', '', ' s');
            IPS_SetVariableProfileValues('IRR.Seconds', 0, 3600, 1);
        }
    }
}
