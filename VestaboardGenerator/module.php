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
        $lines = [];

        $list = json_decode($this->ReadPropertyString("VariablesList"), true);
        if (!is_array($list)) {
            $list = [];
        }

        // Nach der vom Nutzer gesetzten Priorität sortieren
        usort($list, function($a, $b) {
            $prioA = isset($a['Priority']) ? (int)$a['Priority'] : 99;
            $prioB = isset($b['Priority']) ? (int)$b['Priority'] : 99;
            return $prioA <=> $prioB;
        });

        foreach ($list as $row) {
            if (!$row['Active'] || $row['VariableID'] == 0) {
                continue;
            }
            
            $id = $row['VariableID'];
            $type = $row['Type'];
            
            switch ($type) {
                case 'ofen':
                    if (GetValue($id)) {
                        $lines[] = ["text" => "Ofen: Angefeuert {64}"];
                    }
                    break;
                case 'keller':
                    if (GetValue($id)) {
                        $lines[] = ["text" => "Keller: Jetzt Lueften!"];
                    }
                    break;
                case 'wm':
                    $prozent = max(0, min(100, GetValue($id)));
                    if ($prozent > 0) {
                        $lines[] = ["text" => $this->GenerateProgressBar("WM", $prozent, "{68}")];
                    }
                    break;
                case 'tr':
                    $prozent = max(0, min(100, GetValue($id)));
                    if ($prozent > 0) {
                        $lines[] = ["text" => $this->GenerateProgressBar("TR", $prozent, "{67}")];
                    }
                    break;
                case 'brief':
                    if (GetValue($id)) {
                        $lines[] = ["text" => "Briefkasten: Voll {64}"];
                    }
                    break;
                case 'muell':
                    $muell = GetValue($id);
                    if ($muell == 1) $lines[] = ["text" => "Muell: Bio   "];
                    if ($muell == 2) $lines[] = ["text" => "Muell: Papier"];
                    if ($muell == 3) $lines[] = ["text" => "Muell: Rest  "];
                    break;
                case 'sbahn':
                    $lines[] = ["text" => "S2: " . GetValue($id)];
                    break;
                case 'aussen':
                    $lines[] = ["text" => "Aussen: " . GetValue($id) . " {62}C "];
                    break;
            }
        }

        // Maximal 6 Zeilen extrahieren
        $finalLines = array_slice($lines, 0, 6);

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
