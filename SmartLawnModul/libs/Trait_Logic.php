<?php

trait SmartLawnAI_Logic {

    public function ScheduledEvaluation(): void {
        $active = GetValue($this->GetIDForIdent('AutomaticActive'));
        if (!$active) return;
        
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (!is_array($zones) || empty($zones)) return;
        
        $sprinklersJson = $this->ReadPropertyString('Sprinklers');
        $sprinklers = json_decode($sprinklersJson, true);
        if (!is_array($sprinklers)) $sprinklers = [];
        
        // Prüfen, ob bereits ein Ventil aktiv ist
        foreach ($zones as $zone) {
            $status = GetValue($this->GetIDForIdent('Status_' . $zone['SensorID']));
            if ($status === 'WATERING' || $status === 'QUEUED') {
                $this->LogAndDebug('Planer', 'Zyklusprüfung übersprungen: Ein Ventil ist bereits aktiv oder in Warteschlange.', 0);
                return;
            }
        }

        $defaultStart = GetValue($this->GetIDForIdent('DefaultStartSchwellwert'));
        $needsWater = false;
        foreach ($zones as $zone) {
            $aktuelleFeuchte = GetValue($zone['SensorID']);
            if ($aktuelleFeuchte <= $defaultStart) {
                $needsWater = true;
                break;
            }
        }

        if (!$needsWater) {
            $this->LogAndDebug('Planer', 'Zyklusprüfung: Boden ist ausreichend feucht. Keine Bewässerung nötig.', 0);
            // Wir setzen den Zeitstempel für das Webfront neu, um zu zeigen, dass wir geprüft haben
            $this->SetBuffer('LastPlanCalculation', (string)time());
            $this->ProcessLogic(); // Update Heartbeat
            return;
        }

        $this->LogAndDebug('Planer', 'Zyklusprüfung: Boden ist trocken. Hole Wetter und berechne Laufzeiten...', 0);
        $this->UpdateWeather();
        
        $airTempID = $this->ReadPropertyInteger('GlobalAirTempID');
        $humidityID = $this->ReadPropertyInteger('GlobalHumidityID');
        $illuminanceID = $this->ReadPropertyInteger('GlobalIlluminanceID');
        $t = ($airTempID > 0) ? (float)GetValue($airTempID) : 20.0;
        $rh = ($humidityID > 0) ? (float)GetValue($humidityID) : 50.0;
        $lux = ($illuminanceID > 0) ? (float)GetValue($illuminanceID) : 0.0;
        $es = 0.6108 * exp((17.27 * $t) / ($t + 237.3));
        $vpd = $es * (1 - ($rh / 100.0));

        $this->SetBuffer('LastPlanCalculation', (string)time());
        $this->CalculateAndApplyPlan($zones, $sprinklers, false, $vpd, $lux);
        
        $this->ProcessLogic(); // Update Heartbeat und Starte Zonen-Durchlauf
    }

    public function ProcessLogic(): void {
        $defaultZiel  = GetValue($this->GetIDForIdent('DefaultZielFeuchte'));
        $defaultStart = GetValue($this->GetIDForIdent('DefaultStartSchwellwert'));
        
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        
        $sprinklersJson = $this->ReadPropertyString('Sprinklers');
        $sprinklers = json_decode($sprinklersJson, true);
        if (!is_array($sprinklers)) $sprinklers = [];
        
        if (!is_array($zones) || empty($zones)) {
            return; 
        }

        // 1. Prüfen, ob bereits ein Ventil aktiv ist oder Zonen in der Warteschlange stehen
        $einVentilIstAktiv = false;
        $anyQueued = false;
        foreach ($zones as $zone) {
            $status = GetValue($this->GetIDForIdent('Status_' . $zone['SensorID']));
            if ($status === 'WATERING') {
                $einVentilIstAktiv = true;
                $this->LogAndDebug('Sequencer', 'Ein anderes Ventil blockiert die Sequenz (' . $status . ' bei Zone ' . $zone['SensorID'] . '). Warte...', 0);
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

        // 3. Laufzeit-Steuerung des Timers
        $active = GetValue($this->GetIDForIdent('AutomaticActive'));
        if ($active) {
            $this->SetTimerInterval('LawnAITimer', 60000);
        } else {
            $this->SetTimerInterval('LawnAITimer', 0);
        }

        // Manueller Start
        $isManualStart = ($this->GetBuffer('CalculatePlanPending') === 'true');
        if ($isManualStart) {
            $this->SetBuffer('CalculatePlanPending', '');
            $this->LogAndDebug('Planer', 'Neuer Bewässerungszyklus (manuell) initiiert. Berechne Laufzeiten...', 0);
            
            // Wenn keine Koordinaten, UpdateWeather hat keinen Effekt, aber sicherheitshalber aufrufen
            $this->UpdateWeather();
            
            $this->CalculateAndApplyPlan($zones, $sprinklers, true, $vpd, $lux);
            
            $einVentilIstAktiv = false;
            foreach ($zones as $zone) {
                $status = GetValue($this->GetIDForIdent('Status_' . $zone['SensorID']));
                if ($status === 'WATERING') {
                    $einVentilIstAktiv = true;
                }
            }
        }

        // 4. Zonen-Durchlauf (State Machine)
        foreach ($zones as $zone) {
            $zielWert  = $defaultZiel;
            $startWert = $defaultStart;
            
            $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone ' . $zone['SensorID'];
            $zoneSprinklers = [];
            foreach ($sprinklers as $s) {
                if ($s['ZoneName'] === $zoneName) {
                    $zoneSprinklers[] = $s;
                }
            }

            $aktuelleFeuchte = GetValue($zone['SensorID']);
            $aktuellerStatus = GetValue($this->GetIDForIdent('Status_' . $zone['SensorID']));
            if (empty($aktuellerStatus)) {
                $aktuellerStatus = 'IDLE';
            }
            $this->LogAndDebug('ProcessLogic', 'Bearbeite Zone ' . $zone['SensorID'] . ' (Aktueller Status: ' . $aktuellerStatus . ')', 0);

            if (empty($zoneSprinklers)) {
                $this->LogAndDebug('ProcessLogic', 'Zone ' . $zone['SensorID'] . ' hat keine zugeordneten Sprinkler. Überspringe.', 0);
                continue;
            }

            // Gardena Not-Aus Check (prüfe alle Sprinkler dieser Zone)
            $hardwareFehler = false;
            $fehlerhafterSprinklerName = '';
            foreach ($zoneSprinklers as $s) {
                if (isset($s['HardwareStatusID']) && $s['HardwareStatusID'] > 0) {
                    $hwStatus = GetValue($s['HardwareStatusID']);
                    $hwStr = strtoupper((string)$hwStatus);
                    if (in_array($hwStr, ['ERROR', 'WARNING', 'OFFLINE', 'DEFECT', 'FAULT'])) {
                        $sName = isset($s['SprinklerName']) && !empty($s['SprinklerName']) ? $s['SprinklerName'] : 'Sprinkler ' . $s['ValveID'];
                        $this->LogAndDebug('Hardware-Check', 'Zone ' . $zone['SensorID'] . ' ' . $sName . ' meldet Fehler: ' . $hwStr, 0);
                        $hardwareFehler = true;
                        $fehlerhafterSprinklerName = $sName;
                        break;
                    }
                }
            }
            if ($hardwareFehler) {
                IPS_LogMessage('SmartLawnAI', 'HARDWARE_FEHLER für Zone ' . $zone['SensorID'] . '! ' . $fehlerhafterSprinklerName . ' meldet einen Defekt.');
                $this->SetValue('Status_' . $zone['SensorID'], 'HARDWARE_FEHLER');
                $this->SetSummaryStatus('HARDWARE-FEHLER: ' . $zoneName . ' (' . $fehlerhafterSprinklerName . ')');
                continue; 
            }

            $currentIndexVarId = $this->GetIDForIdent('CurrentSprinklerIndex_' . $zone['SensorID']);
            $currentIndex = (int)GetValue($currentIndexVarId);
            if (!isset($zoneSprinklers[$currentIndex])) {
                $currentIndex = 0;
            }
            $currentSprinkler = $zoneSprinklers[$currentIndex];
            $currentSprinklerName = isset($currentSprinkler['SprinklerName']) && !empty($currentSprinkler['SprinklerName']) ? $currentSprinkler['SprinklerName'] : 'Sprinkler ' . $currentSprinkler['ValveID'];

            switch ($aktuellerStatus) {
                case 'IDLE':
                case 'QUEUED':
                    $sollStarten = ($aktuellerStatus === 'QUEUED');

                    if ($sollStarten) {
                        if ($einVentilIstAktiv) {
                            $this->LogAndDebug('Sequencer', 'Zone ' . $zone['SensorID'] . ' bleibt QUEUED, da ein anderes Ventil aktiv ist.', 0);
                            $this->SetValue('Status_' . $zone['SensorID'], 'QUEUED');
                        } else {
                            $this->LogAndDebug('Sequencer', 'Startbedingung erfüllt. Starte Zone ' . $zone['SensorID'] . ' (WATERING).', 0);
                            IPS_LogMessage('SmartLawnAI', 'Bewässerung für Zone ' . $zone['SensorID'] . ' wird gestartet!');
                            $this->SetValue('Status_' . $zone['SensorID'], 'WATERING');
                            $this->SetValue('WateringStart_' . $zone['SensorID'], time());
                            $this->SetSummaryStatus('Bewässere: ' . $zoneName);
                            
                            // Berechnete Laufzeit aus Variable lesen
                            $berechneteMinuten = (int)GetValue($this->GetIDForIdent('Dauer_' . $zone['SensorID']));
                            if ($berechneteMinuten <= 0) {
                                $this->LogAndDebug('Sequencer', 'Zone ' . $zone['SensorID'] . ' hat keine gültige Dauer. Überspringe.', 0);
                                $this->SetValue('Status_' . $zone['SensorID'], 'IDLE');
                                continue 2;
                            }

                            // Gardena Hardware-Watchdog: Dauer setzen
                            if ($currentSprinkler['DurationID'] > 0) {
                                @RequestAction($currentSprinkler['DurationID'], $berechneteMinuten);
                                IPS_Sleep(500); 
                            }

                            // Start-Befehl senden (Gardena spezifisch)
                            @RequestAction($currentSprinkler['ValveID'], 'START_SECONDS_TO_OVERRIDE');
                            
                            // Zwischenspeichern für den Lern-Algorithmus später
                            $this->SetValue('StartFeuchte_' . $zone['SensorID'], $aktuelleFeuchte);
                            $this->SetValue('Dauer_' . $zone['SensorID'], $berechneteMinuten);
                            
                            $einVentilIstAktiv = true; 
                        }
                    } else {
                        $this->SetValue('Status_' . $zone['SensorID'], 'IDLE');
                    }
                    break;
                    
                case 'WATERING':
                    // Ventil-Rückkanal von Gardena prüfen
                    $ventilOffen = false;
                    $hwVal = 'UNKNOWN';
                    if (isset($currentSprinkler['HardwareStatusID']) && $currentSprinkler['HardwareStatusID'] > 0) {
                        $hwVal = strtoupper((string)GetValue($currentSprinkler['HardwareStatusID']));
                        $ventilOffen = in_array($hwVal, ['MANUAL_WATERING', 'AUTOMATIC_WATERING', 'WATERING', 'OPEN']);
                    } else {
                        $v = GetValue($currentSprinkler['ValveID']);
                        $hwVal = (string)$v;
                        $ventilOffen = ($v && $v !== 'STOP_UNTIL_NEXT_TASK' && $v !== 'CLOSED');
                    }
                    
                    // Fallback: Wenn Sekunden noch > 0 sind, läuft es definitiv noch!
                    if (!$ventilOffen && isset($currentSprinkler['RemainingSecondsID']) && $currentSprinkler['RemainingSecondsID'] > 0) {
                        if ((int)GetValue($currentSprinkler['RemainingSecondsID']) > 0) {
                            $ventilOffen = true;
                            $hwVal .= ' (Kept alive by RemainingSeconds > 0)';
                        }
                    }

                    $wateringStart = (int)GetValue($this->GetIDForIdent('WateringStart_' . $zone['SensorID']));
                    
                    // Grace Period
                    $gracePeriod = $this->ReadPropertyInteger('HardwareGracePeriod');
                    if ($gracePeriod <= 0) $gracePeriod = 90;
                    
                    if (!$ventilOffen && $wateringStart > 0 && (time() - $wateringStart) < $gracePeriod) {
                        $ventilOffen = true;
                        $hwVal .= ' (Grace Period Active: ' . $gracePeriod . 's)';
                    }
                    
                    $remaining = 0;
                    if (isset($currentSprinkler['RemainingSecondsID']) && $currentSprinkler['RemainingSecondsID'] > 0) {
                        $remaining = (int)GetValue($currentSprinkler['RemainingSecondsID']);
                    } else {
                        $timerID = $this->GetIDForIdent('ValveSequenceTimer');
                        if (IPS_GetTimerInterval($timerID) > 0) {
                            $remaining = max(0, IPS_GetTimerInterval($timerID) - time());
                        }
                    }
                    $remainingText = $remaining > 0 ? ' (noch ' . ceil($remaining / 60) . ' Min)' : '';

                    if (!$ventilOffen && $aktuellerStatus === 'WATERING') {
                        IPS_LogMessage('SmartLawnAI', $currentSprinklerName . ' in Zone ' . $zone['SensorID'] . ' ist fertig. Hardware-Status: ' . $hwVal);
                        
                        $currentIndex++;
                        if ($currentIndex < count($zoneSprinklers)) {
                            // Nächster Sprinkler in dieser Zone
                            $this->SetValue('CurrentSprinklerIndex_' . $zone['SensorID'],  $currentIndex);
                            $this->SetValue('Status_' . $zone['SensorID'], 'QUEUED');
                            $this->LogAndDebug('Sequencer', 'Sprinkler gewechselt. Nächster Index: ' . $currentIndex, 0);
                        } else {
                            // Alle Sprinkler der Zone fertig
                            $this->SetValue('CurrentSprinklerIndex_' . $zone['SensorID'],  0); // Reset
                            $this->SetValue('Status_' . $zone['SensorID'], 'WAITING_FOR_RESULT');
                            $this->SetValue('SickerpauseStart_' . $zone['SensorID'], time());
                            $this->LogAndDebug('Sequencer', 'Alle Sprinkler fertig. Sickerpause gestartet.', 0);
                            $this->SetSummaryStatus('Sickerpause: ' . $zoneName);
                        }
                    } elseif ($aktuellerStatus === 'WATERING') {
                        // Aktualisiere den Text mit der verbleibenden Zeit während der Bewässerung
                        $this->SetSummaryStatus('Bewässert: ' . $zoneName . ' (' . $currentSprinklerName . ')' . $remainingText);
                    }
                    break;

                case 'WAITING_FOR_RESULT':
                    $sickerStart = (int)GetValue($this->GetIDForIdent('SickerpauseStart_' . $zone['SensorID']));
                    // Sickerpause in Sekunden abwarten
                    $sickerpauseSek = GetValue($this->GetIDForIdent('SickerpauseMinuten')) * 60;
                    if ((time() - $sickerStart) > $sickerpauseSek) {
                        
                        // Lernerfolg auswerten via Gemini
                        $startFeuchte = (float)GetValue($this->GetIDForIdent('StartFeuchte_' . $zone['SensorID']));
                        $dauer = (int)GetValue($this->GetIDForIdent('Dauer_' . $zone['SensorID']));
                        
                        if ($dauer > 0) {
                            $this->EvaluateEfficiencyWithGemini($zone['SensorID'], $startFeuchte, $aktuelleFeuchte, $dauer, $vpd, $lux);
                        }

                        $this->SetValue('Status_' . $zone['SensorID'], 'IDLE');
                        $this->SetSummaryStatus('Standby (Bewässerung abgeschlossen)');
                    }
                    break;
            }
        }

        // 5. Heartbeat für die Webfront Anzeige (Zeitstempel aktualisieren)
        $automaticActive = GetValue($this->GetIDForIdent('AutomaticActive'));
        if ($automaticActive) {
            $einVentilIstAktivOderFehler = false;
            foreach ($zones as $zone) {
                $status = GetValue($this->GetIDForIdent('Status_' . $zone['SensorID']));
                if (in_array($status, ['WATERING', 'QUEUED', 'WAITING_FOR_RESULT', 'HARDWARE_FEHLER'])) {
                    $einVentilIstAktivOderFehler = true;
                    break;
                }
            }

            $currentStatus = GetValue($this->GetIDForIdent('SummaryStatus'));
            // Entferne alten Zeitstempel, falls vorhanden
            $baseStatus = preg_replace('/ \(\d{2}:\d{2}\)$/', '', $currentStatus);
            
            if (!$einVentilIstAktivOderFehler && strpos($baseStatus, 'Berechne') === false && strpos($baseStatus, 'Manueller Start') === false) {
                $naechsteUeberpruefung = $this->GetNextScheduleTime();
                $baseStatus = 'Nächste Prüfung: ' . date('H:i', $naechsteUeberpruefung) . ' Uhr';
            }
            
            $this->SetSummaryStatus($baseStatus);
        }

        // Live-Update der Visualisierung pushen
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
    }

    private function CalculateAndApplyPlan($zones, $sprinklers, $isManualStart, $vpd, $lux) {
        $this->SetSummaryStatus('Berechne Bewässerungsplan (Gemini AI)...');
        $apiKey = trim($this->ReadPropertyString('GeminiApiKey'));
        $model = $this->ReadPropertyString('GeminiModel');
        if (empty($model)) {
            $model = 'gemini-1.5-flash';
        }

        if (empty($apiKey)) {
            $this->LogAndDebug('Planer', 'Kein Gemini API-Schlüssel konfiguriert. Abbruch.', 0);
            IPS_LogMessage('SmartLawnAI', 'Kein Gemini API-Schlüssel konfiguriert. Bewässerungsplan kann nicht berechnet werden.');
            $this->SetSummaryStatus('Fehler: Kein Gemini API-Schlüssel');
            return;
        }

        $ambientContext = [
            'airTemperatureCelsius' => ($this->ReadPropertyInteger('GlobalAirTempID') > 0) ? (float)GetValue($this->ReadPropertyInteger('GlobalAirTempID')) : 20.0,
            'relativeHumidityPercent' => ($this->ReadPropertyInteger('GlobalHumidityID') > 0) ? (float)GetValue($this->ReadPropertyInteger('GlobalHumidityID')) : 50.0,
            'illuminanceLux' => $lux,
            'vaporPressureDeficitKpa' => $vpd,
            'manualStartTriggered' => $isManualStart,
            'timestamp' => time()
        ];
        
        $rainToday = GetValue($this->GetIDForIdent('ForecastRainToday'));
        $rainTomorrow = GetValue($this->GetIDForIdent('ForecastRainTomorrow'));
        $ambientContext['weatherForecast'] = "Erwartete Regenmenge: Heute $rainToday mm, Morgen $rainTomorrow mm";

        $defaultZiel = GetValue($this->GetIDForIdent('DefaultZielFeuchte'));
        $defaultStart = GetValue($this->GetIDForIdent('DefaultStartSchwellwert'));

        $zonesContext = [];
        foreach ($zones as $zone) {
            $sid = $zone['SensorID'];
            if (!$this->isZoneHardwareOk($zone, $sprinklers)) {
                $this->LogAndDebug('Planer', 'Zone ' . $sid . ' übersprungen (Hardware-Fehler).', 0);
                $this->SetValue('Status_' . $sid, 'HARDWARE_FEHLER');
                continue;
            }

            $zielWert  = $defaultZiel;
            $startWert = $defaultStart;
            $aktuelleFeuchte = GetValue($sid);

            // ERZWINGE EREIGNISSTEUERUNG:
            // Zone nur beplanen, wenn manueller Start oder Trigger-Schwellwert erreicht!
            if (!$isManualStart && $aktuelleFeuchte > $startWert) {
                $this->LogAndDebug('Planer', 'Zone ' . $sid . ' ignoriert. Feuchte (' . $aktuelleFeuchte . '%) liegt über dem Trigger (' . $startWert . '%).', 0);
                continue;
            }

            $effizienz = (float)GetValue($this->GetIDForIdent('Effizienz_' . $sid));
            if ($effizienz <= 0) $effizienz = 1.0;
            $maxDuration = GetValue($this->GetIDForIdent('GlobalMaxDuration'));

            $zonesContext[] = [
                'zoneId' => (int)$sid,
                'groupName' => isset($zone['GroupName']) ? $zone['GroupName'] : ('Zone ' . $sid),
                'currentMoisturePercent' => $aktuelleFeuchte,
                'targetMoisturePercent' => $zielWert,
                'startMoisturePercent' => $startWert,
                'learnedEfficiencyPercentPerMinute' => $effizienz,
                'maxDurationMinutes' => $maxDuration
            ];
        }

        if (empty($zonesContext)) {
            $this->LogAndDebug('Planer', 'Keine betriebsbereiten Zonen gefunden.', 0);
            return;
        }

        // 2. Prompt und Instruktion für Gemini erstellen
        $userPrompt = "Erstelle den optimalen Bewässerungsplan.\n\n";
        $userPrompt .= "UMGEBUNGSDATEN & VORHERSAGE:\n" . json_encode($ambientContext, JSON_PRETTY_PRINT) . "\n\n";
        $userPrompt .= "ZONEN MIT SENSORIK UND VENTILEN:\n" . json_encode($zonesContext, JSON_PRETTY_PRINT) . "\n\n";
        $userPrompt .= "Berücksichtige bei der Laufzeitberechnung:\n";
        $userPrompt .= "- Ist die Bodentemperatur zu niedrig, kühle den Boden nicht weiter ab.\n";
        $userPrompt .= "- Nutze Helligkeit und Luftfeuchte, um die aktuelle Verdunstungsrate abzuschätzen.\n";
        $userPrompt .= "- Berechne für JEDE 'zoneId' die exakte Laufzeit in Minuten (0 bis maxDurationMinutes).\n";
        
        $systemInstruction = "Du bist ein präzises Steuerungsmodul für Agrarsysteme. Deine Aufgabe ist es, für die übergebenen Zonen-IDs (zoneId) Laufzeiten in Minuten zu berechnen. Antworte ausschließlich im vorgegebenen JSON-Format.";

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
                            'zoneId' => [
                                'type' => 'INTEGER',
                                'description' => 'Die ID der Zone (Beregnungskreis).'
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
                        'required' => ['zoneId', 'durationMinutes', 'reasoning']
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

        $this->LogAndDebug('Planer', 'Gemini Anfrage gesendet (Modell: ' . $model . ')...', 0);
        $this->LogAndDebug('Planer Prompt', $userPrompt, 0);

        $script = '<?php
            $ch = curl_init("' . $url . '");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ' . var_export(json_encode($payload), true) . ');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 45);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            
            SLAI_ProcessGeminiPlanResponse(' . $this->InstanceID . ', $response, $httpCode, $curlErr, ' . ($isManualStart ? 'true' : 'false') . ');
        ';
        IPS_RunScriptText($script);
    }

    public function ProcessGeminiPlanResponse($response, int $httpCode, string $curlErr, bool $isManualStart): void {
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (!is_array($zones)) $zones = [];
        
        $sprinklersJson = $this->ReadPropertyString('Sprinklers');
        $sprinklers = json_decode($sprinklersJson, true);
        if (!is_array($sprinklers)) $sprinklers = [];

        if ($response === false || $httpCode !== 200) {
            $this->LogAndDebug('Planer Fehler', 'Gemini API call failed. HTTP Code: ' . $httpCode . ', Curl-Fehler: ' . $curlErr . ', Response: ' . $response, 0);
            IPS_LogMessage('SmartLawnAI', 'Gemini API-Aufruf fehlgeschlagen (HTTP ' . $httpCode . '). Curl-Fehler: ' . $curlErr . ' | Details: ' . $response);
            $this->SetSummaryStatus('Fehler: Gemini API (HTTP ' . $httpCode . ')');
            return;
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $this->LogAndDebug('Planer Fehler', 'Ungültiges API-Response-Format.', 0);
            $this->SetSummaryStatus('Fehler: Ungültige API-Antwort');
            return;
        }

        $rawText = $result['candidates'][0]['content']['parts'][0]['text'];
        $this->LogAndDebug('Planer Antwort', $rawText, 0);

        $planData = json_decode($rawText, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($planData['irrigationPlan']) || !is_array($planData['irrigationPlan'])) {
            $this->LogAndDebug('Planer Fehler', 'Plan-JSON konnte nicht geparst werden.', 0);
            $this->SetSummaryStatus('Fehler: Gemini JSON-Parsing fehlgeschlagen');
            return;
        }

        $reasoningText = date('d.m.Y H:i') . " Uhr:\n";
        foreach ($planData['irrigationPlan'] as $item) {
            $zId = isset($item['zoneId']) ? $item['zoneId'] : 'Unbekannt';
            $dur = isset($item['durationMinutes']) ? $item['durationMinutes'] : 0;
            $res = isset($item['reasoning']) ? $item['reasoning'] : '-';
            $reasoningText .= "Zone {$zId} ({$dur} Min): {$res}\n";
        }
        $this->SetValue('LastGeminiResponse', trim($reasoningText));

        // Apply Gemini calculations
        $planByZone = [];
        foreach ($planData['irrigationPlan'] as $item) {
            if (isset($item['zoneId'])) {
                $planByZone[(int)$item['zoneId']] = $item;
            }
        }

        foreach ($zones as $zone) {
            $sid = $zone['SensorID'];
            if (!$this->isZoneHardwareOk($zone, $sprinklers)) {
                continue;
            }

            if (isset($planByZone[$sid])) {
                $zonePlan = $planByZone[$sid];
                $duration = isset($zonePlan['durationMinutes']) ? (int)$zonePlan['durationMinutes'] : 0;
                
                if ($duration <= 0 && $isManualStart) {
                    $duration = 5; // Minimaler Fallback für manuellen Start, damit der User Feedback hat
                    $this->LogAndDebug('Planer', 'Zone ' . $sid . ': Gemini meldet 0 Minuten. Da manueller Start, setze Fallback auf 5 Minuten.', 0);
                }
                
                if ($duration <= 0) {
                    $this->SetValue('Status_' . $sid, 'IDLE');
                    $this->SetValue('Dauer_' . $sid, 0);
                    continue;
                }

                $reasoning = $zonePlan['reasoning'];
                
                $maxDuration = GetValue($this->GetIDForIdent('GlobalMaxDuration'));
                if ($duration > $maxDuration) {
                    $duration = $maxDuration;
                }

                $this->SetValue('Dauer_' . $sid, $duration);
                if ($duration > 0) {
                    $this->SetValue('Status_' . $sid, 'QUEUED');
                    $this->LogAndDebug('Planer', 'Zone ' . $sid . ' eingereiht (Gemini): ' . $duration . ' Minuten. Begründung: ' . $reasoning, 0);
                } else {
                    $this->SetValue('Status_' . $sid, 'IDLE');
                    $this->LogAndDebug('Planer', 'Zone ' . $sid . ' nicht eingereiht (Gemini Dauer = 0). Begründung: ' . $reasoning, 0);
                }
            } else {
                $this->SetValue('Status_' . $sid, 'IDLE');
                $this->SetValue('Dauer_' . $sid, 0);
                $this->LogAndDebug('Planer', 'Zone ' . $sid . ' nicht im Gemini Plan enthalten. Gesetzt auf IDLE.', 0);
            }
        }
        
        $anyQueued = false;
        foreach ($zones as $zone) {
            if (GetValue($this->GetIDForIdent('Status_' . $zone['SensorID'])) === 'QUEUED') {
                $anyQueued = true;
                break;
            }
        }
        if ($anyQueued) {
            $this->SetSummaryStatus('Plan berechnet. Bewässerung startet gleich.');
        } else {
            $this->SetSummaryStatus('Standby (Boden ausreichend feucht)');
        }
    }

    private function resetAllZones(bool $queueForStart) {
        $actionName = $queueForStart ? 'ManualStart (Hard Reset)' : 'Automatik Off (Hard Stop)';
        $this->LogAndDebug('Reset', $actionName . ' aufgerufen', 0);
        
        if (!$queueForStart) {
            IPS_LogMessage('SmartLawnAI', 'Automatik deaktiviert! Alle Ventile werden gestoppt und Zonen zurückgesetzt.');
            $this->SetSummaryStatus('Automatik deaktiviert (Zonen gestoppt)');
        }

        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        $sprinklersJson = $this->ReadPropertyString('Sprinklers');
        $sprinklers = json_decode($sprinklersJson, true);
        if (is_array($sprinklers)) {
            foreach ($sprinklers as $s) {
                if (isset($s['ValveID']) && $s['ValveID'] > 0) {
                    @RequestAction($s['ValveID'], 'STOP_UNTIL_NEXT_TASK');
                }
            }
        }

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
                    @$this->SetValue('StartFeuchte_' . $sid, 0.0);
                    @$this->SetValue('Dauer_' . $sid, 0.0);
                    @$this->SetValue('SickerpauseStart_' . $sid, 0.0);

                    // 3. Status setzen
                    $newStatus = $queueForStart ? 'QUEUED' : 'IDLE';
                    $this->SetValue('Status_' . $sid, $newStatus);
                    
                    if ($queueForStart) {
                        $this->LogAndDebug('Reset', 'Zone ' . $sid . ' hart resettet und -> QUEUED.', 0);
                        IPS_LogMessage('SmartLawnAI', 'Zone ' . $sid . ' wurde manuell zurückgesetzt und in Warteschlange eingereiht.');
                    } else {
                        $this->LogAndDebug('Reset', 'Zone ' . $sid . ' hart resettet und gestoppt -> IDLE.', 0);
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
        $this->SetSummaryStatus('Manueller Start angefordert...');
        $this->LogAndDebug('ManualStart', 'Manueller Start angefordert. Setze Zonen zurück...', 0);
        $this->resetAllZones(false); // Stoppe alle aktiven Ventile und setze Zustand auf IDLE
        $this->SetBuffer('CalculatePlanPending', 'true');
        $this->ProcessLogic();
    }

    private function isZoneHardwareOk($zone, $sprinklers) {
        $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone ' . $zone['SensorID'];
        foreach ($sprinklers as $s) {
            if ($s['ZoneName'] === $zoneName) {
                if (isset($s['HardwareStatusID']) && $s['HardwareStatusID'] > 0) {
                    $hwStatus = GetValue($s['HardwareStatusID']);
                    $hwStr = strtoupper((string)$hwStatus);
                    if (in_array($hwStr, ['ERROR', 'WARNING', 'OFFLINE', 'DEFECT', 'FAULT'])) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    private function GetNextScheduleTime() {
        $schedule = $this->ReadPropertyInteger('IrrigationSchedule');
        $now = time();
        $today = strtotime('today');
        
        $times = [];
        if ($schedule === 1) {
            $times = [6];
        } else if ($schedule === 2) {
            $times = [6, 18];
        } else if ($schedule === 4) {
            $times = [0, 6, 12, 18];
        } else if ($schedule === 6) {
            $times = [0, 4, 8, 12, 16, 20];
        } else if ($schedule === 8) {
            $times = [0, 3, 6, 9, 12, 15, 18, 21];
        } else {
            $times = [6, 18];
        }
        
        foreach ($times as $hour) {
            $t = $today + ($hour * 3600);
            if ($t > $now) return $t;
        }
        
        // Next day first time
        return $today + 86400 + ($times[0] * 3600);
    }
}
