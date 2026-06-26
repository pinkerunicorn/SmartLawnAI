<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/Trait_UI.php';
require_once __DIR__ . '/libs/Trait_Weather.php';
require_once __DIR__ . '/libs/Trait_AI.php';
require_once __DIR__ . '/libs/Trait_Logic.php';
require_once __DIR__ . '/libs/Trait_Helpers.php';

class SmartLawnAI extends IPSModuleStrict {
    use SmartLawnAI_UI;
    use SmartLawnAI_Weather;
    use SmartLawnAI_AI;
    use SmartLawnAI_Logic;
    use SmartLawnAI_Helpers;

    public function Create(): void {
        parent::Create();

        // Globale Defaults (jetzt als Variablen statt Properties)
        $this->RegisterVariableFloat('DefaultZielFeuchte', 'Bewässerungs-Ziel-Feuchte', '', 10);
        $this->RegisterVariableFloat('DefaultStartSchwellwert', 'Bewässerungs-Trigger-Feuchte', '', 11);
        $this->RegisterVariableInteger('SickerpauseMinuten', 'Sickerpause', '', 12);
        $this->RegisterVariableInteger('GlobalMaxDuration', 'Maximale Bewässerungsdauer', '', 13);

        // Summenstatus Variable (fürs Webfront)
        $this->RegisterVariableString('SummaryStatus', 'Aktueller Status', '', 0);
        $this->RegisterVariableString('VestaboardStatus', 'Kurz-Status (Vestaboard)', '', 1);
        $this->RegisterVariableString('LastGeminiResponse', 'Letzte KI-Antwort', '', 2);
        $this->RegisterVariableString('IrrigationLog', 'Bewässerungs-Log', '', 3);


        // Gemini AI Konfiguration
        $this->RegisterPropertyString('GeminiApiKey', '');
        $this->RegisterPropertyString('GeminiModel', 'gemini-1.5-flash');

        // Globale Sensoren (Thermodynamik & Boden)
        $this->RegisterPropertyInteger('GlobalAirTempID', 0);
        $this->RegisterPropertyInteger('GlobalHumidityID', 0);
        $this->RegisterPropertyInteger('GlobalIlluminanceID', 0);
        $this->RegisterPropertyFloat('Latitude', 0.0);
        $this->RegisterPropertyFloat('Longitude', 0.0);
        
        // NEU: Hardware Grace Period konfigurierbar
        $this->RegisterPropertyInteger('HardwareGracePeriod', 90);
        
        $this->SetVisualizationType(1);

        // Wetter/Regen
        $this->RegisterVariableFloat('ForecastRainToday', 'Regen Heute', '', 5);
        $this->RegisterVariableFloat('ForecastRainTomorrow', 'Regen Morgen', '', 6);

        // Zonen (Hardware)
        $this->RegisterPropertyString('Zones', '[]');
        $this->RegisterPropertyString('Sprinklers', '[]');
        
        // Zeitplan (1=06:00, 2=06+18, 4=06+10+14+18)
        $this->RegisterPropertyInteger('IrrigationSchedule', 2);

        // Timer für die 60-Sekunden-Taktung (Zustandsmaschine)
        $this->RegisterTimer('LawnAITimer', 0, 'SLAI_ProcessLogic($_IPS[\'TARGET\']);');
        
        // NEU: Gemini Retry Timer
        $this->RegisterTimer('GeminiRetryTimer', 0, 'SLAI_ProcessGeminiRetry($_IPS[\'TARGET\']);');
    }

    public function RequestAction(string $Ident, $Value): void {
        if (in_array($Ident, ['DefaultZielFeuchte', 'DefaultStartSchwellwert', 'SickerpauseMinuten', 'GlobalMaxDuration'])) {
            SetValue($this->GetIDForIdent($Ident), $Value);
        } else if ($Ident === 'AutomaticActive') {
            SetValue($this->GetIDForIdent($Ident), $Value);
            $this->MaintainScheduleEvents($Value);
            
            if (!$Value) {
                $this->SetTimerInterval('LawnAITimer', 0);
                $this->resetAllZones(false);
            } else {
                $this->SetTimerInterval('LawnAITimer', 1000);
                $this->SetBuffer('LastPlanCalculation', '0');
                $this->ProcessLogic();
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

    public function ApplyChanges(): void {
        parent::ApplyChanges();
        // Timer aktivieren (alle 1.000 ms = 1 Sekunde)
        // Status/Trigger Variablen
        $this->RegisterVariableBoolean('AutomaticActive', 'Automatik aktiv', '', 0);
        $this->EnableAction('AutomaticActive');
        if (!IPS_VariableExists($this->GetIDForIdent('AutomaticActive')) || (GetValue($this->GetIDForIdent('AutomaticActive')) === false && IPS_GetVariable($this->GetIDForIdent('AutomaticActive'))['VariableUpdated'] == 0)) {
            SetValue($this->GetIDForIdent('AutomaticActive'), true); // Default true
            $this->SetTimerInterval('LawnAITimer', 1000);
            $this->MaintainScheduleEvents(true);
        } else {
            $active = GetValue($this->GetIDForIdent('AutomaticActive'));
            $this->MaintainScheduleEvents($active);
            if ($active) {
                $this->SetTimerInterval('LawnAITimer', 1000);
            } else {
                $this->SetTimerInterval('LawnAITimer', 0);
            }
        }
        $this->RegisterVariableBoolean('ForceStart', 'Manuell Starten', '', 0);
        $this->EnableAction('ForceStart');
        SetValue($this->GetIDForIdent('ForceStart'), false);

        $this->EnableAction('DefaultZielFeuchte');
        IPS_SetName($this->GetIDForIdent('DefaultZielFeuchte'), 'Bewässerungs-Ziel-Feuchte');
        if (GetValue($this->GetIDForIdent('DefaultZielFeuchte')) == 0) { SetValue($this->GetIDForIdent('DefaultZielFeuchte'), 55.0); }
        if (function_exists('IPS_SetVariableCustomPresentation')) { 
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('DefaultZielFeuchte'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'ICON' => 'Drops',
                'SUFFIX' => ' %',
                'MIN' => 0,
                'MAX' => 100,
                'STEP' => 5
            ]); 
        }
        
        $this->EnableAction('DefaultStartSchwellwert');
        IPS_SetName($this->GetIDForIdent('DefaultStartSchwellwert'), 'Bewässerungs-Trigger-Feuchte');
        if (GetValue($this->GetIDForIdent('DefaultStartSchwellwert')) == 0) { SetValue($this->GetIDForIdent('DefaultStartSchwellwert'), 20.0); }
        if (function_exists('IPS_SetVariableCustomPresentation')) { 
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('DefaultStartSchwellwert'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'ICON' => 'Drops',
                'SUFFIX' => ' %',
                'MIN' => 0,
                'MAX' => 100,
                'STEP' => 5
            ]); 
        }
        
        $this->EnableAction('SickerpauseMinuten');
        IPS_SetName($this->GetIDForIdent('SickerpauseMinuten'), 'Sickerpause');
        if (GetValue($this->GetIDForIdent('SickerpauseMinuten')) == 0) { SetValue($this->GetIDForIdent('SickerpauseMinuten'), 15); }
        if (function_exists('IPS_SetVariableCustomPresentation')) { 
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('SickerpauseMinuten'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'ICON' => 'Clock',
                'SUFFIX' => ' Min',
                'MIN' => 0,
                'MAX' => 180,
                'STEP' => 5
            ]); 
        }
        
        $this->EnableAction('GlobalMaxDuration');
        IPS_SetName($this->GetIDForIdent('GlobalMaxDuration'), 'Maximale Bewässerungsdauer');
        if (GetValue($this->GetIDForIdent('GlobalMaxDuration')) == 0) { SetValue($this->GetIDForIdent('GlobalMaxDuration'), 30); }
        if (function_exists('IPS_SetVariableCustomPresentation')) { 
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('GlobalMaxDuration'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'ICON' => 'Clock',
                'SUFFIX' => ' Min',
                'MIN' => 0,
                'MAX' => 180,
                'STEP' => 5
            ]); 
        }

        if (function_exists('IPS_SetVariableCustomPresentation')) { 
            IPS_SetVariableCustomPresentation($this->GetIDForIdent('IrrigationLog'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_HTML,
                'ICON' => 'Information'
            ]); 
        }

        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (is_array($zones)) {
            foreach ($zones as $zone) {
                $sid = $zone['SensorID'];
                $hasSoak = isset($zone['SoakEnabled']) ? $zone['SoakEnabled'] : false;
                $name = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone ' . $sid;
                if (!empty($name)) {
                    $this->RegisterVariableString('Status_' . $sid, 'Status ' . $name, '', 1);
                    $this->RegisterVariableFloat('Effizienz_' . $sid, 'Effizienz ' . $name, '', 2);
                    $this->EnableArchive($this->GetIDForIdent('Effizienz_' . $sid));
                    if (function_exists('IPS_SetVariableCustomPresentation')) { 
                        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Effizienz_' . $sid), [
                            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                            'ICON' => 'Graph',
                            'SUFFIX' => ' x'
                        ]); 
                    }
                    $this->RegisterVariableFloat('StartFeuchte_' . $sid, 'StartFeuchte ' . $name, '', 3);
                    if (function_exists('IPS_SetVariableCustomPresentation')) { 
                        IPS_SetVariableCustomPresentation($this->GetIDForIdent('StartFeuchte_' . $sid), [
                            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                            'ICON' => 'Drops',
                            'SUFFIX' => ' %'
                        ]); 
                    }
                    $this->RegisterVariableFloat('Dauer_' . $sid, 'Dauer ' . $name, '', 4);
                    if (function_exists('IPS_SetVariableCustomPresentation')) { 
                        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Dauer_' . $sid), [
                            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                            'ICON' => 'Clock',
                            'SUFFIX' => ' Min'
                        ]); 
                    }
                    $this->RegisterVariableInteger('SickerpauseStart_' . $sid, 'SickerpauseStart ' . $name, '', 5);
                    $this->RegisterVariableInteger('WateringStart_' . $sid, 'Bewässerungsstart ' . $name, '', 6);
                    
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

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void {
        if ($Message == VM_UPDATE) {
            $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
        }
    }
}