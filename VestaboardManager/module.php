<?php
class VestaboardManager extends IPSModule {

    public function Create() {
        parent::Create();
        
        // Eigenschaften (Eingabefelder für die Instanz) anlegen
        $this->RegisterPropertyInteger("VarIdWaschmaschine", 0);
        $this->RegisterPropertyInteger("VarIdTrockner", 0);
        $this->RegisterPropertyInteger("VarIdBriefkasten", 0);
        $this->RegisterPropertyInteger("VarIdMuell", 0);
        $this->RegisterPropertyInteger("VarIdKeller", 0);
        $this->RegisterPropertyInteger("VarIdOfen", 0);
        $this->RegisterPropertyInteger("VarIdSbahn", 0);
        $this->RegisterPropertyInteger("VarIdAussen", 0);
        $this->RegisterPropertyInteger("VarIdPayloadOut", 0); // Die Variable, die das inkludierte Skript triggert
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
        $vars = ["VarIdWaschmaschine", "VarIdTrockner", "VarIdBriefkasten", "VarIdMuell", "VarIdKeller", "VarIdOfen", "VarIdSbahn", "VarIdAussen"];
        foreach ($vars as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        // Wird aufgerufen, wenn sich eine der überwachten Variablen ändert
        $this->UpdateBoard();
    }

    public function UpdateBoard() {
        $lines = []; // Unser Array für die Prioritäten-Queue

        // --- 1. PRIO: Ofen ---
        $idOfen = $this->ReadPropertyInteger("VarIdOfen");
        if ($idOfen > 0 && GetValue($idOfen)) {
            $lines[] = ["prio" => 10, "text" => "Ofen: Angefeuert {64}"];
        }

        // --- 2. PRIO: Keller ---
        $idKeller = $this->ReadPropertyInteger("VarIdKeller");
        if ($idKeller > 0 && GetValue($idKeller)) {
            $lines[] = ["prio" => 20, "text" => "Keller: Jetzt Lueften!"];
        }

        // --- 3. PRIO: Waschmaschine ---
        $idWm = $this->ReadPropertyInteger("VarIdWaschmaschine");
        if ($idWm > 0) {
            $prozent = max(0, min(100, GetValue($idWm)));
            if ($prozent > 0) {
                $lines[] = ["prio" => 30, "text" => $this->GenerateProgressBar("WM", $prozent, "{68}")];
            }
        }

        // --- 4. PRIO: Trockner ---
        $idTr = $this->ReadPropertyInteger("VarIdTrockner");
        if ($idTr > 0) {
            $prozent = max(0, min(100, GetValue($idTr)));
            if ($prozent > 0) {
                $lines[] = ["prio" => 40, "text" => $this->GenerateProgressBar("TR", $prozent, "{67}")];
            }
        }

        // --- 5. PRIO: Briefkasten ---
        $idBriefkasten = $this->ReadPropertyInteger("VarIdBriefkasten");
        if ($idBriefkasten > 0 && GetValue($idBriefkasten)) {
            $lines[] = ["prio" => 50, "text" => "Briefkasten: Voll {64}"];
        }

        // --- 6. PRIO: Müll ---
        $idMuell = $this->ReadPropertyInteger("VarIdMuell");
        if ($idMuell > 0) {
            $muell = GetValue($idMuell);
            if ($muell == 1) $lines[] = ["prio" => 60, "text" => "Muell: Bio   "];
            if ($muell == 2) $lines[] = ["prio" => 60, "text" => "Muell: Papier"];
            if ($muell == 3) $lines[] = ["prio" => 60, "text" => "Muell: Rest  "];
        }

        // --- 7. PRIO: S-Bahn ---
        $idSbahn = $this->ReadPropertyInteger("VarIdSbahn");
        if ($idSbahn > 0) {
            $lines[] = ["prio" => 80, "text" => "S2: " . GetValue($idSbahn)];
        }

        // --- 8. PRIO: Außen ---
        $idAussen = $this->ReadPropertyInteger("VarIdAussen");
        if ($idAussen > 0) {
            $lines[] = ["prio" => 90, "text" => "Aussen: " . GetValue($idAussen) . " {62}C "];
        }

        // --- SORTIEREN & ABSCHNEIDEN ---
        // Nach Prio sortieren (kleinste Zahl = höchste Wichtigkeit)
        usort($lines, function($a, $b) {
            return $a['prio'] <=> $b['prio'];
        });

        // Maximal 6 Zeilen extrahieren
        $finalLines = array_slice($lines, 0, 6);

        // String zusammenbauen
        $textBasis = "";
        foreach ($finalLines as $line) {
            $textBasis .= $line['text'] . "\\n";
        }

        // Output generieren
        $inputArray = [
            "components" => [
                [
                    "style" => [
                        "justify" => "left",
                        "align" => "center"
                    ],
                    "template" => rtrim($textBasis, "\\n")
                ]
            ]
        ];
        $input = json_encode($inputArray);
        
        $idOut = $this->ReadPropertyInteger("VarIdPayloadOut");
        if ($idOut > 0) {
            SetValue($idOut, $input);
            // Hier müsstest du überlegen, wie das inkludierte Sende-Skript aufgerufen wird. 
            // Am besten bindest du dort ein Ereignis an die $idOut Variable, das triggert, sobald diese aktualisiert wird.
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
