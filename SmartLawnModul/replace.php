<?php
$content = file_get_contents('module.php');

// Add AutomaticActive to ApplyChanges
$applyChangesOld = <<<'PHP'
        $zones = json_decode($zonesJson, true);
PHP;
$applyChangesNew = <<<'PHP'
        $this->RegisterVariableBoolean('AutomaticActive', 'Automatik aktiv', '~Switch', 0);
        $this->EnableAction('AutomaticActive');
        if (!IPS_VariableExists($this->GetIDForIdent('AutomaticActive')) || GetValue($this->GetIDForIdent('AutomaticActive')) === false && IPS_GetVariable($this->GetIDForIdent('AutomaticActive'))['VariableUpdated'] == 0) {
            SetValue($this->GetIDForIdent('AutomaticActive'), true); // Default true
        }

        $zones = json_decode($zonesJson, true);
PHP;
$content = str_replace($applyChangesOld, $applyChangesNew, $content);

// Add RequestAction method
$requestAction = <<<'PHP'
    public function RequestAction($Ident, $Value) {
        if ($Ident === 'AutomaticActive') {
            SetValue($this->GetIDForIdent($Ident), $Value);
        }
    }

    public function ApplyChanges() {
PHP;
$content = str_replace('    public function ApplyChanges() {', $requestAction, $content);

// ProcessLogic rewrite
$processLogicOld = <<<'PHP'
            switch ($aktuellerStatus) {
                case 'IDLE':
                case 'QUEUED':
                    if ($aktuelleFeuchte <= $startWert) {
                        if ($einVentilIstAktiv) {
PHP;
$processLogicNew = <<<'PHP'
            $automaticActive = GetValue($this->GetIDForIdent('AutomaticActive'));

            switch ($aktuellerStatus) {
                case 'IDLE':
                case 'QUEUED':
                    $sollStarten = false;
                    if ($aktuellerStatus === 'QUEUED') {
                        $sollStarten = true; 
                    } else if ($automaticActive && $aktuelleFeuchte <= $startWert) {
                        $sollStarten = true;
                    }

                    if ($sollStarten) {
                        if ($einVentilIstAktiv) {
PHP;
$content = str_replace($processLogicOld, $processLogicNew, $content);

$processLogicOld2 = <<<'PHP'
                    } else {
                        SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'IDLE');
                    }
                    break;
PHP;
$processLogicNew2 = <<<'PHP'
                    } else {
                        SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'IDLE');
                    }
                    break;
PHP;
// Actually the else block is fine because it's paired with `if ($sollStarten)` now! Wait. 
// Old: `if ($aktuelleFeuchte <= $startWert) { ... } else { SetValue(IDLE); }`
// New: `if ($sollStarten) { ... } else { SetValue(IDLE); }`
// The structure matches perfectly. I just need to make sure the curly braces match. Let's verify the replacement.
$processLogicOldReplace = <<<'PHP'
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
PHP;

$processLogicNewReplace = <<<'PHP'
            $automaticActive = GetValue($this->GetIDForIdent('AutomaticActive'));

            switch ($aktuellerStatus) {
                case 'IDLE':
                case 'QUEUED':
                    $sollStarten = false;
                    if ($aktuellerStatus === 'QUEUED') {
                        $sollStarten = true;
                    } else if ($automaticActive && $aktuelleFeuchte <= $startWert) {
                        $sollStarten = true;
                    }

                    if ($sollStarten) {
                        if ($einVentilIstAktiv) {
                            SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'QUEUED');
                        } else {
                            SetValue($this->GetIDForIdent('Status_' . $zone['SensorID']), 'VERIFYING_START');
                            
                            // KI-Laufzeitberechnung
                            $effizienz = (float)GetValue($this->GetIDForIdent('Effizienz_' . $zone['SensorID']));
                            if ($effizienz <= 0) $effizienz = 1.0; 
                            
                            $differenz = $zielWert - $aktuelleFeuchte;
                            if ($differenz <= 0) $differenz = 5.0; // Minimaler Feuchte-Hub für manuelle Starts
                            
                            $berechneteMinuten = (int)ceil($differenz / $effizienz);
PHP;
$content = str_replace($processLogicOldReplace, $processLogicNewReplace, $content);

// Add UIRequest modifications
$uiRequestOld = <<<'PHP'
    public function UIRequest($Action, $Payload) {
        $airTempID = $this->ReadPropertyInteger('GlobalAirTempID');
PHP;

$uiRequestNew = <<<'PHP'
    public function UIRequest($Action, $Payload) {
        switch ($Action) {
            case 'TOGGLE_AUTOMATIC':
                $id = $this->GetIDForIdent('AutomaticActive');
                SetValue($id, !GetValue($id));
                break;
            case 'FORCE_START_SEQUENCE':
                $zonesJson = $this->ReadPropertyString('Zones');
                $zones = json_decode($zonesJson, true);
                if (is_array($zones)) {
                    foreach ($zones as $zone) {
                        $sid = $zone['SensorID'];
                        $statusId = @$this->GetIDForIdent('Status_' . $sid);
                        if ($statusId > 0) {
                            $st = GetValue($statusId);
                            if ($st === 'IDLE') {
                                SetValue($statusId, 'QUEUED');
                            }
                        }
                    }
                }
                $this->ProcessLogic();
                break;
        }

        $airTempID = $this->ReadPropertyInteger('GlobalAirTempID');
PHP;
$content = str_replace($uiRequestOld, $uiRequestNew, $content);

// Update partyModeActive to automaticActive in UIRequest
$partyModeOld = <<<'PHP'
                'partyModeActive' => false
PHP;
$partyModeNew = <<<'PHP'
                'automaticActive' => GetValue($this->GetIDForIdent('AutomaticActive'))
PHP;
$content = str_replace($partyModeOld, $partyModeNew, $content);

file_put_contents('module.php', $content);
echo "module.php updated\n";
?>
