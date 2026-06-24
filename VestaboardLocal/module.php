<?php
class VestaboardLocal extends IPSModule {

    public function Create() {
        parent::Create();
        
        // Standard-Eigenschaften für das Konfigurationsformular anlegen
        $this->RegisterPropertyString("ApiUrl", "http://<IP-ADRESSE>:7000/local-api/message");
        $this->RegisterPropertyString("ApiKey", "");
        $this->RegisterPropertyString("AlignHorizontal", "center");
        $this->RegisterPropertyString("AlignVertical", "center");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        $localUrl = $this->ReadPropertyString("ApiUrl");
        $apiKey = $this->ReadPropertyString("ApiKey");

        // Wenn kein Key oder noch die Platzhalter-IP drin ist -> Status Inaktiv/Fehler
        if (empty($localUrl) || strpos($localUrl, '<IP-ADRESSE>') !== false || empty($apiKey)) {
            $this->SetStatus(104); // IS_INACTIVE
        } else {
            $this->SetStatus(102); // IS_ACTIVE
        }
    }

    /**
     * Sendet einen Text erst an den Vestaboard Cloud-Compiler und dann an das lokale Board
     * Aufruf: VESTA_SendMessage(InstanzID, "Dein Text");
     */
    public function SendMessage(string $Text) {
        $localUrl = $this->ReadPropertyString("ApiUrl");
        $apiKey = $this->ReadPropertyString("ApiKey");
        $justify = $this->ReadPropertyString("AlignHorizontal");
        $align = $this->ReadPropertyString("AlignVertical");

        if (empty($localUrl) || empty($apiKey)) {
            IPS_LogMessage("Vestaboard", "Fehler: Lokale URL oder API-Key nicht konfiguriert.");
            return false;
        }

        // ====================================================================
        // SCHRITT 1: Payload für die Cloud-Übersetzung (Compiler) bauen
        // ====================================================================
        $cloudUrl = "https://vbml.vestaboard.com/compose";
        $inputArray = [
            "components" => [
                [
                    "style" => [
                        "justify" => $justify,
                        "align" => $align
                    ],
                    "template" => $Text
                ]
            ]
        ];
        $cloudPayload = json_encode($inputArray);

        // cURL Request an die Vestaboard Cloud
        $chCloud = curl_init($cloudUrl);
        curl_setopt($chCloud, CURLOPT_POST, true);
        curl_setopt($chCloud, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chCloud, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($chCloud, CURLOPT_POSTFIELDS, $cloudPayload);
        curl_setopt($chCloud, CURLOPT_TIMEOUT, 5);
        
        $compiledBoardJson = curl_exec($chCloud);
        $cloudHttpCode = curl_getinfo($chCloud, CURLINFO_HTTP_CODE);
        curl_close($chCloud);

        // Prüfen, ob die Cloud ein sauberes JSON-Array zurückgeliefert hat
        if ($cloudHttpCode < 200 || $cloudHttpCode >= 300 || empty($compiledBoardJson)) {
            IPS_LogMessage("Vestaboard", "Cloud-Kompilierung fehlgeschlagen! HTTP Code: " . $cloudHttpCode);
            return false;
        }

        // ====================================================================
        // SCHRITT 2: Das fertig berechnete Array ans lokale Board senden
        // ====================================================================
        $headersLocal = [
            'X-Vestaboard-Local-Api-Key: ' . $apiKey,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($compiledBoardJson)
        ];

        $chLocal = curl_init($localUrl);
        curl_setopt($chLocal, CURLOPT_POST, true);
        curl_setopt($chLocal, CURLOPT_POSTFIELDS, $compiledBoardJson);
        curl_setopt($chLocal, CURLOPT_HTTPHEADER, $headersLocal);
        curl_setopt($chLocal, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chLocal, CURLOPT_TIMEOUT, 10);
        curl_setopt($chLocal, CURLOPT_CONNECTTIMEOUT, 5);

        $responseLocal = curl_exec($chLocal);
        
        if (curl_errno($chLocal)) {
            IPS_LogMessage("Vestaboard", "Lokaler cURL Fehler: " . curl_error($chLocal));
            curl_close($chLocal);
            return false;
        } else {
            $localHttpCode = curl_getinfo($chLocal, CURLINFO_HTTP_CODE);
            curl_close($chLocal);
            
            if ($localHttpCode >= 200 && $localHttpCode < 300) {
                IPS_LogMessage("Vestaboard", "Erfolgreich kompiliert und lokal gesendet.");
                return true;
            } else {
                IPS_LogMessage("Vestaboard", "Lokaler API Fehler! HTTP Code: " . $localHttpCode . " Response: " . $responseLocal);
                return false;
            }
        }
    }
}
?>
