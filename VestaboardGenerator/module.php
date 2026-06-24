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
                        $text = "Ofen: Angefeuert {64}";
                    }
                    break;
                case 'keller':
                    if (GetValue($id)) {
                        $text = "Keller: Jetzt Lueften!";
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
                        $text = "Briefkasten: Voll {64}";
                    }
                    break;
                case 'muell':
                    $muell = GetValue($id);
                    if ($muell == 1) $text = "Muell: Bio   ";
                    if ($muell == 2) $text = "Muell: Papier";
                    if ($muell == 3) $text = "Muell: Rest  ";
                    break;
                case 'sbahn':
                    $text = "S2: " . GetValue($id);
                    break;
                case 'aussen':
                    $text = "Aussen: " . GetValue($id) . " {62}C ";
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
