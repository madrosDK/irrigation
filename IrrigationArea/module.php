<?php

declare(strict_types=1);

class IrrigationArea extends IPSModule
{
    private const MODULE_ID_ZONE = '{B69A3F87-2E64-4AA0-B67E-7D84587B8A11}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Enabled', true);
        $this->RegisterPropertyInteger('AreaNumber', 1);

        $this->RegisterVariableBoolean('Enabled', 'Zone aktiv', '~Switch', 10);
        $this->EnableAction('Enabled');

        $this->RegisterVariableInteger('AreaNumber', 'Zonennummer', '', 20);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->SetValue('Enabled', $this->ReadPropertyBoolean('Enabled'));
        $this->SetValue('AreaNumber', $this->ReadPropertyInteger('AreaNumber'));
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

            case 'CreateZone':
                $this->CreateZone();
                break;

            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    public function CreateZone(): void
    {
        $children = IPS_GetChildrenIDs($this->InstanceID);
        $number = count($children) + 1;

        $zoneID = IPS_CreateInstance(self::MODULE_ID_ZONE);
        IPS_SetParent($zoneID, $this->InstanceID);
        IPS_SetName($zoneID, 'Kreis ' . $number);
        IPS_SetPosition($zoneID, 1000 + $number);
        IPS_SetProperty($zoneID, 'ZoneNumber', $number);
        IPS_ApplyChanges($zoneID);
    }
}
