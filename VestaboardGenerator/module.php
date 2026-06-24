<?php
class VestaboardGenerator extends IPSModule {

    public function Create() {
        parent::Create();
        
        // Eigenschaften (Eingabefelder für die Instanz) anlegen
        $this->RegisterPropertyString("VariablesList", "[]");
        $this->RegisterPropertyInteger("InstIdVestaboardLocal", 0); // Die InstanzID vom Vestaboard Local Modul
    }

    public function ApplyChanges() {
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
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        // Wird aufgerufen, wenn sich eine der überwachten Variablen ändert
        $this->UpdateBoard();
    }

    public function UpdateBoard() {
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
            
            $text = "";
            switch ($type) {
                case 'ofen':
                    if (GetValue($id)) {
                        $text = $this->PadToRight("Ofen: Angefeuert", "{64}"); // {64} Orange
                    }
                    break;
                case 'keller':
                    if (GetValue($id)) {
                        $text = $this->PadToRight("Keller lueften", "{67}"); // {67} Blau
                    }
                    break;
                case 'wm':
                    $prozent = max(0, min(100, GetValue($id)));
                    if ($prozent > 0) {
                        $text = $this->GenerateProgressBar("WM", $prozent, "{68}");
                    }
                    break;
                case 'tr':
                    $prozent = max(0, min(100, GetValue($id)));
                    if ($prozent > 0) {
                        $text = $this->GenerateProgressBar("TR", $prozent, "{67}");
                    }
                    break;
                case 'brief':
                    if (GetValue($id)) {
                        $text = $this->PadToRight("Briefkasten voll", "{65}"); // {65} Gelb
                    }
                    break;
                case 'muell':
                    $muell = GetValue($id);
                    if ($muell == 1) $text = $this->PadToRight("Biotonne", "{66}"); // Grün
                    if ($muell == 2) $text = $this->PadToRight("Papiertonne", "{67}"); // Blau
                    if ($muell == 3) $text = $this->PadToRight("Restmuell", "{70}"); // Schwarz
                    break;
                case 'sbahn':
                    $sbahnWert = (string)GetValue($id);
                    // Kein spezielles rechtes Icon, aber Text wird garantiert auf 22 gekürzt
                    $text = $this->PadToRight("S2: " . $sbahnWert, ""); 
                    break;
                case 'aussen':
                    $temp = (float)GetValue($id);
                    $color = "{69}"; // Weiß (Neutral)
                    if ($temp < 0) $color = "{67}"; // Blau (Kalt)
                    if ($temp > 25) $color = "{63}"; // Rot (Warm)
                    
                    $text = $this->PadToRight("Aussen: " . round($temp, 1) . "{62}C", $color);
                    break;
                case 'garten':
                    $val = GetValue($id);
                    $isActive = false;
                    
                    if (is_bool($val)) {
                        $isActive = $val;
                    } else if (is_int($val) || is_float($val)) {
                        $isActive = ($val > 0);
                    } else if (is_string($val)) {
                        // Kompatibilität mit SmartLawnAI "SummaryStatus" oder "Status_X"
                        $valUpper = strtoupper($val);
                        if (strpos($valUpper, 'WATERING') !== false || strpos($valUpper, 'BEWÄSSERE') !== false || strpos($valUpper, 'BEWAESSERE') !== false) {
                            $isActive = true;
                        }
                    }

                    if ($isActive) {
                        // Blaue Anzeige {67} für aktives Wasser
                        $text = $this->PadToRight("Gartenbewaesserung", "{67}"); 
                    }
                    break;
            }

            if ($text != "") {
                if ($prio === 'high') {
                    $linesHigh[] = ["text" => $text];
                } else {
                    $linesLow[] = ["text" => $text];
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

        // String zusammenbauen
        $textBasis = "";
        foreach ($finalLines as $line) {
            $textBasis .= $line['text'] . "\n";
        }
        $textBasis = rtrim($textBasis, "\n"); // Letzten Zeilenumbruch entfernen
        
        $instId = $this->ReadPropertyInteger("InstIdVestaboardLocal");
        if ($instId > 0 && IPS_InstanceExists($instId)) {
            // Direkt die Funktion der Vestaboard Local Instanz aufrufen
            VESTA_SendMessage($instId, $textBasis);
        } else {
            IPS_LogMessage("Vestaboard Generator", "Keine gueltige Vestaboard Local Instanz hinterlegt.");
        }
    }

    private function GetVisualLength($text) {
        $visualLength = strlen($text);
        if (preg_match_all('/\{\d{1,2}\}/', $text, $matches)) {
            foreach ($matches[0] as $match) {
                $visualLength -= strlen($match); 
                $visualLength += 1; 
            }
        }
        return $visualLength;
    }

    private function PadToRight($leftText, $rightIcon) {
        $leftLen = $this->GetVisualLength($leftText);
        $rightLen = $this->GetVisualLength($rightIcon);
        
        if ($leftLen + $rightLen > 22) {
            // Kürzen (einfacher substr reicht, da zu lange Texte bei uns nur reiner Text von der S-Bahn sind)
            $allowedLen = 22 - $rightLen;
            $leftText = substr($leftText, 0, $allowedLen); 
            $leftLen = $this->GetVisualLength($leftText);
        }
        
        $spacesNeeded = 22 - $leftLen - $rightLen;
        return $leftText . str_repeat(" ", max(0, $spacesNeeded)) . $rightIcon;
    }

    private function GenerateProgressBar($prefix, $prozent, $defaultColor) {
        $colorCode = ($prozent >= 100) ? "{66}" : $defaultColor;
        $text = sprintf("%s %3d%%: ", $prefix, $prozent);
        
        $balkenBreite = 13;
        $gefuellteSpalten = (int)round(($prozent / 100) * $balkenBreite);
        $leereSpalten = $balkenBreite - $gefuellteSpalten;
        
        $balken = str_repeat($colorCode, $gefuellteSpalten) . str_repeat(" ", $leereSpalten);
        return $text . $balken;
    }
}
?>
