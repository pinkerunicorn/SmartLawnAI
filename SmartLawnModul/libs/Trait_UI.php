<?php

trait SmartLawnAI_UI {

    private function RegisterVisuMessages(): void {
        $this->RegisterMessage($this->GetIDForIdent('SummaryStatus'), VM_UPDATE);
        $this->RegisterMessage($this->GetIDForIdent('ForecastRainToday'), VM_UPDATE);
        $this->RegisterMessage($this->GetIDForIdent('ForecastRainTomorrow'), VM_UPDATE);
        
        $this->RegisterMessage($this->GetIDForIdent('AutomaticActive'), VM_UPDATE);
        $this->RegisterMessage($this->GetIDForIdent('ForceStart'), VM_UPDATE);
        $this->RegisterMessage($this->GetIDForIdent('DefaultZielFeuchte'), VM_UPDATE);
        $this->RegisterMessage($this->GetIDForIdent('DefaultStartSchwellwert'), VM_UPDATE);
        $this->RegisterMessage($this->GetIDForIdent('SickerpauseMinuten'), VM_UPDATE);
        $this->RegisterMessage($this->GetIDForIdent('GlobalMaxDuration'), VM_UPDATE);
        
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (is_array($zones)) {
            foreach ($zones as $zone) {
                $sid = $zone['SensorID'];
                $this->RegisterMessage((int)$sid, VM_UPDATE);
                if (@$this->GetIDForIdent('Status_' . $sid)) $this->RegisterMessage($this->GetIDForIdent('Status_' . $sid), VM_UPDATE);
                if (@$this->GetIDForIdent('Effizienz_' . $sid)) $this->RegisterMessage($this->GetIDForIdent('Effizienz_' . $sid), VM_UPDATE);
                if (@$this->GetIDForIdent('Dauer_' . $sid)) $this->RegisterMessage($this->GetIDForIdent('Dauer_' . $sid), VM_UPDATE);
            }
        }
    }

    public function GetVisualizationTile(): string {
        $initialHandling = '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ')</script>';
        
        $htmlFile = __DIR__ . '/../module.html';
        $moduleHtml = '';
        if (file_exists($htmlFile)) {
            $moduleHtml = file_get_contents($htmlFile);
        }
        
        return $moduleHtml . $initialHandling;
    }

    private function GetFullUpdateMessage(): string {
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (!is_array($zones)) $zones = [];

        $sprinklersJson = $this->ReadPropertyString('Sprinklers');
        $allSprinklers = json_decode($sprinklersJson, true);
        if (!is_array($allSprinklers)) $allSprinklers = [];

        $zoneData = [];
        foreach ($zones as $zone) {
            $sid = $zone['SensorID'];
            
            $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : ('Zone ' . $sid);
            $zoneSprinklers = array_filter($allSprinklers, function($s) use ($zoneName) {
                return isset($s['ZoneName']) && $s['ZoneName'] === $zoneName;
            });
            $zoneSprinklers = array_values($zoneSprinklers);

            $currentIndex = (int)@GetValue($this->GetIDForIdent('CurrentSprinklerIndex_' . $sid));
            $currentSprinklerName = '';
            $remainingSeconds = 0;
            if (isset($zoneSprinklers[$currentIndex])) {
                $currentSprinklerName = isset($zoneSprinklers[$currentIndex]['SprinklerName']) && !empty($zoneSprinklers[$currentIndex]['SprinklerName']) ? $zoneSprinklers[$currentIndex]['SprinklerName'] : 'Sprinkler ' . ($currentIndex + 1);
                $res = $this->ResolveSprinklerObject((int)@$zoneSprinklers[$currentIndex]['ValveID']);
                if ($res['RemainingSecondsID'] > 0) {
                    $remainingSeconds = (int)@GetValue($res['RemainingSecondsID']);
                }
            }

            $zoneData[] = [
                'id' => $sid,
                'name' => $zoneName,
                'status' => @GetValue($this->GetIDForIdent('Status_' . $sid)),
                'moisture' => @GetValue((int)$sid),
                'efficiency' => @GetValue($this->GetIDForIdent('Effizienz_' . $sid)),
                'duration' => @GetValue($this->GetIDForIdent('Dauer_' . $sid)),
                'wateringStart' => @GetValue($this->GetIDForIdent('WateringStart_' . $sid)),
                'currentSprinkler' => $currentSprinklerName,
                'remainingSeconds' => $remainingSeconds
            ];
        }

        $config = [
            'summaryStatus' => @GetValue($this->GetIDForIdent('SummaryStatus')),
            'forecastRainToday' => @GetValue($this->GetIDForIdent('ForecastRainToday')),
            'forecastRainTomorrow' => @GetValue($this->GetIDForIdent('ForecastRainTomorrow')),
            'zones' => $zoneData,
            'settings' => [
                'AutomaticActive' => ['id' => $this->GetIDForIdent('AutomaticActive'), 'value' => @GetValue($this->GetIDForIdent('AutomaticActive'))],
                'ManualStart' => ['id' => $this->GetIDForIdent('ForceStart'), 'value' => @GetValue($this->GetIDForIdent('ForceStart'))],
                'DefaultStartSchwellwert' => ['id' => $this->GetIDForIdent('DefaultStartSchwellwert'), 'value' => @GetValue($this->GetIDForIdent('DefaultStartSchwellwert'))],
                'DefaultZielFeuchte' => ['id' => $this->GetIDForIdent('DefaultZielFeuchte'), 'value' => @GetValue($this->GetIDForIdent('DefaultZielFeuchte'))],
                'GlobalMaxDuration' => ['id' => $this->GetIDForIdent('GlobalMaxDuration'), 'value' => @GetValue($this->GetIDForIdent('GlobalMaxDuration'))],
                'SickerpauseMinuten' => ['id' => $this->GetIDForIdent('SickerpauseMinuten'), 'value' => @GetValue($this->GetIDForIdent('SickerpauseMinuten'))]
            ]
        ];

        return json_encode($config);
    }

    public function UIRequest(string $Action, string $Payload): void {
        switch ($Action) {
            case 'TOGGLE_AUTOMATIC':
                $id = $this->GetIDForIdent('AutomaticActive');
                $newVal = !GetValue($id);
                $this->SetValue('AutomaticActive', $newVal);
                if (!$newVal) {
                    $this->resetAllZones(false);
                } else {
                    $this->SetBuffer('LastPlanCalculation', '0');
                    $this->ProcessLogic();
                }
                break;
            case 'FORCE_START_SEQUENCE':
                $this->triggerManualStart();
                break;
        }

        $airTempID = $this->ReadPropertyInteger('GlobalAirTempID');
        $humidityID = $this->ReadPropertyInteger('GlobalHumidityID');
        $illuminanceID = $this->ReadPropertyInteger('GlobalIlluminanceID');

        $t = ($airTempID > 0) ? (float)GetValue($airTempID) : 20.0;
        $rh = ($humidityID > 0) ? (float)GetValue($humidityID) : 50.0;
        $lux = ($illuminanceID > 0) ? (float)GetValue($illuminanceID) : 0.0;

        $es = 0.6108 * exp((17.27 * $t) / ($t + 237.3));
        $vpd = $es * (1 - ($rh / 100.0));

        $defaultZiel  = GetValue($this->GetIDForIdent('DefaultZielFeuchte'));
        $defaultStart = GetValue($this->GetIDForIdent('DefaultStartSchwellwert'));
        
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        
        $zoneData = [];
        if (is_array($zones)) {
            foreach ($zones as $zone) {
                $sid = $zone['SensorID'];
                $zielWert  = $defaultZiel;
                $startWert = $defaultStart;
                
                $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone ' . $sid;
                $hwStatus = $this->isHardwareOk($sid);
                
                $allSprinklersJson = $this->ReadPropertyString('Sprinklers');
                $allSprinklers = json_decode($allSprinklersJson, true);
                if (!is_array($allSprinklers)) $allSprinklers = [];
                
                $zoneSprinklers = array_filter($allSprinklers, function($s) use ($zoneName) {
                    return isset($s['ZoneName']) && $s['ZoneName'] === $zoneName;
                });
                $zoneSprinklers = array_values($zoneSprinklers);
                
                $currentIndex = (int)@GetValue($this->GetIDForIdent('CurrentSprinklerIndex_' . $sid));
                $remainingSeconds = 0;
                if (isset($zoneSprinklers[$currentIndex])) {
                    $res = $this->ResolveSprinklerObject((int)@$zoneSprinklers[$currentIndex]['ValveID']);
                    if ($res['RemainingSecondsID'] > 0) {
                        $remainingSeconds = (int)@GetValue($res['RemainingSecondsID']);
                    }
                }

                $statusId = @$this->GetIDForIdent('Status_' . $sid);
                $effizienzId = @$this->GetIDForIdent('Effizienz_' . $sid);
                
                $zoneData[] = [
                    'id' => $sid,
                    'name' => isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone ' . $sid,
                    'sensorVarId' => $sid,
                    'currentMoisture' => ($sid > 0) ? (float)GetValue($sid) : 0.0,
                    'targetMoisture' => $zielWert,
                    'startMoisture' => $startWert,
                    'status' => $statusId > 0 ? GetValue($statusId) : 'IDLE',
                    'efficiency' => $effizienzId > 0 ? (float)GetValue($effizienzId) : 1.0,
                    'hardwareOk' => $hwStatus,
                    'remainingSeconds' => $remainingSeconds,
                ];
            }
        }

        $config = [
            'globalVars' => [
                'airTemp' => $t,
                'vpd' => $vpd,
                'lux' => $lux,
                'automaticActive' => GetValue($this->GetIDForIdent('AutomaticActive'))
            ],
            'zones' => $zoneData
        ];
        
        $this->UpdateUI('INITIAL_CONFIG', $config);
    }
}
