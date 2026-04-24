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
        $this->RegisterPropertyInteger('DefaultDuration', 10);
        $this->RegisterPropertyInteger('PauseBetweenZonesSeconds', 5);
        $this->RegisterPropertyInteger('MaxZones', 10);

        $this->RegisterProfiles();

        $this->RegisterVariableInteger('Mode', 'Betriebsmodus', 'IRR.Mode', 10);
        $this->EnableAction('Mode');
        $this->RegisterVariableInteger('DefaultDuration', 'Standarddauer je Kreis', 'IRR.Minutes', 20);
        $this->EnableAction('DefaultDuration');
        $this->RegisterVariableInteger('PauseBetweenZonesSeconds', 'Pause zwischen Kreisen', 'IRR.Seconds', 30);
        $this->EnableAction('PauseBetweenZonesSeconds');
        $this->RegisterVariableBoolean('SequenceActive', 'Sequenz aktiv', '~Switch', 40);
        $this->EnableAction('SequenceActive');
        $this->RegisterVariableInteger('CurrentZone', 'Aktueller Kreis', '', 50);
        $this->RegisterVariableInteger('QueueCount', 'Wartende Kreise', '', 60);
        $this->RegisterVariableString('DecisionText', 'Sequenzstatus', '', 70);
        $this->RegisterVariableString('LastAction', 'Letzte Aktion', '', 80);
        $this->RegisterVariableString('ZoneOverview', 'Kreisübersicht', '', 90);

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
        $this->SetValue('DefaultDuration', $this->ReadPropertyInteger('DefaultDuration'));
        $this->SetValue('PauseBetweenZonesSeconds', $this->ReadPropertyInteger('PauseBetweenZonesSeconds'));

        $this->MaintainWeekplan('ScheduleTimer', 'Zeitsteuerung');
        $this->MaintainWeekplan('ScheduleAuto', 'Automatik');
        $this->UpdateWeekplanVisibility();
        $this->RefreshZones();
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
            case 'DefaultDuration':
                IPS_SetProperty($this->InstanceID, 'DefaultDuration', max(1, (int) $Value));
                IPS_ApplyChanges($this->InstanceID);
                break;
            case 'PauseBetweenZonesSeconds':
                IPS_SetProperty($this->InstanceID, 'PauseBetweenZonesSeconds', max(0, (int) $Value));
                IPS_ApplyChanges($this->InstanceID);
                break;
            case 'SequenceActive':
                if ((bool) $Value) {
                    $this->GetValue('Mode') === self::MODE_AUTO ? $this->StartAutomaticSequence() : $this->StartManualSequence();
                } else {
                    $this->StopSequence();
                }
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    public function RefreshZones(): void
    {
        $zones = $this->GetZones();
        $parts = [];
        foreach ($zones as $zoneID) {
            $parts[] = 'Kreis ' . @IRRZ_GetZoneNumber($zoneID) . ': ' . IPS_GetName($zoneID) . ' (#' . $zoneID . ')';
        }
        $this->SetValue('ZoneOverview', implode("\n", $parts));
        $this->SetValue('QueueCount', count($this->GetQueue()));
        $this->Debug('RefreshZones', ['Zones' => $zones]);
    }

    public function StartManualSequence(): void
    {
        $this->Debug('StartManualSequence', 'gestartet');
        $this->StartQueue($this->GetRunnableZones(false), 'Manuell/Zeitsteuerung');
    }

    public function StartAutomaticSequence(): void
    {
        $this->Debug('StartAutomaticSequence', 'gestartet');
        $this->StartQueue($this->GetRunnableZones(true), 'Automatik');
    }

    public function StopSequence(): void
    {
        $this->Debug('StopSequence', 'gestartet');
        $currentZoneID = (int) $this->GetBuffer('CurrentZoneID');
        if ($currentZoneID > 0 && @IPS_InstanceExists($currentZoneID)) {
            @IRRZ_StopZone($currentZoneID);
        }
        $this->SetQueue([]);
        $this->SetBuffer('CurrentZoneID', '0');
        $this->SetTimerInterval('StopCurrentZoneTimer', 0);
        $this->SetTimerInterval('StartNextZoneTimer', 0);
        $this->SetValue('SequenceActive', false);
        $this->SetValue('CurrentZone', 0);
        $this->WriteLog('Sequenz gestoppt');
    }

    public function StartNextZone(): void
    {
        $this->Debug('StartNextZone', 'gestartet');
        $this->SetTimerInterval('StartNextZoneTimer', 0);

        $queue = $this->GetQueue();
        if (count($queue) === 0) {
            $this->SetBuffer('CurrentZoneID', '0');
            $this->SetValue('SequenceActive', false);
            $this->SetValue('CurrentZone', 0);
            $this->SetValue('QueueCount', 0);
            $this->WriteLog('Sequenz abgeschlossen');
            return;
        }

        $zoneID = array_shift($queue);
        $this->SetQueue($queue);

        if (!@IPS_InstanceExists($zoneID)) {
            $this->Debug('StartNextZone', 'Zone existiert nicht mehr, springe weiter');
            $this->SetTimerInterval('StartNextZoneTimer', 100);
            return;
        }

        $duration = @IRRZ_GetDurationMinutes($zoneID);
        if (!is_int($duration) || $duration <= 0) {
            $duration = $this->ReadPropertyInteger('DefaultDuration');
        }

        $this->SetBuffer('CurrentZoneID', (string) $zoneID);
        $this->SetValue('CurrentZone', @IRRZ_GetZoneNumber($zoneID));
        $this->WriteLog('Starte Kreis ' . @IRRZ_GetZoneNumber($zoneID) . ' für ' . $duration . ' Minute(n)');
        @IRRZ_StartZone($zoneID);
        $this->SetTimerInterval('StopCurrentZoneTimer', $duration * 60 * 1000);
    }

    public function FinishCurrentZone(): void
    {
        $this->Debug('FinishCurrentZone', 'gestartet');
        $this->SetTimerInterval('StopCurrentZoneTimer', 0);
        $currentZoneID = (int) $this->GetBuffer('CurrentZoneID');
        if ($currentZoneID > 0 && @IPS_InstanceExists($currentZoneID)) {
            @IRRZ_StopZone($currentZoneID);
            $this->WriteLog('Kreis ' . @IRRZ_GetZoneNumber($currentZoneID) . ' beendet');
        }
        $this->SetBuffer('CurrentZoneID', '0');
        $pauseSeconds = max(0, $this->ReadPropertyInteger('PauseBetweenZonesSeconds'));
        $this->SetTimerInterval('StartNextZoneTimer', max(100, $pauseSeconds * 1000));
    }

    private function StartQueue(array $zones, string $source): void
    {
        $this->Debug('StartQueue', ['Source' => $source, 'Zones' => $zones]);
        if ($this->GetValue('SequenceActive')) {
            $this->StopSequence();
        }
        if (count($zones) === 0) {
            $this->SetQueue([]);
            $this->SetValue('SequenceActive', false);
            $this->WriteLog($source . ': keine Kreise zum Bewässern');
            return;
        }
        $this->SetQueue($zones);
        $this->SetValue('SequenceActive', true);
        $this->WriteLog($source . ': Sequenz mit ' . count($zones) . ' Kreis(en) gestartet');
        $this->SetTimerInterval('StartNextZoneTimer', 100);
    }

    private function GetRunnableZones(bool $automatic): array
    {
        $result = [];
        foreach ($this->GetZones() as $zoneID) {
            $enabled = @IRRZ_IsEnabled($zoneID);
            $number = @IRRZ_GetZoneNumber($zoneID);
            $this->Debug('GetRunnableZones.Zone', ['ID' => $zoneID, 'Number' => $number, 'Enabled' => $enabled, 'Automatic' => $automatic]);
            if (!$enabled) {
                continue;
            }
            if ($automatic && !@IRRZ_ShouldWater($zoneID)) {
                $this->Debug('GetRunnableZones.Skip', 'Kreis ' . $number . ' braucht keine Bewässerung');
                continue;
            }
            $result[] = $zoneID;
        }
        return $result;
    }

    private function GetZones(): array
    {
        $maxZones = max(1, min(10, $this->ReadPropertyInteger('MaxZones')));
        $zones = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            if (!@IPS_InstanceExists($childID)) {
                continue;
            }
            $instance = IPS_GetInstance($childID);
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
        return is_array($queue) ? array_values(array_filter($queue, 'is_int')) : [];
    }

    private function SetQueue(array $queue): void
    {
        $queue = array_values(array_map('intval', $queue));
        $this->SetBuffer('Queue', json_encode($queue));
        $this->SetValue('QueueCount', count($queue));
        $this->Debug('SetQueue', $queue);
    }

    private function MaintainWeekplan(string $Ident, string $Name): void
    {
        $eventID = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
        if ($eventID === false) {
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
        $this->SetStatus(count($this->GetZones()) === 0 ? 200 : 102);
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
