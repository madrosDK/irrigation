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
    }

    public function RequestAction($Ident, $Value)
    {
        // Wird später ergänzt
    }
}
