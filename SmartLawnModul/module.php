<?php
class SmartLawnAI extends IPSModule {

    public function Create() {
        parent::Create();

        // Globale Defaults
        $this->RegisterPropertyFloat('DefaultZielFeuchte', 55.0);
        $this->RegisterPropertyFloat('DefaultStartSchwellwert', 20.0);

        // Globale Sensoren (Thermodynamik)
        $this->RegisterPropertyInteger('GlobalAirTempID', 0);
        $this->RegisterPropertyInteger('GlobalHumidityID', 0);
        $this->RegisterPropertyInteger('GlobalIlluminanceID', 0);

        // Zonen (Hardware)
        $this->RegisterPropertyString('Zones', '[]');

        // Timer für die 60-Sekunden-Taktung
        $this->RegisterTimer('LawnAITimer', 0, 'SLAI_ProcessLogic($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        
        // Timer aktivieren (alle 60.000 ms = 1 Minute)
        $this->SetTimerInterval('LawnAITimer', 60000);

        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (is_array($zones)) {
            foreach ($zones as $zone) {
                $sid = $zone['SensorID'];
                $name = isset($zone['Name']) && !empty($zone['Name']) ? $zone['Name'] : 'Zone ' . $sid;
                $this->RegisterVariableString('Status_' . $sid, 'Status ' . $name, '', 0);
                $this->RegisterVariableFloat('Effizienz_' . $sid, 'Effizienz ' . $name, '', 0);
                $this->RegisterVariableFloat('StartFeuchte_' . $sid, 'StartFeuchte ' . $name, '', 0);
                $this->RegisterVariableFloat('Dauer_' . $sid, 'Dauer ' . $name, '', 0);
                $this->RegisterVariableFloat('SickerpauseStart_' . $sid, 'SickerpauseStart ' . $name, '', 0);
            }
        }
    }

    public function ProcessLogic() {
        $defaultZiel  = $this->ReadPropertyFloat('DefaultZielFeuchte');
        $defaultStart = $this->ReadPropertyFloat('DefaultStartSchwellwert');
        
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        
        if (!is_array($zones) || empty($zones)) {
            return; 
        }

        // 1. Sequenz-Check: Ist bereits ein Ventil in Bearbeitung?
        $einVentilIstAktiv = false;
        foreach ($zones as $zone) {
            $status = GetValue($this->GetIDForIdent('Status_' . $zone['SensorID']));
            if ($status === 'WATERING' || $status === 'VERIFYING_START') {
                $einVentilIstAktiv = true;
                break; 
            }
        }

        // 2. Thermodynamik (VPD) für alle Zonen vorbereiten
        $airTempID = $this->ReadPropertyInteger('GlobalAirTempID');
        $humidityID = $this->ReadPropertyInteger('GlobalHumidityID');
        $illuminanceID = $this->ReadPropertyInteger('GlobalIlluminanceID');

        $t = ($airTempID > 0) ? (float)GetValue($airTempID) : 20.0;
        $rh = ($humidityID > 0) ? (float)GetValue($humidityID) : 50.0;
        $lux = ($illuminanceID > 0) ? (float)GetValue($illuminanceID) : 0.0;

        $es = 0.6108 * exp((17.27 * $t) / ($t + 237.3));
        $vpd = $es * (1 - ($rh / 100.0));

        // 3. Zonen-Durchlauf (State Machine)
        foreach ($zones as $zone) {
            $zielWert  = ($zone['CustomZiel'] > 0) ? $zone['CustomZiel'] : $defaultZiel;
            $startWert = ($zone['CustomStart'] > 0) ? $zone['CustomStart'] : $defaultStart;
            
            $aktuelleFeuchte = GetValue($zone['SensorID']);
            $aktuellerStatus = GetValue($this->GetIDForIdent('Status_' . $zone['SensorID']));
            if (empty($aktuellerStatus)) {
                $aktuellerStatus = 'IDLE';
            }

            // Gardena Not-Aus Check
            if ($zone['HardwareStatusID'] > 0) {
                $hwStatus = GetValue($zone['HardwareStatusID']);
                if ($hwStatus !== 0 && $hwStatus !== 'OK') {
                    SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'HARDWARE_FEHLER');
                    continue; 
                }
            }

            switch ($aktuellerStatus) {
                case 'IDLE':
                case 'QUEUED':
                    if ($aktuelleFeuchte <= $startWert) {
                        if ($einVentilIstAktiv) {
                            SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'QUEUED');
                        } else {
                            SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'VERIFYING_START');
                            
                            // KI-Laufzeitberechnung (Basiswert 30 Minuten, wenn noch kein Effizienzfaktor gelernt wurde)
                            $effizienz = (float)GetValue($this->GetIDForIdent('Effizienz_' . $zone['SensorID']));
                            if ($effizienz <= 0) $effizienz = 1.0; 
                            $differenz = $zielWert - $aktuelleFeuchte;
                            $berechneteMinuten = (int)ceil($differenz / $effizienz);

                            // Gardena Hardware-Watchdog: Dauer setzen
                            if ($zone['DurationID'] > 0) {
                                @RequestAction($zone['DurationID'], $berechneteMinuten);
                                IPS_Sleep(500); 
                            }

                            // Start-Befehl senden
                            @RequestAction($zone['ValveID'], true);
                            
                            // Zwischenspeichern für den Lern-Algorithmus später
                            SetValue($this->GetIDForIdent('StartFeuchte_' . $zone['SensorID']), $aktuelleFeuchte);
                            SetValue($this->GetIDForIdent('Dauer_' . $zone['SensorID']), $berechneteMinuten);
                            
                            $einVentilIstAktiv = true; 
                        }
                    } else {
                        SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'IDLE');
                    }
                    break;
                    
                case 'VERIFYING_START':
                case 'WATERING':
                    // Ventil-Rückkanal von Gardena prüfen
                    $ventilOffen = GetValue($zone['ValveID']);
                    
                    if ($ventilOffen && $aktuellerStatus === 'VERIFYING_START') {
                        SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'WATERING');
                    } elseif (!$ventilOffen && $aktuellerStatus === 'WATERING') {
                        // Ventil hat planmäßig geschlossen -> Sickerpause starten
                        SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'WAITING_FOR_RESULT');
                        SetValue($this->GetIDForIdent('SickerpauseStart_' . $zone['SensorID']), time());
                    }
                    break;

                case 'WAITING_FOR_RESULT':
                    $sickerStart = (int)GetValue($this->GetIDForIdent('SickerpauseStart_' . $zone['SensorID']));
                    // 15 Minuten (900 Sekunden) Sickerpause abwarten
                    if ((time() - $sickerStart) > 900) {
                        
                        // Thermodynamischen Gesamtverlustfaktor berechnen
                        $verdunstungsFaktorVPD = ($vpd > 1.2) ? (($vpd - 1.2) * 10 * 0.02) : 0.0;
                        $verdunstungsFaktorLux = ($lux > 20000) ? (($lux - 20000) / 10000) * 0.015 : 0.0;
                        $gesamtVerlustFaktor = 1.0 + $verdunstungsFaktorVPD + $verdunstungsFaktorLux;

                        // Lernerfolg auswerten
                        $startFeuchte = (float)GetValue($this->GetIDForIdent('StartFeuchte_' . $zone['SensorID']));
                        $dauer = (int)GetValue($this->GetIDForIdent('Dauer_' . $zone['SensorID']));
                        
                        $erreichteFeuchte = $aktuelleFeuchte - $startFeuchte;
                        $korrigiertesErgebnis = $erreichteFeuchte * $gesamtVerlustFaktor;
                        
                        // Neuen Effizienzfaktor sichern
                        if ($dauer > 0) {
                            $neueEffizienz = $korrigiertesErgebnis / $dauer;
                            SetValue($this->GetIDForIdent('Effizienz_' . $zone['SensorID']), $neueEffizienz);
                        }

                        SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'IDLE');
                    }
                    break;
            }
        }
    }

    public function UIRequest($Action, $Payload) {
        $airTempID = $this->ReadPropertyInteger('GlobalAirTempID');
        $humidityID = $this->ReadPropertyInteger('GlobalHumidityID');
        $illuminanceID = $this->ReadPropertyInteger('GlobalIlluminanceID');

        $t = ($airTempID > 0) ? (float)GetValue($airTempID) : 20.0;
        $rh = ($humidityID > 0) ? (float)GetValue($humidityID) : 50.0;
        $lux = ($illuminanceID > 0) ? (float)GetValue($illuminanceID) : 0.0;

        $es = 0.6108 * exp((17.27 * $t) / ($t + 237.3));
        $vpd = $es * (1 - ($rh / 100.0));

        $defaultZiel  = $this->ReadPropertyFloat('DefaultZielFeuchte');
        $defaultStart = $this->ReadPropertyFloat('DefaultStartSchwellwert');
        
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        
        $zoneData = [];
        if (is_array($zones)) {
            foreach ($zones as $zone) {
                $sid = $zone['SensorID'];
                $zielWert  = ($zone['CustomZiel'] > 0) ? $zone['CustomZiel'] : $defaultZiel;
                $startWert = ($zone['CustomStart'] > 0) ? $zone['CustomStart'] : $defaultStart;
                
                $hwStatus = false;
                if (isset($zone['HardwareStatusID']) && $zone['HardwareStatusID'] > 0) {
                    $hwVal = GetValue($zone['HardwareStatusID']);
                    if ($hwVal === 0 || $hwVal === 'OK') {
                        $hwStatus = true;
                    }
                } else {
                    $hwStatus = true;
                }

                $statusId = @$this->GetIDForIdent('Status_' . $sid);
                $effizienzId = @$this->GetIDForIdent('Effizienz_' . $sid);
                
                $zoneData[] = [
                    'id' => $sid,
                    'name' => isset($zone['Name']) ? $zone['Name'] : 'Zone ' . $sid,
                    'sensorVarId' => $sid,
                    'currentMoisture' => ($sid > 0) ? (float)GetValue($sid) : 0.0,
                    'targetMoisture' => $zielWert,
                    'startMoisture' => $startWert,
                    'status' => $statusId > 0 ? GetValue($statusId) : 'IDLE',
                    'efficiency' => $effizienzId > 0 ? (float)GetValue($effizienzId) : 1.0,
                    'hardwareOk' => $hwStatus
                ];
            }
        }

        $config = [
            'globalVars' => [
                'airTemp' => $t,
                'vpd' => $vpd,
                'lux' => $lux,
                'partyModeActive' => false
            ],
            'zones' => $zoneData
        ];
        
        $this->UpdateUI('INITIAL_CONFIG', $config);
    }
}