<?php
class SmartLawnAI extends IPSModule {

    public function Create() {
        parent::Create();

        // Globale Defaults
        $this->RegisterPropertyFloat('DefaultZielFeuchte', 55.0);
        $this->RegisterPropertyFloat('DefaultStartSchwellwert', 20.0);
        $this->RegisterPropertyInteger('SickerpauseMinuten', 15);

        // Summenstatus Variable (fürs Webfront)
        $this->RegisterVariableString('SummaryStatus', 'Aktueller Status', '', 0);

        // Gemini AI Konfiguration
        $this->RegisterPropertyString('GeminiApiKey', '');
        $this->RegisterPropertyString('GeminiModel', 'gemini-3.5-flash');

        // Globale Sensoren (Thermodynamik & Boden)
        $this->RegisterPropertyInteger('GlobalAirTempID', 0);
        $this->RegisterPropertyInteger('GlobalHumidityID', 0);
        $this->RegisterPropertyInteger('GlobalIlluminanceID', 0);
        $this->RegisterPropertyInteger('GlobalSoilTempID', 0);
        $this->RegisterPropertyInteger('GlobalWeatherForecastID', 0);

        // Zonen (Hardware)
        $this->RegisterPropertyString('Zones', '[]');

        // Timer für die 60-Sekunden-Taktung
        $this->RegisterTimer('LawnAITimer', 0, 'SLAI_ProcessLogic($_IPS[\'TARGET\']);');
    }

    public function RequestAction($Ident, $Value) {
        if ($Ident === 'AutomaticActive') {
            SetValue($this->GetIDForIdent($Ident), $Value);
            if (!$Value) {
                $this->resetAllZones(false);
            }
        } else if ($Ident === 'ForceStart') {
            if ($Value) {
                SetValue($this->GetIDForIdent($Ident), true);
                $this->triggerManualStart();
                IPS_Sleep(500);
                SetValue($this->GetIDForIdent($Ident), false);
            }
        }
    }

    private function resetAllZones(bool $queueForStart) {
        $actionName = $queueForStart ? 'ManualStart (Hard Reset)' : 'Automatik Off (Hard Stop)';
        $this->SendDebug('Reset', $actionName . ' aufgerufen', 0);
        
        if (!$queueForStart) {
            IPS_LogMessage('SmartLawnAI', 'Automatik deaktiviert! Alle Ventile werden gestoppt und Zonen zurückgesetzt.');
        }

        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (is_array($zones)) {
            foreach ($zones as $zone) {
                $sid = $zone['SensorID'];
                
                // 1. Physisches Ventil stoppen (sicherheitshalber)
                if (isset($zone['ValveID']) && $zone['ValveID'] > 0) {
                    @RequestAction($zone['ValveID'], 'STOP_UNTIL_NEXT_TASK');
                }

                $statusId = @$this->GetIDForIdent('Status_' . $sid);
                if ($statusId > 0) {
                    // 2. Laufzeit-Variablen zurücksetzen (gelernt wird nicht beeinflusst: Effizienz bleibt!)
                    @SetValue($this->GetIDForIdent('StartFeuchte_' . $sid), 0.0);
                    @SetValue($this->GetIDForIdent('Dauer_' . $sid), 0.0);
                    @SetValue($this->GetIDForIdent('SickerpauseStart_' . $sid), 0.0);

                    // 3. Status setzen
                    $newStatus = $queueForStart ? 'QUEUED' : 'IDLE';
                    SetValue($statusId, $newStatus);
                    
                    if ($queueForStart) {
                        $this->SendDebug('Reset', 'Zone ' . $sid . ' hart resettet und -> QUEUED.', 0);
                        IPS_LogMessage('SmartLawnAI', 'Zone ' . $sid . ' wurde manuell zurückgesetzt und in Warteschlange eingereiht.');
                    } else {
                        $this->SendDebug('Reset', 'Zone ' . $sid . ' hart resettet und gestoppt -> IDLE.', 0);
                    }
                }
            }
        }
        
        // Kurze Pause, damit Gardena die Aus-Befehle sicher verarbeitet hat
        IPS_Sleep(1000);
        
        if ($queueForStart) {
            $this->ProcessLogic();
        }
    }

    private function triggerManualStart() {
        $this->SendDebug('ManualStart', 'Manueller Start angefordert. Setze Zonen zurück...', 0);
        $this->resetAllZones(false); // Stoppe alle aktiven Ventile und setze Zustand auf IDLE
        $this->SetBuffer('CalculatePlanPending', 'true');
        $this->ProcessLogic();
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        
        // Timer aktivieren (alle 60.000 ms = 1 Minute)
        $this->SetTimerInterval('LawnAITimer', 60000);

        $this->RegisterVariableBoolean('AutomaticActive', 'Automatik aktiv', '~Switch', 0);
        $this->EnableAction('AutomaticActive');
        if (!IPS_VariableExists($this->GetIDForIdent('AutomaticActive')) || (GetValue($this->GetIDForIdent('AutomaticActive')) === false && IPS_GetVariable($this->GetIDForIdent('AutomaticActive'))['VariableUpdated'] == 0)) {
            SetValue($this->GetIDForIdent('AutomaticActive'), true); // Default true
        }

        $this->RegisterVariableBoolean('ForceStart', 'Manuell Starten', '~Switch', 0);
        $this->EnableAction('ForceStart');
        SetValue($this->GetIDForIdent('ForceStart'), false);

        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (is_array($zones)) {
            foreach ($zones as $zone) {
                $sid = $zone['SensorID'];
                $name = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone ' . $sid;
            if (!empty($name)) {
                $this->RegisterVariableString('Status_' . $sid, 'Status ' . $name, '', 1);
                $this->RegisterVariableFloat('Effizienz_' . $sid, 'Effizienz ' . $name, '', 2);
                $this->RegisterVariableFloat('StartFeuchte_' . $sid, 'StartFeuchte ' . $name, '', 3);
                $this->RegisterVariableFloat('Dauer_' . $sid, 'Dauer ' . $name, '', 4);
                $this->RegisterVariableFloat('SickerpauseStart_' . $sid, 'SickerpauseStart ' . $name, '', 5);

                // IP-Symcon benennt bestehende Variablen nicht automatisch um, daher erzwingen wir es hier
                IPS_SetName($this->GetIDForIdent('Status_' . $sid), 'Status ' . $name);
                IPS_SetName($this->GetIDForIdent('Effizienz_' . $sid), 'Effizienz ' . $name);
                IPS_SetName($this->GetIDForIdent('StartFeuchte_' . $sid), 'StartFeuchte ' . $name);
                IPS_SetName($this->GetIDForIdent('Dauer_' . $sid), 'Dauer ' . $name);
                IPS_SetName($this->GetIDForIdent('SickerpauseStart_' . $sid), 'SickerpauseStart ' . $name);
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

        // 1. Prüfen, ob bereits ein Ventil aktiv ist oder Zonen in der Warteschlange stehen
        $einVentilIstAktiv = false;
        $anyQueued = false;
        foreach ($zones as $zone) {
            $status = GetValue($this->GetIDForIdent('Status_' . $zone['SensorID']));
            if ($status === 'WATERING' || $status === 'VERIFYING_START' || $status === 'WAITING_FOR_RESULT') {
                $einVentilIstAktiv = true;
                $this->SendDebug('Sequencer', 'Ein anderes Ventil blockiert die Sequenz (' . $status . ' bei Zone ' . $zone['SensorID'] . '). Warte...', 0);
            }
            if ($status === 'QUEUED') {
                $anyQueued = true;
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

        // 3. Prüfen, ob ein neuer Bewässerungszyklus gestartet werden muss
        $automaticActive = GetValue($this->GetIDForIdent('AutomaticActive'));

        $isManualStart = ($this->GetBuffer('CalculatePlanPending') === 'true');
        if ($isManualStart) {
            $this->SetBuffer('CalculatePlanPending', '');
        }

        $newCycleTriggered = false;
        if ($isManualStart) {
            $newCycleTriggered = true;
        } else if (!$einVentilIstAktiv && !$anyQueued) {
            // Prüfen, ob mindestens eine Zone Trockenstress hat
            foreach ($zones as $zone) {
                $startWert = ($zone['CustomStart'] > 0) ? $zone['CustomStart'] : $defaultStart;
                $aktuelleFeuchte = GetValue($zone['SensorID']);
                if ($automaticActive && $aktuelleFeuchte <= $startWert) {
                    $newCycleTriggered = true;
                    break;
                }
            }
        }

        if ($newCycleTriggered) {
            $this->SendDebug('Planer', 'Neuer Bewässerungszyklus initiiert. Berechne Laufzeiten...', 0);
            $this->CalculateAndApplyPlan($zones, $isManualStart, $vpd, $lux);
            
            // Status nach Berechnungsdurchlauf neu einlesen
            $einVentilIstAktiv = false;
            foreach ($zones as $zone) {
                $status = GetValue($this->GetIDForIdent('Status_' . $zone['SensorID']));
                if ($status === 'WATERING' || $status === 'VERIFYING_START' || $status === 'WAITING_FOR_RESULT') {
                    $einVentilIstAktiv = true;
                }
            }
        }

        // 4. Zonen-Durchlauf (State Machine)
        foreach ($zones as $zone) {
            $zielWert  = ($zone['CustomZiel'] > 0) ? $zone['CustomZiel'] : $defaultZiel;
            $startWert = ($zone['CustomStart'] > 0) ? $zone['CustomStart'] : $defaultStart;
            
            $aktuelleFeuchte = GetValue($zone['SensorID']);
            $aktuellerStatus = GetValue($this->GetIDForIdent('Status_' . $zone['SensorID']));
            if (empty($aktuellerStatus)) {
                $aktuellerStatus = 'IDLE';
            }
            $this->SendDebug('ProcessLogic', 'Bearbeite Zone ' . $zone['SensorID'] . ' (Aktueller Status: ' . $aktuellerStatus . ')', 0);

            // Gardena Not-Aus Check
            if (isset($zone['HardwareStatusID']) && $zone['HardwareStatusID'] > 0) {
                $hwStatus = GetValue($zone['HardwareStatusID']);
                $this->SendDebug('Hardware-Check', 'Zone ' . $zone['SensorID'] . ' HW-Status: ' . print_r($hwStatus, true) . ' (Typ: ' . gettype($hwStatus) . ')', 0);
                
                $hwStr = strtoupper((string)$hwStatus);
                if (in_array($hwStr, ['ERROR', 'WARNING', 'OFFLINE', 'DEFECT', 'FAULT'])) {
                    IPS_LogMessage('SmartLawnAI', 'HARDWARE_FEHLER für Zone ' . $zone['SensorID'] . '! Status-Variable (' . $zone['HardwareStatusID'] . ') meldet einen Defekt: ' . print_r($hwStatus, true));
                    SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'HARDWARE_FEHLER');
                    continue; 
                }
            }

            switch ($aktuellerStatus) {
                case 'IDLE':
                case 'QUEUED':
                    $sollStarten = ($aktuellerStatus === 'QUEUED');

                    if ($sollStarten) {
                        if ($einVentilIstAktiv) {
                            $this->SendDebug('Sequencer', 'Zone ' . $zone['SensorID'] . ' bleibt QUEUED, da ein anderes Ventil aktiv ist.', 0);
                            SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'QUEUED');
                        } else {
                            $this->SendDebug('Sequencer', 'Startbedingung erfüllt. Starte Zone ' . $zone['SensorID'] . ' (VERIFYING_START).', 0);
                            IPS_LogMessage('SmartLawnAI', 'Bewässerung für Zone ' . $zone['SensorID'] . ' wird gestartet!');
                            SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'VERIFYING_START');
                            
                            // Berechnete Laufzeit aus Variable lesen
                            $berechneteMinuten = (int)GetValue($this->GetIDForIdent('Dauer_' . $zone['SensorID']));
                            if ($berechneteMinuten <= 0) {
                                $this->SendDebug('Sequencer', 'Zone ' . $zone['SensorID'] . ' hat keine gültige Dauer. Überspringe.', 0);
                                SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'IDLE');
                                continue;
                            }

                            // Gardena Hardware-Watchdog: Dauer setzen
                            if ($zone['DurationID'] > 0) {
                                @RequestAction($zone['DurationID'], $berechneteMinuten);
                                IPS_Sleep(500); 
                            }

                            // Start-Befehl senden (Gardena spezifisch)
                            @RequestAction($zone['ValveID'], 'START_SECONDS_TO_OVERRIDE');
                            
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
                    $ventilOffen = false;
                    $hwVal = 'UNKNOWN';
                    if (isset($zone['HardwareStatusID']) && $zone['HardwareStatusID'] > 0) {
                        $hwVal = strtoupper((string)GetValue($zone['HardwareStatusID']));
                        $ventilOffen = in_array($hwVal, ['MANUAL_WATERING', 'AUTOMATIC_WATERING', 'WATERING', 'OPEN']);
                    } else {
                        $v = GetValue($zone['ValveID']);
                        $hwVal = (string)$v;
                        $ventilOffen = ($v && $v !== 'STOP_UNTIL_NEXT_TASK' && $v !== 'CLOSED');
                    }
                    
                    // Fallback: Wenn Sekunden noch > 0 sind, läuft es definitiv noch!
                    if (!$ventilOffen && isset($zone['RemainingSecondsID']) && $zone['RemainingSecondsID'] > 0) {
                        if ((int)GetValue($zone['RemainingSecondsID']) > 0) {
                            $ventilOffen = true;
                            $hwVal .= ' (Kept alive by RemainingSeconds > 0)';
                        }
                    }
                    
                    if ($ventilOffen && $aktuellerStatus === 'VERIFYING_START') {
                        SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'WATERING');
                    } elseif (!$ventilOffen && $aktuellerStatus === 'WATERING') {
                        IPS_LogMessage('SmartLawnAI', 'Zone ' . $zone['SensorID'] . ' ist scheinbar fertig. Letzter Hardware-Status war: ' . $hwVal);
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

    public function UIRequest(string $Action, string $Payload) {
        switch ($Action) {
            case 'TOGGLE_AUTOMATIC':
                $id = $this->GetIDForIdent('AutomaticActive');
                $newVal = !GetValue($id);
                SetValue($id, $newVal);
                if (!$newVal) {
                    $this->resetAllZones(false);
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
                    $hwStr = strtoupper((string)$hwVal);
                    // In der UI geben wir HardwareOk = false nur aus, wenn es wirklich ein Fehler ist.
                    if (!in_array($hwStr, ['ERROR', 'WARNING', 'OFFLINE', 'DEFECT', 'FAULT'])) {
                        $hwStatus = true;
                    }
                } else {
                    $hwStatus = true;
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
                    'remainingSeconds' => (isset($zone['RemainingSecondsID']) && $zone['RemainingSecondsID'] > 0) ? (int)GetValue($zone['RemainingSecondsID']) : 0
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

    private function isZoneHardwareOk(array $zone): bool {
        if (isset($zone['HardwareStatusID']) && $zone['HardwareStatusID'] > 0) {
            $hwStatus = GetValue($zone['HardwareStatusID']);
            $hwStr = strtoupper((string)$hwStatus);
            if (in_array($hwStr, ['ERROR', 'WARNING', 'OFFLINE', 'DEFECT', 'FAULT'])) {
                return false;
            }
        }
        return true;
    }

    private function CalculateAndApplyPlan(array $zones, bool $isManualStart, float $vpd, float $lux) {
        $apiKey = $this->ReadPropertyString('GeminiApiKey');
        $model = $this->ReadPropertyString('GeminiModel');
        if (empty($model)) {
            $model = 'gemini-3.5-flash';
        }

        if (empty($apiKey)) {
            $this->SendDebug('Planer', 'Kein Gemini API-Schlüssel konfiguriert. Abbruch.', 0);
            IPS_LogMessage('SmartLawnAI', 'Kein Gemini API-Schlüssel konfiguriert. Bewässerungsplan kann nicht berechnet werden.');
            return;
        }

        $soilTempID = $this->ReadPropertyInteger('GlobalSoilTempID');
        $soilTemp = ($soilTempID > 0) ? (float)GetValue($soilTempID) : null;
        
        // Frostschutz Check
        if ($soilTemp !== null && $soilTemp < 5.0) {
            $this->SendDebug('Planer', 'Frostschutz aktiv: Bodentemperatur beträgt ' . $soilTemp . ' °C (< 5 °C). Alle Zonen blockiert.', 0);
            IPS_LogMessage('SmartLawnAI', 'Frostschutz aktiv: Bodentemperatur < 5 °C. Keine Bewässerung gestartet.');
            foreach ($zones as $zone) {
                $sid = $zone['SensorID'];
                SetValue($this->GetIDForIdent('Status_' . $sid), 'IDLE');
            }
            return;
        }

        $forecastID = $this->ReadPropertyInteger('GlobalWeatherForecastID');
        $forecast = null;
        if ($forecastID > 0) {
            $forecastVal = GetValue($forecastID);
            $decoded = json_decode($forecastVal, true);
            $forecast = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $forecastVal;
        }

        $ambientContext = [
            'airTemperatureCelsius' => ($this->ReadPropertyInteger('GlobalAirTempID') > 0) ? (float)GetValue($this->ReadPropertyInteger('GlobalAirTempID')) : 20.0,
            'relativeHumidityPercent' => ($this->ReadPropertyInteger('GlobalHumidityID') > 0) ? (float)GetValue($this->ReadPropertyInteger('GlobalHumidityID')) : 50.0,
            'illuminanceLux' => $lux,
            'vaporPressureDeficitKpa' => $vpd,
            'manualStartTriggered' => $isManualStart,
            'timestamp' => time()
        ];
        if ($soilTemp !== null) {
            $ambientContext['soilTemperatureCelsius'] = $soilTemp;
        }
        if ($forecast !== null) {
            $ambientContext['weatherForecast'] = $forecast;
        }

        $defaultZiel = $this->ReadPropertyFloat('DefaultZielFeuchte');
        $defaultStart = $this->ReadPropertyFloat('DefaultStartSchwellwert');

        $zonesContext = [];
        foreach ($zones as $zone) {
            $sid = $zone['SensorID'];
            if (!$this->isZoneHardwareOk($zone)) {
                $this->SendDebug('Planer', 'Zone ' . $sid . ' übersprungen (Hardware-Fehler).', 0);
                SetValue($this->GetIDForIdent('Status_' . $sid), 'HARDWARE_FEHLER');
                continue;
            }

            $zielWert  = ($zone['CustomZiel'] > 0) ? $zone['CustomZiel'] : $defaultZiel;
            $startWert = ($zone['CustomStart'] > 0) ? $zone['CustomStart'] : $defaultStart;
            $aktuelleFeuchte = GetValue($sid);
            $effizienz = (float)GetValue($this->GetIDForIdent('Effizienz_' . $sid));
            if ($effizienz <= 0) $effizienz = 1.0;
            $maxDuration = isset($zone['MaxDuration']) && $zone['MaxDuration'] > 0 ? (int)$zone['MaxDuration'] : 30;

            $zonesContext[] = [
                'valveId' => (int)$zone['ValveID'],
                'sensorId' => (int)$sid,
                'groupName' => isset($zone['GroupName']) ? $zone['GroupName'] : ('Zone ' . $sid),
                'currentMoisturePercent' => $aktuelleFeuchte,
                'targetMoisturePercent' => $zielWert,
                'startMoisturePercent' => $startWert,
                'learnedEfficiencyPercentPerMinute' => $effizienz,
                'maxDurationMinutes' => $maxDuration
            ];
        }

        if (empty($zonesContext)) {
            $this->SendDebug('Planer', 'Keine betriebsbereiten Zonen gefunden.', 0);
            return;
        }

        // 2. Prompt und Instruktion für Gemini erstellen
        $userPrompt = "Erstelle den optimalen Bewässerungsplan.\n\n";
        $userPrompt .= "UMGEBUNGSDATEN & VORHERSAGE:\n" . json_encode($ambientContext, JSON_PRETTY_PRINT) . "\n\n";
        $userPrompt .= "ZONEN MIT SENSORIK UND VENTILEN:\n" . json_encode($zonesContext, JSON_PRETTY_PRINT) . "\n\n";
        $userPrompt .= "Berücksichtige bei der Laufzeitberechnung:\n";
        $userPrompt .= "- Ist die Bodentemperatur zu niedrig, kühle den Boden nicht weiter ab.\n";
        $userPrompt .= "- Nutze Helligkeit und Luftfeuchte, um die aktuelle Verdunstungsrate abzuschätzen.\n";
        $userPrompt .= "- Berechne für JEDE 'valveId' die exakte Laufzeit in Minuten (0 bis maxDurationMinutes).\n";
        
        $systemInstruction = "Du bist ein präzises Steuerungsmodul für Agrarsysteme. Deine Aufgabe ist es, für die übergebenen Ventil-IDs Laufzeiten in Minuten zu berechnen. Antworte ausschließlich im vorgegebenen JSON-Format.";

        // 3. API-Aufruf (Gemini mit striktem JSON Schema)
        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $model . ":generateContent?key=" . $apiKey;

        $responseSchema = [
            'type' => 'OBJECT',
            'properties' => [
                'irrigationPlan' => [
                    'type' => 'ARRAY',
                    'description' => 'Liste der berechneten Bewässerungszeiten pro Ventil.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'valveId' => [
                                'type' => 'INTEGER',
                                'description' => 'Die physische ID des Ventils.'
                            ],
                            'durationMinutes' => [
                                'type' => 'INTEGER',
                                'description' => 'Die exakte Bewässerungsdauer in Minuten (0 falls nicht bewässert werden soll).'
                            ],
                            'reasoning' => [
                                'type' => 'STRING',
                                'description' => 'Kurze agronomische Begründung für diese Entscheidung.'
                            ]
                        ],
                        'required' => ['valveId', 'durationMinutes', 'reasoning']
                    ]
                ]
            ],
            'required' => ['irrigationPlan']
        ];

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $userPrompt]
                    ]
                ]
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $responseSchema
            ]
        ];

        $this->SendDebug('Planer', 'Gemini Anfrage gesendet (Modell: ' . $model . ')...', 0);
        $this->SendDebug('Planer Prompt', $userPrompt, 0);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            $this->SendDebug('Planer Fehler', 'Gemini API call failed. HTTP Code: ' . $httpCode . ', Curl-Fehler: ' . $curlErr, 0);
            IPS_LogMessage('SmartLawnAI', 'Gemini API-Aufruf fehlgeschlagen. Abbruch.');
            return;
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $this->SendDebug('Planer Fehler', 'Ungültiges API-Response-Format.', 0);
            return;
        }

        $rawText = $result['candidates'][0]['content']['parts'][0]['text'];
        $this->SendDebug('Planer Antwort', $rawText, 0);

        $planData = json_decode($rawText, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($planData['irrigationPlan']) || !is_array($planData['irrigationPlan'])) {
            $this->SendDebug('Planer Fehler', 'Plan-JSON konnte nicht geparst werden.', 0);
            return;
        }

        // Apply Gemini calculations
        $planByValve = [];
        foreach ($planData['irrigationPlan'] as $item) {
            if (isset($item['valveId'])) {
                $planByValve[(int)$item['valveId']] = $item;
            }
        }

        foreach ($zones as $zone) {
            $sid = $zone['SensorID'];
            if (!$this->isZoneHardwareOk($zone)) {
                continue;
            }

            $valveId = (int)$zone['ValveID'];
            if (isset($planByValve[$valveId])) {
                $duration = (int)$planByValve[$valveId]['durationMinutes'];
                $reasoning = $planByValve[$valveId]['reasoning'];
                
                $maxDuration = isset($zone['MaxDuration']) && $zone['MaxDuration'] > 0 ? (int)$zone['MaxDuration'] : 30;
                if ($duration > $maxDuration) {
                    $duration = $maxDuration;
                }

                SetValue($this->GetIDForIdent('Dauer_' . $sid), $duration);
                if ($duration > 0) {
                    SetValue($this->GetIDForIdent('Status_' . $sid), 'QUEUED');
                    $this->SendDebug('Planer', 'Zone ' . $sid . ' eingereiht (Gemini): ' . $duration . ' Minuten. Begründung: ' . $reasoning, 0);
                } else {
                    SetValue($this->GetIDForIdent('Status_' . $sid), 'IDLE');
                    $this->SendDebug('Planer', 'Zone ' . $sid . ' nicht eingereiht (Gemini Dauer = 0). Begründung: ' . $reasoning, 0);
                }
            } else {
                SetValue($this->GetIDForIdent('Status_' . $sid), 'IDLE');
                SetValue($this->GetIDForIdent('Dauer_' . $sid), 0);
                $this->SendDebug('Planer', 'Zone ' . $sid . ' nicht im Gemini Plan enthalten. Gesetzt auf IDLE.', 0);
            }
        }
    }
}