<?php

declare(strict_types=1);

class IRRIrrigationController extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('Mode', 0);
        $this->RegisterPropertyInteger('MoistureSensor1', 0);
        $this->RegisterPropertyInteger('MoistureSensor2', 0);
        $this->RegisterPropertyInteger('RainLast24h', 0);
        $this->RegisterPropertyInteger('Valve1', 0);
        $this->RegisterPropertyInteger('Valve2', 0);
        $this->RegisterPropertyInteger('Pump', 0);
        $this->RegisterPropertyInteger('Duration', 10);
        $this->RegisterPropertyInteger('MoistureThreshold', 30);
        $this->RegisterPropertyString('StartTime', '06:00');
        $this->RegisterPropertyInteger('Days', 127); // Alle Tage aktiv
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Hier könnten Timer o.ä. gesetzt werden, je nach Modus
    }

    public function RequestAction($Ident, $Value)
    {
        // Hier kannst du später manuelle Aktionen (z. B. Ventil manuell starten) behandeln
    }

    private function ReadSensorValue(int $sensorID): ?float
    {
        if ($sensorID <= 0) {
            return null;
        }
        $value = @GetValue($sensorID);
        return is_numeric($value) ? floatval($value) : null;
    }

    private function ShouldWater(): bool
    {
        $sensor1 = $this->ReadSensorValue($this->ReadPropertyInteger('MoistureSensor1'));
        $sensor2 = $this->ReadSensorValue($this->ReadPropertyInteger('MoistureSensor2'));

        $threshold = $this->ReadPropertyInteger('MoistureThreshold');

        $belowThreshold = [];
        if (!is_null($sensor1)) $belowThreshold[] = $sensor1 < $threshold;
        if (!is_null($sensor2)) $belowThreshold[] = $sensor2 < $threshold;

        return in_array(true, $belowThreshold, true);
    }

    private function ActivateWatering()
    {
        $duration = $this->ReadPropertyInteger('Duration');
        $valve1 = $this->ReadPropertyInteger('Valve1');
        $valve2 = $this->ReadPropertyInteger('Valve2');
        $pump = $this->ReadPropertyInteger('Pump');

        if ($pump > 0) RequestAction($pump, true);
        if ($valve1 > 0) RequestAction($valve1, true);
        if ($valve2 > 0) RequestAction($valve2, true);

        IPS_Sleep($duration * 60 * 1000);

        if ($valve1 > 0) RequestAction($valve1, false);
        if ($valve2 > 0) RequestAction($valve2, false);
        if ($pump > 0) RequestAction($pump, false);
    }
}
