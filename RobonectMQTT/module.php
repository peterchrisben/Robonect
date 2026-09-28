<?php

declare(strict_types=1);

/**
 * Robonect MQTT
 *
 * Optionale Ergänzung zum Robonect Wifi Modul: empfängt die MQTT-Nachrichten des Robonect-Moduls
 * über den Symcon MQTT-Server und reicht sie an die ausgewählte Robonect-Instanz weiter.
 */
class RobonectMQTT extends IPSModule
{
    // Symcon MQTT-Server
    private const MQTT_SERVER = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';

    // Robonect Wifi Modul
    private const ROBONECT_MODULE = '{169FA82D-CFA6-EF9C-6490-AFFC882D0181}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('RobonectInstance', 0);
        $this->RegisterPropertyString('MQTTTopic', 'Robonect');
        $this->RegisterPropertyBoolean('DebugLog', false);

        // mit vorhandenem MQTT-Server verbinden bzw. einen anlegen
        $this->ConnectParent(self::MQTT_SERVER);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $topic = trim(trim($this->ReadPropertyString('MQTTTopic')), '/');
        $this->SetReceiveDataFilter($this->buildReceiveDataFilter($topic));
        $this->SetSummary($topic);

        $target = $this->ReadPropertyInteger('RobonectInstance');
        if ($topic == '') {
            $this->SetStatus(200);
        } elseif (!$this->isRobonectInstance($target)) {
            $this->SetStatus(201);
        } else {
            $this->SetStatus(IS_ACTIVE);
        }

        // Verweis, damit Symcon die Verbindung zur Robonect-Instanz kennt
        foreach ($this->GetReferenceList() as $reference) {
            $this->UnregisterReference($reference);
        }
        if ($target > 0 && IPS_ObjectExists($target)) {
            $this->RegisterReference($target);
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['Topic'], $data['Payload'])) {
            return '';
        }

        $topic = trim(trim($this->ReadPropertyString('MQTTTopic')), '/');
        if ($topic == '' || strpos($data['Topic'], $topic . '/') !== 0) {
            return '';
        }

        // Der MQTT-Server liefert die Nutzdaten ab IP-Symcon 6.3 zusätzlich UTF-8 kodiert
        $payload = (string) $data['Payload'];
        if (IPS_GetKernelDate() > 1670886000) {
            $payload = mb_convert_encoding($payload, 'ISO-8859-1', 'UTF-8');
        }

        $subTopic = substr($data['Topic'], strlen($topic));
        if ($this->ReadPropertyBoolean('DebugLog')) {
            $this->SendDebug('MQTT', $data['Topic'] . ' = ' . $payload, 0);
        }

        $target = $this->ReadPropertyInteger('RobonectInstance');
        if (!$this->isRobonectInstance($target)) {
            return '';
        }
        ROBONECT_ProcessMQTT($target, $subTopic, $payload);
        return '';
    }

    private function isRobonectInstance(int $id): bool
    {
        return $id > 0 && IPS_InstanceExists($id) && IPS_GetInstance($id)['ModuleInfo']['ModuleID'] == self::ROBONECT_MODULE;
    }

    private function buildReceiveDataFilter(string $topic): string
    {
        if ($topic == '') {
            // ohne Topic keine Daten verarbeiten
            return '.*"Topic":"-ROBONECT-NO-TOPIC-".*';
        }
        // Schrägstriche können im JSON als "\/" maskiert sein
        $parts = array_map(function (string $part): string
        {
            return preg_quote($part, '/');
        }, explode('/', $topic));
        return '.*"Topic":"' . implode('\\\\?\/', $parts) . '\\\\?\/.*';
    }
}
