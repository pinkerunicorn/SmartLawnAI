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
            $status = $this->GetBuffer('Status_' . $zone['SensorID']);
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
            $aktuellerStatus = $this->GetBuffer('Status_' . $zone['SensorID']);
            if (empty($aktuellerStatus)) {
                $aktuellerStatus = 'IDLE';
            }

            // Gardena Not-Aus Check
            if ($zone['HardwareStatusID'] > 0) {
                $hwStatus = GetValue($zone['HardwareStatusID']);
                if ($hwStatus !== 0 && $hwStatus !== 'OK') {
                    $this->SetBuffer('Status_' . $zone['SensorID'], 'HARDWARE_FEHLER');
                    continue; 
                }
            }

            switch ($aktuellerStatus) {
                case 'IDLE':
                case 'QUEUED':
                    if ($aktuelleFeuchte <= $startWert) {
                        if ($einVentilIstAktiv) {
                            $this->SetBuffer('Status_' . $zone['SensorID'], 'QUEUED');
                        } else {
                            $this->SetBuffer('Status_' . $zone['SensorID'], 'VERIFYING_START');
                            
                            // KI-Laufzeitberechnung (Basiswert 30 Minuten, wenn noch kein Effizienzfaktor gelernt wurde)
                            $effizienz = (float)$this->GetBuffer('Effizienz_' . $zone['SensorID']);
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
                            $this->SetBuffer('StartFeuchte_' . $zone['SensorID'], $aktuelleFeuchte);
                            $this->SetBuffer('Dauer_' . $zone['SensorID'], $berechneteMinuten);
                            
                            $einVentilIstAktiv = true; 
                        }
                    } else {
                        $this->SetBuffer('Status_' . $zone['SensorID'], 'IDLE');
                    }
                    break;
                    
                case 'VERIFYING_START':
                case 'WATERING':
                    // Ventil-Rückkanal von Gardena prüfen
                    $ventilOffen = GetValue($zone['ValveID']);
                    
                    if ($ventilOffen && $aktuellerStatus === 'VERIFYING_START') {
                        $this->SetBuffer('Status_' . $zone['SensorID'], 'WATERING');
                    } elseif (!$ventilOffen && $aktuellerStatus === 'WATERING') {
                        // Ventil hat planmäßig geschlossen -> Sickerpause starten
                        $this->SetBuffer('Status_' . $zone['SensorID'], 'WAITING_FOR_RESULT');
                        $this->SetBuffer('SickerpauseStart_' . $zone['SensorID'], time());
                    }
                    break;

                case 'WAITING_FOR_RESULT':
                    $sickerStart = (int)$this->GetBuffer('SickerpauseStart_' . $zone['SensorID']);
                    // 15 Minuten (900 Sekunden) Sickerpause abwarten
                    if ((time() - $sickerStart) > 900) {
                        
                        // Thermodynamischen Gesamtverlustfaktor berechnen
                        $verdunstungsFaktorVPD = ($vpd > 1.2) ? (($vpd - 1.2) * 10 * 0.02) : 0.0;
                        $verdunstungsFaktorLux = ($lux > 20000) ? (($lux - 20000) / 10000) * 0.015 : 0.0;
                        $gesamtVerlustFaktor = 1.0 + $verdunstungsFaktorVPD + $verdunstungsFaktorLux;

                        // Lernerfolg auswerten
                        $startFeuchte = (float)$this->GetBuffer('StartFeuchte_' . $zone['SensorID']);
                        $dauer = (int)$this->GetBuffer('Dauer_' . $zone['SensorID']);
                        
                        $erreichteFeuchte = $aktuelleFeuchte - $startFeuchte;
                        $korrigiertesErgebnis = $erreichteFeuchte * $gesamtVerlustFaktor;
                        
                        // Neuen Effizienzfaktor sichern
                        if ($dauer > 0) {
                            $neueEffizienz = $korrigiertesErgebnis / $dauer;
                            $this->SetBuffer('Effizienz_' . $zone['SensorID'], $neueEffizienz);
                        }

                        $this->SetBuffer('Status_' . $zone['SensorID'], 'IDLE');
                    }
                    break;
            }
        }
    }
}