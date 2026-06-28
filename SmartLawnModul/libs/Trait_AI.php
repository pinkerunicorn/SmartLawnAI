<?php

trait SmartLawnAI_AI {

    public function ProcessGeminiRetry(): void {
        $queueStr = $this->GetBuffer('GeminiRetryQueue');
        if (empty($queueStr)) {
            $this->SetTimerInterval('GeminiRetryTimer', 0);
            return;
        }

        $queue = json_decode($queueStr, true);
        if (!is_array($queue) || empty($queue)) {
            $this->SetTimerInterval('GeminiRetryTimer', 0);
            return;
        }

        // Hole das erste Element aus der Queue
        $item = array_shift($queue);
        $this->SetBuffer('GeminiRetryQueue', json_encode($queue));
        
        if (empty($queue)) {
            $this->SetTimerInterval('GeminiRetryTimer', 0);
        }

        $this->LogAndDebug('Weather', "Starte Gemini Retry Versuch " . ($item['retryCount'] + 1) . " für Zone " . $item['zoneID'], 0);
        
        $this->EvaluateEfficiencyWithGemini(
            $item['zoneID'], 
            $item['startFeuchte'], 
            $item['aktuelleFeuchte'], 
            $item['dauer'], 
            $item['vpd'], 
            $item['lux'], 
            $item['retryCount'] + 1
        );
    }

    public function EvaluateEfficiencyWithGemini(int $zoneID, float $startFeuchte, float $aktuelleFeuchte, float $dauer, float $vpd, float $lux, int $retryCount = 0): void {
        $zoneName = 'Zone ' . $zoneID;
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (is_array($zones)) {
            foreach ($zones as $z) {
                if ($z['SensorID'] == $zoneID && !empty($z['GroupName'])) {
                    $zoneName = $z['GroupName'];
                    break;
                }
            }
        }

        $apiKey = trim($this->ReadPropertyString('GeminiApiKey'));
        $model = trim($this->ReadPropertyString('GeminiModel'));
        if (empty($apiKey)) {
            $this->LogAndDebug('Weather', 'Kein Gemini API-Key für Effizienz-Lernen konfiguriert.', 0);
            $this->AddLogEvent("{$zoneName}: Abschluss (ohne KI)", "Dauer: {$dauer} Min | VPD: " . number_format($vpd, 2) . " kPa | Feuchte: {$startFeuchte}% -> {$aktuelleFeuchte}%");
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
        
        $script = '<?php
            $ch = curl_init("' . $url . '");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ' . var_export($jsonPayload, true) . ');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            SLAI_ProcessGeminiResponse(' . $this->InstanceID . ', $result, $httpCode, ' . $zoneID . ', ' . $startFeuchte . ', ' . $aktuelleFeuchte . ', ' . $dauer . ', ' . $vpd . ', ' . $lux . ', ' . $retryCount . ');
        ';
        IPS_RunScriptText($script);
    }

    public function ProcessGeminiResponse(string $result, int $httpCode, int $zoneID, float $startFeuchte, float $aktuelleFeuchte, float $dauer, float $vpd, float $lux, int $retryCount): void {
        $zoneName = 'Zone ' . $zoneID;
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (is_array($zones)) {
            foreach ($zones as $z) {
                if ($z['SensorID'] == $zoneID && !empty($z['GroupName'])) {
                    $zoneName = $z['GroupName'];
                    break;
                }
            }
        }

        if ($httpCode === 200 && $result) {
            $data = json_decode($result, true);
            if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                $jsonText = $data['candidates'][0]['content']['parts'][0]['text'];
                $parsed = json_decode($jsonText, true);
                if (is_array($parsed) && isset($parsed['newEfficiencyMultiplier'])) {
                    $neueEffizienz = (float)$parsed['newEfficiencyMultiplier'];
                    $begruendung = $parsed['reasoning'];
                    
                    $this->SetValue('Effizienz_' . $zoneID, $neueEffizienz);
                    IPS_LogMessage('SmartLawnAI', "Gemini Effizienz-Lernen (Zone $zoneID): Der neue Faktor ist {$neueEffizienz}x. Begründung: $begruendung");
                    $this->AddLogEvent("{$zoneName}: KI-Lernen erfolgreich", "Neue Effizienz: {$neueEffizienz}x. Grund: {$begruendung}", '#9C27B0');
                    return;
                }
            }
        }
        
        if ($retryCount < 3) {
            $this->LogAndDebug('Weather', "Fehler beim Gemini Effizienz-Lernen für Zone $zoneID (HTTP $httpCode). Starte Retry in 5 Minuten (Versuch " . ($retryCount + 1) . ").", 0);
            
            $queueStr = $this->GetBuffer('GeminiRetryQueue');
            $queue = $queueStr ? json_decode($queueStr, true) : [];
            if (!is_array($queue)) $queue = [];
            
            $queue[] = [
                'zoneID' => $zoneID,
                'startFeuchte' => $startFeuchte,
                'aktuelleFeuchte' => $aktuelleFeuchte,
                'dauer' => $dauer,
                'vpd' => $vpd,
                'lux' => $lux,
                'retryCount' => $retryCount
            ];
            
            $this->SetBuffer('GeminiRetryQueue', json_encode($queue));
            $this->SetTimerInterval('GeminiRetryTimer', 300000);
        } else {
            $this->LogAndDebug('Weather', "Gemini Effizienz-Lernen für Zone $zoneID nach 3 Versuchen endgültig fehlgeschlagen (HTTP $httpCode).", 0);
            IPS_LogMessage('SmartLawnAI', "Gemini Effizienz-Lernen für Zone $zoneID endgültig fehlgeschlagen.");
            $this->AddLogEvent("{$zoneName}: KI-Lernen fehlgeschlagen", "HTTP Fehler $httpCode", '#F44336');
        }
    }
}
