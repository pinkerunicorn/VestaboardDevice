<?php
declare(strict_types=1);

class VestaboardGenerator extends IPSModuleStrict {

    public function Create(): void {
        parent::Create();
        
        // Eigenschaften (Eingabefelder für die Instanz) anlegen
        $this->RegisterPropertyString("VariablesList", "[]");
        $this->RegisterPropertyInteger("InstIdVestaboardLocal", 0); // Die InstanzID vom Vestaboard Local Modul
        $this->RegisterPropertyInteger("ActiveTimeStart", 7);
        $this->RegisterPropertyInteger("ActiveTimeEnd", 22);
        $this->RegisterPropertyInteger("UpdateDelayMinutes", 1);
        $this->RegisterPropertyString("SleepText", "");

        $this->RegisterTimer("VestaboardUpdateTimer", 0, 'VESTA_UpdateBoard($_IPS[\'TARGET\']);');
        $this->RegisterTimer("VestaboardSleepTimer", 0, 'VESTA_SendSleepText($_IPS[\'TARGET\']);');

        for ($i = 1; $i <= 6; $i++) {
            $this->RegisterVariableString("Line{$i}", "Zeile {$i}", "", $i);
        }
    }

    public function ApplyChanges(): void {
        parent::ApplyChanges();
        
        // Alte Registrierungen löschen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
        
        // Auf Variablen-Updates lauschen (MessageSink)
        $list = json_decode($this->ReadPropertyString("VariablesList"), true);
        if (is_array($list)) {
            foreach ($list as $row) {
                if ($row['Active'] && $row['VariableID'] > 0 && IPS_VariableExists($row['VariableID'])) {
                    $this->RegisterMessage($row['VariableID'], VM_UPDATE);
                }
            }
        }
        
        $this->UpdateSleepTimer();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void {
        // Wird aufgerufen, wenn sich eine der überwachten Variablen ändert
        $delayMin = $this->ReadPropertyInteger("UpdateDelayMinutes");
        $delaySec = $delayMin * 60;
        if ($delaySec > 0) {
            if ($this->GetTimerInterval('VestaboardUpdateTimer') == 0) {
                $this->SetTimerInterval('VestaboardUpdateTimer', $delaySec * 1000);
            }
        } else {
            $this->UpdateBoard();
        }
    }

    public function UpdateBoard(): void {
        $this->SetTimerInterval('VestaboardUpdateTimer', 0);
        
        $linesHigh = [];
        $linesLow = [];

        $list = json_decode($this->ReadPropertyString("VariablesList"), true);
        if (!is_array($list)) {
            $list = [];
        }

        foreach ($list as $row) {
            if (!$row['Active'] || $row['VariableID'] == 0) {
                continue;
            }
            
            $id = $row['VariableID'];
            $type = $row['Type'];
            $prio = isset($row['Priority']) ? $row['Priority'] : 'low';
            $format = isset($row['FormatString']) ? $row['FormatString'] : '';
            
            $text = $this->GetLineText($type, $id, $format);
            $cleanText = trim(preg_replace('/\{\d{1,2}\}/', '', $text));

            if ($cleanText !== "") {
                if ($prio === 'high') {
                    $linesHigh[] = ["text" => $text, "clean" => $cleanText];
                } else {
                    $linesLow[] = ["text" => $text, "clean" => $cleanText];
                }
            }
        }

        // Alle 'Hoch' Prioritäten einfügen
        $finalLines = $linesHigh;

        // Wenn noch Platz ist, fülle mit 'Niedrig' auf. 
        // Das Vestaboard hat genau 6 nutzbare Zeilen.
        $remainingSpace = 6 - count($finalLines);
        if ($remainingSpace > 0) {
            $finalLines = array_merge($finalLines, array_slice($linesLow, 0, $remainingSpace));
        }

        // Maximal 6 Zeilen extrahieren (falls es z.B. 7 High-Prios gibt)
        $finalLines = array_slice($finalLines, 0, 6);

        // String zusammenbauen und Variablen updaten
        $textBasis = "";
        for ($i = 0; $i < 6; $i++) {
            if (isset($finalLines[$i])) {
                $textBasis .= $finalLines[$i]['text'] . "\n";
                $this->SetValue("Line" . ($i + 1), $finalLines[$i]['clean']);
            } else {
                $this->SetValue("Line" . ($i + 1), "");
            }
        }
        $textBasis = rtrim($textBasis, "\n"); // Letzten Zeilenumbruch entfernen
        
        $instId = $this->ReadPropertyInteger("InstIdVestaboardLocal");
        $activeStart = $this->ReadPropertyInteger("ActiveTimeStart");
        $activeEnd = $this->ReadPropertyInteger("ActiveTimeEnd");
        $currentHour = (int)date('G');

        $isActiveTime = true;
        if ($activeStart != $activeEnd) {
            if ($activeStart < $activeEnd) {
                if ($currentHour < $activeStart || $currentHour >= $activeEnd) {
                    $isActiveTime = false;
                }
            } else {
                if ($currentHour >= $activeEnd && $currentHour < $activeStart) {
                    $isActiveTime = false;
                }
            }
        }

        if ($instId > 0 && IPS_InstanceExists($instId)) {
            if ($isActiveTime) {
                // Direkt die Funktion der Vestaboard Local Instanz aufrufen
                VESTA_SendMessage($instId, $textBasis);
            } else {
                IPS_LogMessage("Vestaboard Generator", "Aktualisierung uebersprungen (Ruhezeit aktiv: " . $currentHour . " Uhr)");
            }
        } else {
            IPS_LogMessage("Vestaboard Generator", "Keine gueltige Vestaboard Local Instanz hinterlegt.");
        }
    }

    private function GetLineText(string $type, int $id, string $format): string {
        $text = "";
        
        if ($type !== 'text' && $type !== 'empty') {
            if ($id <= 0 || !IPS_VariableExists($id)) {
                return "";
            }
        }
        
        switch ($type) {
            case 'alert':
                $val = GetValue($id);
                $isActive = (is_bool($val) && $val) || ((is_int($val) || is_float($val)) && $val > 0);
                if ($isActive && $format != "") {
                    $text = $this->PadToRight($format, "");
                }
                break;
            case 'wm':
            case 'tr':
                $prozent = max(0, min(100, (int)GetValue($id)));
                if ($prozent > 0) {
                    $prefix = ($type === 'tr') ? "TR" : "WM";
                    $color = ($type === 'tr') ? "{67}" : "{68}";
                    
                    if ($format != "") {
                        if (preg_match('/\{\d{1,2}\}/', $format, $matches)) {
                            $color = $matches[0];
                            $prefix = trim(str_replace($color, "", $format));
                        } else {
                            $prefix = $format;
                        }
                    }
                    
                    $text = $this->GenerateProgressBar($prefix, $prozent, $color);
                }
                break;
            case 'aussen':
                $temp = (float)GetValue($id);
                $color = "{69}"; // Weiß (Neutral)
                if ($temp < 0) $color = "{67}"; // Blau (Kalt)
                if ($temp > 25) $color = "{63}"; // Rot (Warm)
                
                if ($format != "") {
                    if (strpos($format, '%s') !== false || strpos($format, '%f') !== false) {
                        $textStr = sprintf($format, round($temp, 1));
                    } else {
                        // Wenn sie z.B. nur "Pool: " eingegeben haben
                        $textStr = $format . round($temp, 1) . "{62}C";
                    }
                } else {
                    // Standardausgabe nur die Zahl + C
                    $textStr = round($temp, 1) . "{62}C";
                }
                
                $text = $this->PadToRight($textStr, $color);
                break;
            case 'custom':
                $val = GetValue($id);
                if (is_bool($val)) {
                    $val = $val ? 'Ein' : 'Aus';
                } else {
                    $val = (string)$val;
                }
                
                if ($format != "") {
                    if (strpos($format, '%s') !== false || strpos($format, '%d') !== false || strpos($format, '%f') !== false) {
                        $text = sprintf($format, $val);
                    } else {
                        $text = $format . $val;
                    }
                } else {
                    $text = $val;
                }
                // Die Formatierung (inkl. Längenbegrenzung) anwenden. 
                // Farbcodes im Text werden durch GetVisualLength in PadToRight korrekt behandelt.
                $text = $this->PadToRight($text, "");
                break;
        }
        return $text;
    }

    private function GetVisualLength(string $text): int {
        $visualLength = mb_strlen($text, 'UTF-8');
        if (preg_match_all('/\{\d{1,2}\}/', $text, $matches)) {
            foreach ($matches[0] as $match) {
                $visualLength -= mb_strlen($match, 'UTF-8'); 
                $visualLength += 1; 
            }
        }
        return $visualLength;
    }
    private function SanitizeTextForVestaboard(string $text): string {
        $search = ['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'];
        $replace = ['ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue', 'ss'];
        return str_replace($search, $replace, $text);
    }

    private function PadToRight(string $leftText, string $rightIcon = ""): string {
        $leftText = $this->SanitizeTextForVestaboard($leftText);
        // Smart-Extraktion: Wenn das rechte Icon leer ist, aber der User am Ende des Textes
        // einen Farbcode (z.B. {66}) angegeben hat, ziehen wir diesen automatisch nach ganz rechts.
        if ($rightIcon === "" && preg_match('/\s*(\{\d{1,2}\})\s*$/', $leftText, $matches)) {
            $rightIcon = $matches[1];
            $leftText = preg_replace('/\s*\{\d{1,2}\}\s*$/', '', $leftText);
        }
        
        $leftLen = $this->GetVisualLength($leftText);
        $rightLen = $this->GetVisualLength($rightIcon);
        
        if ($leftLen + $rightLen > 22) {
            // Kürzen
            $allowedLen = 22 - $rightLen;
            $leftText = mb_substr($leftText, 0, $allowedLen, 'UTF-8'); 
            $leftLen = $this->GetVisualLength($leftText);
        }
        
        $spacesNeeded = 22 - $leftLen - $rightLen;
        return $leftText . str_repeat(" ", max(0, $spacesNeeded)) . $rightIcon;
    }

    private function GenerateProgressBar(string $prefix, int $prozent, string $defaultColor): string {
        $prefix = $this->SanitizeTextForVestaboard($prefix);
        $colorCode = ($prozent >= 100) ? "{66}" : $defaultColor;
        
        $suffix = sprintf(" %d%%:", $prozent);
        $prefixLen = mb_strlen($prefix, 'UTF-8');
        $suffixLen = mb_strlen($suffix, 'UTF-8');
        
        // Mindestens 5 Blöcke wollen wir für den Balken haben (Maximal also 17 Zeichen für Text)
        if ($prefixLen + $suffixLen > 17) {
            $prefix = mb_substr($prefix, 0, 17 - $suffixLen, 'UTF-8');
            $prefixLen = mb_strlen($prefix, 'UTF-8');
        }
        
        $text = $prefix . $suffix;
        $balkenBreite = 22 - ($prefixLen + $suffixLen);
        
        if ($balkenBreite > 0) {
            $gefuellteSpalten = (int)round(($prozent / 100) * $balkenBreite);
            $leereSpalten = $balkenBreite - $gefuellteSpalten;
            
            $balken = str_repeat($colorCode, $gefuellteSpalten) . str_repeat(" ", $leereSpalten);
            return $text . $balken;
        }
        return $text;
    }

    public function SendSleepText(): void {
        $sleepText = $this->ReadPropertyString("SleepText");
        $instId = $this->ReadPropertyInteger("InstIdVestaboardLocal");

        if ($sleepText !== "" && $instId > 0 && IPS_InstanceExists($instId)) {
            VESTA_SendMessage($instId, $sleepText);
        }
        
        $this->UpdateSleepTimer();
    }

    private function UpdateSleepTimer(): void {
        $activeEnd = $this->ReadPropertyInteger("ActiveTimeEnd");
        $sleepText = $this->ReadPropertyString("SleepText");

        if ($sleepText === "") {
            $this->SetTimerInterval("VestaboardSleepTimer", 0);
            return;
        }

        $now = time();
        // mktime(hour, minute, second, month, day, year)
        $targetTime = mktime($activeEnd, 0, 0, (int)date('m'), (int)date('d'), (int)date('Y'));

        if ($targetTime <= $now) {
            // Wenn die Zeit für heute schon vorbei ist, auf morgen setzen
            $targetTime = strtotime('+1 day', $targetTime);
        }

        $interval = ($targetTime - $now) * 1000;
        $this->SetTimerInterval("VestaboardSleepTimer", $interval);
    }
}
?>
