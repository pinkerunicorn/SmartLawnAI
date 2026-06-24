<?php

trait SmartLawnAI_Helpers {

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
}
