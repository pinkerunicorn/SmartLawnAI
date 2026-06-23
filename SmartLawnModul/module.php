<?php
class SmartLawnAI extends IPSModule {

    public function Create() {
        parent::Create();

        // Profile registrieren (Werte-Anzeige ohne Slider -> StepSize = 0)
        $this->RegisterProfileFloat('SmartLawn.Percentage', 'Drops', '', ' %', 0, 100, 0, 1);
        $this->RegisterProfileFloat('SmartLawn.MinutesFloat', 'Clock', '', ' Min', 0, 180, 0, 1);
        $this->RegisterProfileFloat('SmartLawn.Multiplier', 'Graph', '', ' x', 0.1, 5.0, 0, 1);

        // Profile registrieren (Eingabe mit Slider -> StepSize > 0)
        $this->RegisterProfileFloat('SmartLawn.Percentage.Input', 'Drops', '', ' %', 0, 100, 5, 1);
        $this->RegisterProfileInteger('SmartLawn.Minutes.Input', 'Clock', '', ' Min', 0, 180, 5);

        // Globale Defaults (jetzt als Variablen statt Properties)
        $this->RegisterVariableFloat('DefaultZielFeuchte', 'Bewässerungs-Ziel-Feuchte', 'SmartLawn.Percentage.Input', 10);
        $this->RegisterVariableFloat('DefaultStartSchwellwert', 'Bewässerungs-Trigger-Feuchte', 'SmartLawn.Percentage.Input', 11);
        $this->RegisterVariableInteger('SickerpauseMinuten', 'Sickerpause', 'SmartLawn.Minutes.Input', 12);
        $this->RegisterVariableInteger('GlobalMaxDuration', 'Maximale Bewässerungsdauer', 'SmartLawn.Minutes.Input', 13);

        // Summenstatus Variable (fürs Webfront)
        $this->RegisterVariableString('SummaryStatus', 'Aktueller Status', '', 0);

        // Gemini AI Konfiguration
        $this->RegisterPropertyString('GeminiApiKey', '');
        $this->RegisterPropertyString('GeminiModel', 'gemini-3.5-flash');

        // Globale Sensoren (Thermodynamik & Boden)
        $this->RegisterPropertyInteger('GlobalAirTempID', 0);
        $this->RegisterPropertyInteger('GlobalHumidityID', 0);
        $this->RegisterPropertyInteger('GlobalIlluminanceID', 0);
        $this->RegisterPropertyFloat('Latitude', 0.0);
        $this->RegisterPropertyFloat('Longitude', 0.0);
        $this->SetVisualizationType(1);

        // Wetter-Variablen
        $this->RegisterVariableFloat('ForecastRainToday', 'Regen Heute', '~Rainfall', 5);
        $this->RegisterVariableFloat('ForecastRainTomorrow', 'Regen Morgen', '~Rainfall', 6);
        $this->RegisterVariableInteger('ForecastLastUpdate', 'Letztes Wetter-Update', '~UnixTimestamp', 7);

        // Zonen (Hardware)
        $this->RegisterPropertyString('Zones', '[]');
        $this->RegisterPropertyString('Sprinklers', '[]');

        // Timer für die 60-Sekunden-Taktung
        $this->RegisterTimer('LawnAITimer', 0, 'SLAI_ProcessLogic($_IPS[\'TARGET\']);');
        $this->RegisterTimer('WeatherTimer', 0, 'SLAI_UpdateWeather($_IPS[\'TARGET\']);');
    }

    public function RequestAction($Ident, $Value) {
        if (in_array($Ident, ['DefaultZielFeuchte', 'DefaultStartSchwellwert', 'SickerpauseMinuten', 'GlobalMaxDuration'])) {
            SetValue($this->GetIDForIdent($Ident), $Value);
        } else if ($Ident === 'AutomaticActive') {
            SetValue($this->GetIDForIdent($Ident), $Value);
            if (!$Value) {
                $this->SetTimerInterval('LawnAITimer', 0);
                $this->resetAllZones(false);
            } else {
                $this->SetTimerInterval('LawnAITimer', 1000);
                $this->SetSummaryStatus('Automatik aktiviert (überwache Sensoren...)');
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
                    @SetValue($this->GetIDForIdent('StartFeuchte_' . $sid), 0.0);
                    @SetValue($this->GetIDForIdent('Dauer_' . $sid), 0.0);
                    @SetValue($this->GetIDForIdent('SickerpauseStart_' . $sid), 0.0);

                    // 3. Status setzen
                    $newStatus = $queueForStart ? 'QUEUED' : 'IDLE';
                    SetValue($statusId, $newStatus);
                    
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

    public function ApplyChanges() {
        parent::ApplyChanges();
        // Timer aktivieren (alle 1.000 ms = 1 Sekunde)
        $this->SetTimerInterval('LawnAITimer', 1000);

        $this->RegisterVariableBoolean('AutomaticActive', 'Automatik aktiv', '~Switch', 0);
        $this->EnableAction('AutomaticActive');
        if (!IPS_VariableExists($this->GetIDForIdent('AutomaticActive')) || (GetValue($this->GetIDForIdent('AutomaticActive')) === false && IPS_GetVariable($this->GetIDForIdent('AutomaticActive'))['VariableUpdated'] == 0)) {
            SetValue($this->GetIDForIdent('AutomaticActive'), true); // Default true
            $this->SetTimerInterval('LawnAITimer', 1000);
        } else {
            $active = GetValue($this->GetIDForIdent('AutomaticActive'));
            if ($active) {
                $this->SetTimerInterval('LawnAITimer', 1000);
            } else {
                $this->SetTimerInterval('LawnAITimer', 0);
            }
        }
        $this->RegisterVariableBoolean('ForceStart', 'Manuell Starten', '~Switch', 0);
        $this->EnableAction('ForceStart');
        SetValue($this->GetIDForIdent('ForceStart'), false);

        $this->EnableAction('DefaultZielFeuchte');
        IPS_SetName($this->GetIDForIdent('DefaultZielFeuchte'), 'Bewässerungs-Ziel-Feuchte');
        if (GetValue($this->GetIDForIdent('DefaultZielFeuchte')) == 0) { SetValue($this->GetIDForIdent('DefaultZielFeuchte'), 55.0); }
        // Clean up legacy slider presentation if it was set
        if (function_exists('IPS_SetVariableCustomPresentation')) { IPS_SetVariableCustomPresentation($this->GetIDForIdent('DefaultZielFeuchte'), []); }
        
        $this->EnableAction('DefaultStartSchwellwert');
        IPS_SetName($this->GetIDForIdent('DefaultStartSchwellwert'), 'Bewässerungs-Trigger-Feuchte');
        if (GetValue($this->GetIDForIdent('DefaultStartSchwellwert')) == 0) { SetValue($this->GetIDForIdent('DefaultStartSchwellwert'), 20.0); }
        if (function_exists('IPS_SetVariableCustomPresentation')) { IPS_SetVariableCustomPresentation($this->GetIDForIdent('DefaultStartSchwellwert'), []); }
        
        $this->EnableAction('SickerpauseMinuten');
        IPS_SetName($this->GetIDForIdent('SickerpauseMinuten'), 'Sickerpause');
        if (GetValue($this->GetIDForIdent('SickerpauseMinuten')) == 0) { SetValue($this->GetIDForIdent('SickerpauseMinuten'), 15); }
        if (function_exists('IPS_SetVariableCustomPresentation')) { IPS_SetVariableCustomPresentation($this->GetIDForIdent('SickerpauseMinuten'), []); }
        
        $this->EnableAction('GlobalMaxDuration');
        IPS_SetName($this->GetIDForIdent('GlobalMaxDuration'), 'Maximale Bewässerungsdauer');
        if (GetValue($this->GetIDForIdent('GlobalMaxDuration')) == 0) { SetValue($this->GetIDForIdent('GlobalMaxDuration'), 30); }
        if (function_exists('IPS_SetVariableCustomPresentation')) { IPS_SetVariableCustomPresentation($this->GetIDForIdent('GlobalMaxDuration'), []); }

        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (is_array($zones)) {
            foreach ($zones as $zone) {
                $sid = $zone['SensorID'];
                $name = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone ' . $sid;
            if (!empty($name)) {
                $this->RegisterVariableString('Status_' . $sid, 'Status ' . $name, '', 1);
                $this->RegisterVariableFloat('Effizienz_' . $sid, 'Effizienz ' . $name, 'SmartLawn.Multiplier', 2);
                $this->EnableArchive($this->GetIDForIdent('Effizienz_' . $sid));
                if (function_exists('IPS_SetVariableCustomPresentation')) { IPS_SetVariableCustomPresentation($this->GetIDForIdent('Effizienz_' . $sid), []); }
                $this->RegisterVariableFloat('StartFeuchte_' . $sid, 'StartFeuchte ' . $name, 'SmartLawn.Percentage', 3);
                if (function_exists('IPS_SetVariableCustomPresentation')) { IPS_SetVariableCustomPresentation($this->GetIDForIdent('StartFeuchte_' . $sid), []); }
                $this->RegisterVariableFloat('Dauer_' . $sid, 'Dauer ' . $name, 'SmartLawn.MinutesFloat', 4);
                if (function_exists('IPS_SetVariableCustomPresentation')) { IPS_SetVariableCustomPresentation($this->GetIDForIdent('Dauer_' . $sid), []); }
                $this->RegisterVariableInteger('SickerpauseStart_' . $sid, 'SickerpauseStart ' . $name, '~UnixTimestamp', 5);
                $this->RegisterVariableInteger('WateringStart_' . $sid, 'Bewässerungsstart ' . $name, '~UnixTimestamp', 6);
                $this->RegisterVariableInteger('CurrentSprinklerIndex_' . $sid, 'Aktueller Sprinkler Index ' . $name, '', 7);
                IPS_SetHidden($this->GetIDForIdent('CurrentSprinklerIndex_' . $sid), true);

                // IP-Symcon benennt bestehende Variablen nicht automatisch um, daher erzwingen wir es hier
                IPS_SetName($this->GetIDForIdent('Status_' . $sid), 'Status ' . $name);
                IPS_SetName($this->GetIDForIdent('Effizienz_' . $sid), 'Effizienz ' . $name);
                IPS_SetName($this->GetIDForIdent('StartFeuchte_' . $sid), 'StartFeuchte ' . $name);
                IPS_SetName($this->GetIDForIdent('Dauer_' . $sid), 'Dauer ' . $name);
                IPS_SetName($this->GetIDForIdent('SickerpauseStart_' . $sid), 'SickerpauseStart ' . $name);
                IPS_SetName($this->GetIDForIdent('WateringStart_' . $sid), 'Bewässerungsstart ' . $name);
                IPS_SetName($this->GetIDForIdent('CurrentSprinklerIndex_' . $sid), 'Aktueller Sprinkler Index ' . $name);
            }
        }
        }
        
        $this->RegisterVisuMessages();
    }

    private function RegisterVisuMessages() {
        $this->RegisterMessage($this->GetIDForIdent('SummaryStatus'), VM_UPDATE);
        $this->RegisterMessage($this->GetIDForIdent('ForecastRainToday'), VM_UPDATE);
        $this->RegisterMessage($this->GetIDForIdent('ForecastRainTomorrow'), VM_UPDATE);
        
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

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message == VM_UPDATE) {
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
        }
    }

    public function GetVisualizationTile() {
        $initialHandling = '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ')</script>';
        
        $htmlFile = __DIR__ . '/module.html';
        $moduleHtml = '';
        if (file_exists($htmlFile)) {
            $moduleHtml = file_get_contents($htmlFile);
        }
        
        return $moduleHtml . $initialHandling;
    }

    private function GetFullUpdateMessage() {
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
                if (isset($zoneSprinklers[$currentIndex]['RemainingSecondsID']) && $zoneSprinklers[$currentIndex]['RemainingSecondsID'] > 0) {
                    $remainingSeconds = (int)@GetValue($zoneSprinklers[$currentIndex]['RemainingSecondsID']);
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
            'zones' => $zoneData
        ];

        return json_encode($config);
    }

    public function ProcessLogic() {
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
            if ($status === 'WATERING' || $status === 'VERIFYING_START') {
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

        // 3. Prüfen, ob ein neuer Bewässerungszyklus gestartet werden muss
        $active = GetValue($this->GetIDForIdent('AutomaticActive'));
        if ($active) {
            $this->SetTimerInterval('LawnAITimer', 60000);
        } else {
            $this->SetTimerInterval('LawnAITimer', 0);
        }

        // Wetter-Timer (alle 4 Stunden = 14400000 ms), wenn Koordinaten vorhanden
        $lat = (float)$this->ReadPropertyFloat('Latitude');
        $lon = (float)$this->ReadPropertyFloat('Longitude');
        if ($lat != 0.0 || $lon != 0.0) {
            $this->SetTimerInterval('WeatherTimer', 14400000);
            // Direkt einmalig abrufen, falls noch keine Daten vorliegen
            $this->UpdateWeather();
        } else {
            $this->SetTimerInterval('WeatherTimer', 0);
        }

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
                $startWert = $defaultStart;
                $aktuelleFeuchte = GetValue($zone['SensorID']);
                if ($active && $aktuelleFeuchte <= $startWert) {
                    $newCycleTriggered = true;
                    break;
                }
            }
        }

        if ($newCycleTriggered) {
            $this->LogAndDebug('Planer', 'Neuer Bewässerungszyklus initiiert. Berechne Laufzeiten...', 0);
            $this->CalculateAndApplyPlan($zones, $sprinklers, $isManualStart, $vpd, $lux);
            
            // Status nach Berechnungsdurchlauf neu einlesen
            $einVentilIstAktiv = false;
            foreach ($zones as $zone) {
                $status = GetValue($this->GetIDForIdent('Status_' . $zone['SensorID']));
                if ($status === 'WATERING' || $status === 'VERIFYING_START') {
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
                SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'HARDWARE_FEHLER');
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
                            SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'QUEUED');
                        } else {
                            $this->LogAndDebug('Sequencer', 'Startbedingung erfüllt. Starte Zone ' . $zone['SensorID'] . ' (VERIFYING_START).', 0);
                            IPS_LogMessage('SmartLawnAI', 'Bewässerung für Zone ' . $zone['SensorID'] . ' wird gestartet!');
                            SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'VERIFYING_START');
                            SetValue($this->GetIDForIdent('WateringStart_' . $zone['SensorID']), time());
                            $this->SetSummaryStatus('Starte Bewässerung: ' . $zoneName . '...');
                            
                            // Berechnete Laufzeit aus Variable lesen
                            $berechneteMinuten = (int)GetValue($this->GetIDForIdent('Dauer_' . $zone['SensorID']));
                            if ($berechneteMinuten <= 0) {
                                $this->LogAndDebug('Sequencer', 'Zone ' . $zone['SensorID'] . ' hat keine gültige Dauer. Überspringe.', 0);
                                SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'IDLE');
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
                    
                    // Grace Period: Cloud APIs (z.B. Gardena) brauchen oft bis zu 60 Sekunden zum Synchronisieren.
                    // Während dieser ersten 90 Sekunden tun wir so, als wäre das Ventil sicher offen, um Verzögerungen im UI zu umgehen und versehentliches Stoppen zu verhindern.
                    if (!$ventilOffen && $wateringStart > 0 && (time() - $wateringStart) < 90) {
                        $ventilOffen = true;
                        $hwVal .= ' (Grace Period Active)';
                    }
                    
                    $remaining = 0;
                    if (isset($currentSprinkler['RemainingSecondsID']) && $currentSprinkler['RemainingSecondsID'] > 0) {
                        $remaining = (int)GetValue($currentSprinkler['RemainingSecondsID']);
                    } else {
                        $timerID = $this->GetIDForIdent('ValveSequenceTimer');
                        $timer = IPS_GetTimer($timerID);
                        if ($timer['NextRun'] > 0) {
                            $remaining = max(0, $timer['NextRun'] - time());
                        }
                    }
                    $remainingText = $remaining > 0 ? ' (noch ' . ceil($remaining / 60) . ' Min)' : '';

                    if ($ventilOffen && $aktuellerStatus === 'VERIFYING_START') {
                        SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'WATERING');
                        SetValue($this->GetIDForIdent('WateringStart_' . $zone['SensorID']), time());
                        $this->SetSummaryStatus('Bewässert: ' . $zoneName . ' (' . $currentSprinklerName . ')' . $remainingText);
                    } elseif (!$ventilOffen && $aktuellerStatus === 'WATERING') {
                        IPS_LogMessage('SmartLawnAI', $currentSprinklerName . ' in Zone ' . $zone['SensorID'] . ' ist fertig. Hardware-Status: ' . $hwVal);
                        
                        $currentIndex++;
                        if ($currentIndex < count($zoneSprinklers)) {
                            // Nächster Sprinkler in dieser Zone
                            SetValue($currentIndexVarId, $currentIndex);
                            SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'QUEUED');
                            $this->LogAndDebug('Sequencer', 'Sprinkler gewechselt. Nächster Index: ' . $currentIndex, 0);
                        } else {
                            // Alle Sprinkler der Zone fertig
                            SetValue($currentIndexVarId, 0); // Reset
                            SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'WAITING_FOR_RESULT');
                            SetValue($this->GetIDForIdent('SickerpauseStart_' . $zone['SensorID']), time());
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

                        SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'IDLE');
                        $this->SetSummaryStatus('Standby (Bewässerung abgeschlossen)');
                    }
                    break;
            }
        }

        // 5. Heartbeat für die Webfront Anzeige (Zeitstempel aktualisieren)
        $automaticActive = GetValue($this->GetIDForIdent('AutomaticActive'));
        if ($automaticActive) {
            $currentStatus = GetValue($this->GetIDForIdent('SummaryStatus'));
            // Entferne alten Zeitstempel, falls vorhanden
            $baseStatus = preg_replace('/ \(\d{2}:\d{2}\)$/', '', $currentStatus);
            
            // Textvereinheitlichung für den normalen Leerlauf
            if ($baseStatus === 'Standby (Boden ausreichend feucht)' || 
                $baseStatus === 'Automatik aktiviert (Überwache Sensoren...)') {
                $baseStatus = 'Standby - Überwache Sensoren';
            }
            
            $this->SetSummaryStatus($baseStatus . ' (' . date('H:i') . ')');
        }

        // Live-Update der Visualisierung pushen
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
    }

    public function UIRequest(string $Action, string $Payload) {
        switch ($Action) {
            case 'TOGGLE_AUTOMATIC':
                $id = $this->GetIDForIdent('AutomaticActive');
                $newVal = !GetValue($id);
                SetValue($id, $newVal);
                if (!$newVal) {
                    $this->resetAllZones(false);
                } else {
                    $this->SetSummaryStatus('Automatik aktiviert (Überwache Sensoren...)');
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

    private function EnableArchive($variableID) {
        if ($variableID > 0) {
            $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
            if (count($archiveIDs) > 0) {
                $archiveID = $archiveIDs[0];
                if (!AC_GetLoggingStatus($archiveID, $variableID)) {
                    AC_SetLoggingStatus($archiveID, $variableID, true);
                    IPS_ApplyChanges($archiveID);
                }
            }
        }
    }

    public function UpdateWeather() {
        $lat = (float)$this->ReadPropertyFloat('Latitude');
        $lon = (float)$this->ReadPropertyFloat('Longitude');
        if ($lat == 0.0 && $lon == 0.0) {
            return;
        }

        $omUrl = "https://api.open-meteo.com/v1/forecast?latitude=" . number_format($lat, 6, '.', '') . "&longitude=" . number_format($lon, 6, '.', '') . "&daily=precipitation_sum&timezone=auto&forecast_days=3";
        $omContent = @Sys_GetURLContent($omUrl);
        if ($omContent !== false) {
            $omData = json_decode($omContent, true);
            if (isset($omData['daily']) && isset($omData['daily']['precipitation_sum'])) {
                $sums = $omData['daily']['precipitation_sum'];
                if (isset($sums[0])) SetValue($this->GetIDForIdent('ForecastRainToday'), (float)$sums[0]);
                if (isset($sums[1])) SetValue($this->GetIDForIdent('ForecastRainTomorrow'), (float)$sums[1]);
                SetValue($this->GetIDForIdent('ForecastLastUpdate'), time());
                $this->LogAndDebug('Weather', 'Open-Meteo Regen-Vorhersage aktualisiert: Heute ' . (float)$sums[0] . 'mm, Morgen ' . (float)$sums[1] . 'mm', 0);
            }
        } else {
            $this->LogAndDebug('Weather', 'Fehler beim Abrufen der Open-Meteo Wetterdaten.', 0);
        }
    }

    private function EvaluateEfficiencyWithGemini($zoneID, $startFeuchte, $aktuelleFeuchte, $dauer, $vpd, $lux) {
        $apiKey = trim($this->ReadPropertyString('GeminiApiKey'));
        $model = trim($this->ReadPropertyString('GeminiModel'));
        if (empty($apiKey)) {
            $this->LogAndDebug('Weather', 'Kein Gemini API-Key für Effizienz-Lernen konfiguriert.', 0);
            return;
        }

        $userPrompt = "Du bist ein Agrar-Analyst. Bewerte den folgenden Bewässerungs-Zyklus:\n";
        $userPrompt .= "- Zone ID: $zoneID\n";
        $userPrompt .= "- Dauer der Bewässerung: $dauer Minuten\n";
        $userPrompt .= "- Bodenfeuchte vor dem Gießen: $startFeuchte %\n";
        $userPrompt .= "- Bodenfeuchte nach der Sickerpause: $aktuelleFeuchte %\n";
        $userPrompt .= "- Wetter: Sättigungsdefizit (VPD) = $vpd kPa, Helligkeit = $lux Lux\n";
        $userPrompt .= "\nBerechne auf Basis dieser Werte einen neuen 'efficiencyPercentPerMinute'-Multiplikator für diese Zone (wie viel Prozent Feuchte bringt 1 Minute Gießen unter diesen Umständen). Ein normaler Wert liegt zwischen 0.5 und 3.0.";
        
        $systemInstruction = "Du antwortest ausschließlich im JSON-Format.";

        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $model . ":generateContent?key=" . $apiKey;
        $responseSchema = [
            'type' => 'OBJECT',
            'properties' => [
                'newEfficiencyMultiplier' => [
                    'type' => 'NUMBER',
                    'description' => 'Der neu berechnete Effizienz-Faktor.'
                ],
                'reasoning' => [
                    'type' => 'STRING',
                    'description' => 'Agronomische Begründung für diesen Wert.'
                ]
            ],
            'required' => ['newEfficiencyMultiplier', 'reasoning']
        ];

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
                'responseSchema' => $responseSchema
            ]
        ];

        $jsonPayload = json_encode($payload);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $result) {
            $data = json_decode($result, true);
            if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                $jsonText = $data['candidates'][0]['content']['parts'][0]['text'];
                $parsed = json_decode($jsonText, true);
                if (is_array($parsed) && isset($parsed['newEfficiencyMultiplier'])) {
                    $neueEffizienz = (float)$parsed['newEfficiencyMultiplier'];
                    $begruendung = $parsed['reasoning'];
                    
                    SetValue($this->GetIDForIdent('Effizienz_' . $zoneID), $neueEffizienz);
                    IPS_LogMessage('SmartLawnAI', "Gemini Effizienz-Lernen (Zone $zoneID): Der neue Faktor ist {$neueEffizienz}x. Begründung: $begruendung");
                    return;
                }
            }
        }
        $this->LogAndDebug('Weather', "Fehler beim Gemini Effizienz-Lernen für Zone $zoneID (HTTP $httpCode).", 0);
    }

    private function CalculateAndApplyPlan($zones, $sprinklers, $isManualStart, $vpd, $lux) {
        $this->SetSummaryStatus('Berechne Bewässerungsplan (Gemini AI)...');
        $apiKey = trim($this->ReadPropertyString('GeminiApiKey'));
        $model = $this->ReadPropertyString('GeminiModel');
        if (empty($model)) {
            $model = 'gemini-3.5-flash';
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
                SetValue($this->GetIDForIdent('Status_' . $sid), 'HARDWARE_FEHLER');
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

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Workaround for missing cacert.pem in local PHP setups
        curl_setopt($ch, CURLOPT_TIMEOUT, 45); // Erhöht auf 45 Sekunden, da die Gemini API manchmal länger braucht

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

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
                $duration = (int)$planByZone[$sid]['durationMinutes'];
                $reasoning = $planByZone[$sid]['reasoning'];
                
                $maxDuration = GetValue($this->GetIDForIdent('GlobalMaxDuration'));
                if ($duration > $maxDuration) {
                    $duration = $maxDuration;
                }

                SetValue($this->GetIDForIdent('Dauer_' . $sid), $duration);
                if ($duration > 0) {
                    SetValue($this->GetIDForIdent('Status_' . $sid), 'QUEUED');
                    $this->LogAndDebug('Planer', 'Zone ' . $sid . ' eingereiht (Gemini): ' . $duration . ' Minuten. Begründung: ' . $reasoning, 0);
                } else {
                    SetValue($this->GetIDForIdent('Status_' . $sid), 'IDLE');
                    $this->LogAndDebug('Planer', 'Zone ' . $sid . ' nicht eingereiht (Gemini Dauer = 0). Begründung: ' . $reasoning, 0);
                }
            } else {
                SetValue($this->GetIDForIdent('Status_' . $sid), 'IDLE');
                SetValue($this->GetIDForIdent('Dauer_' . $sid), 0);
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

    private function SetSummaryStatus(string $status) {
        $id = @$this->GetIDForIdent('SummaryStatus');
        if ($id > 0) {
            SetValue($id, $status);
        }
    }

    private function LogAndDebug($Topic, $Payload, $Format = 0) {
        $this->SendDebug($Topic, $Payload, $Format);
        if (is_scalar($Payload)) {
            IPS_LogMessage('SmartLawnAI', $Topic . ': ' . $Payload);
        } else {
            IPS_LogMessage('SmartLawnAI', $Topic . ': ' . json_encode($Payload));
        }
    }

    private function RegisterProfileFloat($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits) {
        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, 2); // 2 = Float
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] != 2) {
                throw new Exception("Variable profile type does not match for profile " . $Name);
            }
        }
        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
        IPS_SetVariableProfileValues($Name, $MinValue, $MaxValue, $StepSize);
        IPS_SetVariableProfileDigits($Name, $Digits);
    }

    private function RegisterProfileInteger($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize) {
        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, 1); // 1 = Integer
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] != 1) {
                throw new Exception("Variable profile type does not match for profile " . $Name);
            }
        }
        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
        IPS_SetVariableProfileValues($Name, $MinValue, $MaxValue, $StepSize);
    }
}