<?php

declare(strict_types=1);

/**
 * Robonect Wifi Modul
 *
 * Einbindung eines Mähroboters mit Robonect-WLAN-Modul (z. B. Robonect HX) in IP-Symcon.
 * Die Daten werden per HTTP (JSON-API des Robonect-Moduls) abgefragt. Optional liefert die
 * Instanz "Robonect MQTT" (Kind des Symcon MQTT-Servers) Werte in Echtzeit über ProcessMQTT.
 * Diese Instanz selbst benötigt daher keine übergeordnete Instanz.
 *
 * Öffentliche Funktionen stehen mit dem Präfix ROBONECT (bzw. Robonect) zur Verfügung, z. B.:
 *   ROBONECT_Update($InstanzID);
 */
class RobonectWifiModul extends IPSModule
{
    // Wochentage in der Reihenfolge des Symcon Wochenplans (0 = Montag)
    private const WEEKDAYS = ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'su'];

    // erlaubte Modi für SetMode/ScheduleJob
    private const MODES = ['home', 'eod', 'man', 'auto'];

    // Anzahl Timer-Slots im Robonect-Modul
    private const TIMER_SLOTS = 14;

    // Instanz-Status
    private const STATUS_OK = 102;
    private const STATUS_CONFIG_MISSING = 200;
    private const STATUS_IP_INVALID = 201;
    private const STATUS_NO_DEVICE = 202;
    private const STATUS_NO_JSON = 203;
    private const STATUS_NO_ROBONECT = 204;
    private const STATUS_LOGIN_FAILED = 205;

    // MQTT-Topics (relativ zum eingestellten Topic, Kleinschreibung) => Ident
    private const MQTT_TOPICS = [
        '/mower/status'               => 'mowerStatus',
        '/mower/status/plain'         => 'mowerStatusPlain',
        '/mower/substatus'            => 'mowerSubstatus',
        '/mower/substatus/plain'      => 'mowerSubstatusPlain',
        '/mower/mode'                 => 'mowerMode',
        '/mower/stopped'              => 'mowerStopped',
        '/mower/status/duration'      => 'mowerStatusSinceDurationMin',
        '/mower/distance'             => 'mowerDistance',
        '/mower/battery/charge'       => 'mowerBatterySoc',
        '/mower/statistic/hours'      => 'mowerHours',
        '/mower/blades/quality'       => 'mowerBladesQuality',
        '/mower/blades/hours'         => 'mowerBladesOperatingHours',
        '/mower/blades/days'          => 'mowerBladesAge',
        '/mower/error/message'        => 'mowerCurrentError',
        '/health/voltage/batt'        => 'mowerVoltageBattery',
        '/health/voltage/int33'       => 'mowerVoltageInternal',
        '/health/voltage/ext33'       => 'mowerVoltageExternal',
        '/health/climate/temperature' => 'mowerTemperature',
        '/health/climate/humidity'    => 'mowerHumidity',
        '/wlan/rssi'                  => 'mowerWlanStatus',
        '/mqtt'                       => 'mowerMqttStatus',
        '/timer/next/unix'            => 'mowerNextTimerstart',
        '/mower/timer/next/unix'      => 'mowerNextTimerstart',
        '/gps/latitude'               => 'mowerGpsLatitudeRaw',
        '/gps/longitude'              => 'mowerGpsLongitudeRaw'
    ];

    public function Create()
    {
        // Wird einmalig beim Anlegen der Instanz und bei jedem Start von IP-Symcon aufgerufen
        parent::Create();

        // Login
        $this->RegisterPropertyString('IPAddress', '0.0.0.0');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');

        // Automatische Updates
        $this->RegisterPropertyBoolean('HTTPUpdateTimer', false);
        $this->RegisterPropertyInteger('UpdateTimer', 10);
        $this->RegisterPropertyBoolean('UpdateErrorsWithStatus', false);

        // Vorgabewerte
        $this->RegisterPropertyInteger('MowingTime', 180);

        // Debugging
        $this->RegisterPropertyBoolean('DebugLog', false);

        // Timer
        $this->RegisterTimer('ROBONECT_UpdateTimer', 0, 'ROBONECT_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        // Wird bei "Änderungen übernehmen" und nach dem Anlegen der Instanz aufgerufen
        parent::ApplyChanges();

        $this->registerVariables();

        // Zusammenfassung in der Instanzliste
        $this->SetSummary($this->ReadPropertyString('IPAddress'));

        // Timer
        $this->updateTimerInterval();

        // Status der Konfiguration
        $IPAddress = trim($this->ReadPropertyString('IPAddress'));
        if ($IPAddress == '' || $IPAddress == '0.0.0.0') {
            $this->SetStatus(self::STATUS_CONFIG_MISSING);
        } elseif (filter_var($IPAddress, FILTER_VALIDATE_IP) === false) {
            $this->SetStatus(self::STATUS_IP_INVALID);
        } elseif ($this->GetStatus() == self::STATUS_CONFIG_MISSING || $this->GetStatus() == self::STATUS_IP_INVALID) {
            $this->SetStatus(IS_ACTIVE);
        }
    }

    //=== Öffentliche Funktionen =====================================================================

    public function Update(): bool
    {
        $semaphore = 'Robonect' . $this->InstanceID . '_Update';
        if (!IPS_SemaphoreEnter($semaphore, 0)) {
            $this->log('Update', 'Update läuft bereits - übersprungen');
            return false;
        }

        try {
            // Status
            $data = $this->executeHTTPCommand('status');
            if ($data === false) {
                return false;
            }
            if (!empty($data['successful'])) {
                $this->processStatus($data);
            }

            // Health (Spannungen)
            $data = $this->executeHTTPCommand('health', true);
            if ($data !== false && !empty($data['successful'])) {
                if (isset($data['health']['voltages']['int3v3'])) {
                    $this->updateIdent('mowerVoltageInternal', $data['health']['voltages']['int3v3']);
                }
                if (isset($data['health']['voltages']['ext3v3'])) {
                    $this->updateIdent('mowerVoltageExternal', $data['health']['voltages']['ext3v3']);
                }
                if (isset($data['health']['voltages']['batt'])) {
                    $this->updateIdent('mowerVoltageBattery', $data['health']['voltages']['batt']);
                }
                if (isset($data['health']['climate']['temperature'])) {
                    $this->updateIdent('mowerTemperature', $data['health']['climate']['temperature']);
                }
                if (isset($data['health']['climate']['humidity'])) {
                    $this->updateIdent('mowerHumidity', $data['health']['climate']['humidity']);
                }
            }

            // GPS (nur wenn das Robonect-Modul GPS unterstützt)
            $data = $this->executeHTTPCommand('gps', true);
            if ($data !== false && !empty($data['successful'])) {
                if (isset($data['gps']['latitude'])) {
                    $this->updateIdent('mowerGpsLatitudeRaw', $data['gps']['latitude']);
                }
                if (isset($data['gps']['longitude'])) {
                    $this->updateIdent('mowerGpsLongitudeRaw', $data['gps']['longitude']);
                }
            }

            $this->SetValue('lastUpdate', time());
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }

        // Fehlerliste optional mit aktualisieren (eigene Sperre, daher außerhalb)
        if ($this->ReadPropertyBoolean('UpdateErrorsWithStatus')) {
            $this->UpdateErrorList();
        }

        return true;
    }

    public function UpdateErrorList(): bool
    {
        $semaphore = 'Robonect' . $this->InstanceID . '_ErrorList';
        if (!IPS_SemaphoreEnter($semaphore, 0)) {
            $this->log('UpdateErrorList', 'Abruf läuft bereits - übersprungen');
            return false;
        }

        try {
            $data = $this->executeHTTPCommand('error', true);
            if ($data === false || empty($data['successful']) || !isset($data['errors']) || !is_array($data['errors'])) {
                // Das Robonect-Modul liefert die Fehlerliste nicht immer zuverlässig
                $this->log('UpdateErrorList', 'Keine gültige Fehlerliste erhalten');
                return false;
            }

            $errors = array_values($data['errors']);
            $this->SetValue('mowerErrorCount', count($errors));
            $this->SetValue('mowerErrorList', $this->buildErrorListHTML($errors));
            return true;
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    public function ClearErrors(): bool
    {
        $data = $this->executeHTTPCommand('error&clear=1');
        if ($data === false) {
            return false;
        }
        $successful = !empty($data['successful']);
        if ($successful) {
            $this->SetValue('mowerErrorCount', 0);
            $this->SetValue('mowerErrorList', $this->buildErrorListHTML([]));
            $this->SetValue('mowerCurrentError', '');
        }
        return $successful;
    }

    public function Start(): bool
    {
        // Rasenmäher nach einem Stopp wieder starten (Rückkehr zum letzten Modus)
        return $this->executeSimpleCommand('start');
    }

    public function Stop(): bool
    {
        // Rasenmäher anhalten
        return $this->executeSimpleCommand('stop');
    }

    public function DriveHome(): bool
    {
        // Rasenmäher in die Ladestation schicken
        return $this->executeSimpleCommand('mode&mode=home');
    }

    public function SetMode(string $mode): bool
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::MODES, true)) {
            $this->log('SetMode', 'Ungültiger Modus: ' . $mode);
            return false;
        }
        return $this->executeSimpleCommand('mode&mode=' . $mode);
    }

    public function StartMowingNow(int $duration): bool
    {
        // Jetzt für $duration Minuten mähen, danach in die Ladestation (0 = Vorgabewert der Instanz)
        if ($duration == 0) {
            $duration = $this->ReadPropertyInteger('MowingTime');
        }
        if ($duration < 10 || $duration > 1440) {
            $this->log('StartMowingNow', 'Ungültige Dauer: ' . $duration);
            return false;
        }

        $start = date('H:i');
        return $this->executeSimpleCommand('mode&mode=job&start=' . $start . '&duration=' . $duration . '&after=home');
    }

    public function ScheduleJob(int $duration, string $modeAfter, string $start, string $stop): bool
    {
        // Entfernte Startpunkte werden nicht unterstützt
        if ($duration < 10 || $duration > 1440) {
            $this->log('ScheduleJob', 'Ungültige Dauer: ' . $duration);
            return false;
        }

        $modeAfter = strtolower(trim($modeAfter));
        if ($modeAfter == '') {
            $modeAfter = 'home';
        }
        if (!in_array($modeAfter, self::MODES, true)) {
            $this->log('ScheduleJob', 'Ungültiger Modus: ' . $modeAfter);
            return false;
        }

        // Start (leer = sofort)
        $start = trim($start);
        if ($start == '') {
            $start = date('H:i');
        }
        $startMinutes = $this->timeToMinutes($start);
        if ($startMinutes === false) {
            $this->log('ScheduleJob', 'Ungültige Startzeit: ' . $start);
            return false;
        }

        // Ende (leer = Start + Dauer + 5 Minuten Puffer)
        $stop = trim($stop);
        if ($stop == '') {
            $stop = $this->minutesToTime($startMinutes + $duration + 5);
        }
        $stopMinutes = $this->timeToMinutes($stop);
        if ($stopMinutes === false) {
            $this->log('ScheduleJob', 'Ungültige Endzeit: ' . $stop);
            return false;
        }
        if ($stopMinutes < $startMinutes) {
            $stopMinutes += 1440;
        }

        // Die Dauer darf nicht länger als das Zeitfenster sein
        if (($stopMinutes - $startMinutes) < $duration) {
            $this->log('ScheduleJob', 'Dauer ist länger als das Zeitfenster');
            return false;
        }

        return $this->executeSimpleCommand('mode&mode=job&start=' . $start . '&stop=' . $stop . '&duration=' . $duration . '&after=' . $modeAfter);
    }

    public function GetTimerFromMower()
    {
        // Liest alle Timer aus dem Rasenmäher und überträgt sie in den Wochenplan
        $data = $this->executeHTTPCommand('timer');
        if ($data === false || empty($data['successful']) || !isset($data['timer']) || !is_array($data['timer'])) {
            return false;
        }

        $weekPlanID = $this->getWeekPlanID();
        if ($weekPlanID === false) {
            return false;
        }

        // Wochenplan leeren: pro Tag nur "mähen beenden" um 00:00
        for ($day = 0; $day <= 6; $day++) {
            IPS_SetEventScheduleGroup($weekPlanID, $day, 0);
        }
        for ($day = 0; $day <= 6; $day++) {
            IPS_SetEventScheduleGroup($weekPlanID, $day, 1 << $day);
            IPS_SetEventScheduleGroupPoint($weekPlanID, $day, 1, 0, 0, 0, 1);
        }

        // aktive Timer eintragen
        $pointIDs = array_fill(0, 7, 2);
        foreach ($data['timer'] as $timer) {
            if (empty($timer['enabled']) || !isset($timer['start'], $timer['end'], $timer['weekdays'])) {
                continue;
            }
            $startMinutes = $this->timeToMinutes(substr((string) $timer['start'], 0, 5));
            $endMinutes = $this->timeToMinutes(substr((string) $timer['end'], 0, 5));
            if ($startMinutes === false || $endMinutes === false) {
                continue;
            }
            foreach (self::WEEKDAYS as $day => $weekday) {
                if (empty($timer['weekdays'][$weekday])) {
                    continue;
                }
                IPS_SetEventScheduleGroupPoint($weekPlanID, $day, $pointIDs[$day]++, intdiv($startMinutes, 60), $startMinutes % 60, 0, 2);
                IPS_SetEventScheduleGroupPoint($weekPlanID, $day, $pointIDs[$day]++, intdiv($endMinutes, 60), $endMinutes % 60, 0, 1);
            }
        }

        return $data;
    }

    public function SetTimerToMower(): bool
    {
        // Überträgt den Wochenplan in den Rasenmäher (bestehende Timer werden überschrieben!)
        $weekPlanID = $this->getWeekPlanID();
        if ($weekPlanID === false) {
            return false;
        }
        $weekPlan = IPS_GetEvent($weekPlanID);

        // Zeitfenster pro Tag aus dem Wochenplan ermitteln
        $windows = []; // key "start|end" => Liste der Wochentage
        foreach ($weekPlan['ScheduleGroups'] ?? [] as $group) {
            $days = [];
            for ($day = 0; $day <= 6; $day++) {
                if ((($group['Days'] ?? 0) & (1 << $day)) != 0) {
                    $days[] = $day;
                }
            }
            if (count($days) == 0) {
                continue;
            }

            $points = $group['Points'] ?? [];
            usort($points, function (array $a, array $b): int
            {
                return ($a['Start']['Hour'] * 60 + $a['Start']['Minute']) <=> ($b['Start']['Hour'] * 60 + $b['Start']['Minute']);
            });

            $start = null;
            foreach ($points as $point) {
                $time = sprintf('%02d:%02d', $point['Start']['Hour'], $point['Start']['Minute']);
                if ($start === null && $point['ActionID'] == 2) {
                    // mähen beginnen
                    $start = $time;
                } elseif ($start !== null && $point['ActionID'] == 1) {
                    // mähen beenden
                    $end = ($time == '00:00') ? '23:59' : $time;
                    $this->addTimerWindow($windows, $start, $end, $days);
                    $start = null;
                }
            }
            if ($start !== null) {
                // Mähen läuft über Mitternacht => an diesem Tag bis 23:59
                $this->addTimerWindow($windows, $start, '23:59', $days);
            }
        }

        if (count($windows) > self::TIMER_SLOTS) {
            $this->log('SetTimerToMower', 'Zu viele Zeitfenster im Wochenplan (' . count($windows) . '), max. ' . self::TIMER_SLOTS);
            return false;
        }

        // Robonect programmieren: belegte Slots, danach die restlichen Slots deaktivieren
        $success = true;
        $slot = 1;
        foreach ($windows as $key => $days) {
            [$start, $end] = explode('|', $key);
            $success = $this->sendTimer($slot++, true, $start, $end, $days) && $success;
        }
        while ($slot <= self::TIMER_SLOTS) {
            $success = $this->sendTimer($slot++, false, '08:00', '18:00', []) && $success;
        }

        return $success;
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'mowerModeInteractive':
                if ($Value == 0 && $this->SetMode('man')) {
                    $this->SetValue('mowerModeInteractive', 0);
                } elseif ($Value == 1 && $this->SetMode('auto')) {
                    $this->SetValue('mowerModeInteractive', 1);
                }
                break;

            case 'manualAction':
                switch ($Value) {
                    case 0: // jetzt mähen
                        if ($this->StartMowingNow(0)) {
                            $this->SetValue('manualAction', 0);
                        }
                        break;

                    case 1: // Pause / weitermachen
                        // Zunächst sicherstellen, dass "man. angehalten" aktuell ist
                        $this->Update();
                        if (!$this->GetValue('mowerStopped')) {
                            if ($this->Stop()) {
                                $this->SetValue('manualAction', 1);
                            }
                        } elseif ($this->Start()) {
                            $this->SetValue('manualAction', -1);
                        }
                        $this->Update();
                        break;

                    case 2: // mähen beenden
                        if ($this->SetMode('home')) {
                            $this->SetValue('manualAction', 2);
                        }
                        $this->Update();
                        break;
                }
                break;

            case 'timerTransmitAction':
                if ($Value == 0) {
                    $this->GetTimerFromMower();
                } elseif ($Value == 1) {
                    $this->SetTimerToMower();
                }
                break;

            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    public function ProcessMQTT(string $Topic, string $Payload): bool
    {
        // Wird von der Instanz "Robonect MQTT" aufgerufen.
        // $Topic ist relativ zum eingestellten Robonect-Topic, z. B. "/mower/status"
        $topic = strtolower('/' . ltrim(trim($Topic), '/'));
        $payload = $this->cleanPayload($Payload);
        $this->log('MQTT', $topic . ' = ' . $payload);

        if (!isset(self::MQTT_TOPICS[$topic])) {
            $this->log('MQTT', 'Unbekanntes Topic: ' . $topic);
            return false;
        }

        $ident = self::MQTT_TOPICS[$topic];
        $this->updateIdent($ident, $payload);
        if ($ident != 'mowerMqttStatus') {
            $this->SetValue('mowerMqttStatus', 1); // Daten kommen an => online
        }
        return true;
    }

    //=== Interne Funktionen =========================================================================

    private function processStatus(array $data): void
    {
        // Identifikation
        if (isset($data['name'])) {
            $this->updateIdent('mowerName', $data['name']);
        }
        if (isset($data['id'])) {
            $this->updateIdent('mowerSerial', $data['id']);
        }

        // Netzwerk
        if (isset($data['wlan']['signal'])) {
            $this->updateIdent('mowerWlanStatus', $data['wlan']['signal']);
        }

        // Status
        $status = $data['status'] ?? [];
        if (isset($status['mode'])) {
            $this->updateIdent('mowerMode', $status['mode']);
        }
        if (isset($status['status'])) {
            $this->updateIdent('mowerStatus', $status['status']);
        }
        if (isset($status['stopped'])) {
            $this->updateIdent('mowerStopped', $status['stopped']);
        }
        if (isset($status['duration'])) {
            $this->updateIdent('mowerStatusSinceDurationSec', $status['duration']);
        }
        if (isset($status['distance'])) {
            $this->updateIdent('mowerDistance', $status['distance']);
        }
        if (isset($status['battery'])) {
            $this->updateIdent('mowerBatterySoc', $status['battery']);
        }
        if (isset($status['hours'])) {
            $this->updateIdent('mowerHours', $status['hours']);
        }

        // Zustand
        if (isset($data['health']['temperature'])) {
            $this->updateIdent('mowerTemperature', $data['health']['temperature']);
        }
        if (isset($data['health']['humidity'])) {
            $this->updateIdent('mowerHumidity', $data['health']['humidity']);
        }
        if (isset($data['blades']['quality'])) {
            $this->updateIdent('mowerBladesQuality', $data['blades']['quality']);
        }
        if (isset($data['blades']['hours'])) {
            $this->updateIdent('mowerBladesOperatingHours', $data['blades']['hours']);
        }
        if (isset($data['blades']['days'])) {
            $this->updateIdent('mowerBladesAge', $data['blades']['days']);
        }

        // Aktueller Fehler (nur vorhanden, wenn der Mäher einen Fehler meldet)
        if (isset($data['error']['error_message']) && isset($status['status']) && $status['status'] == 7) {
            $this->updateIdent('mowerCurrentError', $data['error']['error_message']);
        } elseif (isset($status['status']) && $status['status'] != 7) {
            $this->updateIdent('mowerCurrentError', '');
        }

        // Timer
        if (isset($data['timer']['status'])) {
            $this->updateIdent('mowerTimerStatus', $data['timer']['status']);
        }
        $this->updateIdent('mowerNextTimerstart', $data['timer']['next']['unix'] ?? 0);

        // Uhr
        if (isset($data['clock']['unix'])) {
            $this->updateIdent('mowerUnixTimestamp', $data['clock']['unix']);
        }
    }

    private function executeSimpleCommand(string $command): bool
    {
        $data = $this->executeHTTPCommand($command);
        return ($data !== false) && !empty($data['successful']);
    }

    private function executeHTTPCommand(string $command, bool $optional = false): array|false
    {
        $IPAddress = trim($this->ReadPropertyString('IPAddress'));
        $Username = trim($this->ReadPropertyString('Username'));
        $Password = trim($this->ReadPropertyString('Password'));

        // IP-Adresse prüfen
        if ($IPAddress == '' || $IPAddress == '0.0.0.0') {
            $this->setStatusIfChanged(self::STATUS_CONFIG_MISSING);
            return false;
        }
        if (filter_var($IPAddress, FILTER_VALIDATE_IP) === false) {
            $this->setStatusIfChanged(self::STATUS_IP_INVALID);
            return false;
        }
        if ($command == '') {
            return false;
        }

        $URL = 'http://' . $IPAddress . '/json?cmd=' . $command;
        $this->log('HTTP', 'Anfrage: cmd=' . $command);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $Username . ':' . $Password,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15
        ]);
        $response = curl_exec($ch);
        $curlError = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError != 0 || $response === false) {
            $this->log('HTTP', 'Keine Antwort (cURL-Fehler ' . $curlError . ')');
            $this->setOnline(false);
            $this->setStatusIfChanged(self::STATUS_NO_DEVICE);
            return false;
        }
        if ($httpCode == 401) {
            $this->log('HTTP', 'Anmeldung fehlgeschlagen (HTTP 401)');
            $this->setOnline(false);
            $this->setStatusIfChanged(self::STATUS_LOGIN_FAILED);
            return false;
        }

        $this->log('HTTP', 'Antwort (HTTP ' . $httpCode . '): ' . $response);

        $data = $this->decodeJSON((string) $response);
        if (!is_array($data)) {
            $this->log('HTTP', 'Antwort auf cmd=' . $command . ' ist kein gültiges JSON: ' . json_last_error_msg());
            if (!$optional) {
                $this->setOnline(false);
                $this->setStatusIfChanged(self::STATUS_NO_JSON);
            }
            return false;
        }
        if (!array_key_exists('successful', $data)) {
            if (!$optional) {
                $this->setOnline(false);
                $this->setStatusIfChanged(self::STATUS_NO_ROBONECT);
            }
            return false;
        }

        $this->setOnline(true);
        $this->setStatusIfChanged(self::STATUS_OK);
        return $data;
    }

    private function decodeJSON(string $response): ?array
    {
        $data = json_decode($response, true);
        if (is_array($data)) {
            return $data;
        }
        // Manche Firmware-Stände liefern Umlaute nicht als UTF-8 (z. B. in Fehlermeldungen)
        if (!mb_check_encoding($response, 'UTF-8')) {
            $data = json_decode(mb_convert_encoding($response, 'UTF-8', 'Windows-1252'), true);
            if (is_array($data)) {
                return $data;
            }
        }
        $data = json_decode($response, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        return is_array($data) ? $data : null;
    }

    private function updateIdent(string $ident, mixed $payload): void
    {
        try {
            switch ($ident) {
                case 'mowerMode':
                    $this->setTypedValue('mowerMode', $payload);
                    // automatisch (0) = Timer, sonst manuell
                    $this->SetValue('mowerModeInteractive', ((int) $payload == 0) ? 1 : 0);
                    break;

                case 'mowerStopped':
                    $stopped = $this->toBool($payload);
                    $this->SetValue('mowerStopped', $stopped);
                    if ($stopped) {
                        $this->SetValue('manualAction', 1); // "Pause" hervorheben
                    } elseif ($this->GetValue('manualAction') == 1) {
                        $this->SetValue('manualAction', -1); // keine Aktion hervorheben
                    }
                    break;

                case 'mowerStatusSinceDurationSec':
                    $this->setStatusSince((int) filter_var($payload, FILTER_SANITIZE_NUMBER_INT));
                    break;

                case 'mowerStatusSinceDurationMin':
                    $this->setStatusSince((int) filter_var($payload, FILTER_SANITIZE_NUMBER_INT) * 60);
                    break;

                case 'mowerVoltageBattery':
                case 'mowerVoltageInternal':
                case 'mowerVoltageExternal':
                    $voltage = (float) str_replace(',', '.', (string) $payload);
                    if ($voltage > 100) {
                        $voltage = $voltage / 1000; // Millivolt => Volt
                    }
                    $this->SetValue($ident, $voltage);
                    break;

                case 'mowerWlanStatus':
                    $dBm = abs((int) filter_var($payload, FILTER_SANITIZE_NUMBER_INT));
                    $intensity = ($dBm >= 95) ? 0 : (int) min(max(round(((95 - $dBm) / 60) * 100), 0), 100);
                    $this->SetValue('mowerWlanStatus', $intensity);
                    break;

                case 'mowerMqttStatus':
                    $this->SetValue('mowerMqttStatus', (strtolower(trim((string) $payload)) == 'online') ? 1 : 0);
                    break;

                case 'mowerNextTimerstart':
                case 'mowerUnixTimestamp':
                    // Das Robonect-Modul liefert die lokale Zeit als Unix-Zeitstempel
                    $timestamp = (int) $payload;
                    if ($timestamp != 0) {
                        $timezone = new DateTimeZone(date_default_timezone_get());
                        $timestamp -= $timezone->getOffset(new DateTime('now', $timezone));
                    }
                    $this->SetValue($ident, $timestamp);
                    break;

                case 'mowerGpsLatitudeRaw':
                case 'mowerGpsLongitudeRaw':
                    $this->SetValue($ident, (string) $payload);
                    $decimal = $this->convertGpsToDecimal((string) $payload);
                    if ($decimal !== false) {
                        $this->SetValue(($ident == 'mowerGpsLatitudeRaw') ? 'mowerGpsLatitude' : 'mowerGpsLongitude', $decimal);
                    }
                    break;

                default:
                    $this->setTypedValue($ident, $payload);
                    break;
            }
        } catch (Throwable $e) {
            $this->log('UpdateIdent', 'Aktualisierung von ' . $ident . ' mit "' . print_r($payload, true) . '" fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private function setTypedValue(string $ident, mixed $payload): void
    {
        // Wert passend zum Variablentyp umwandeln (MQTT liefert immer Text)
        $variableID = @$this->GetIDForIdent($ident);
        if ($variableID === false || $variableID == 0) {
            return;
        }
        switch (IPS_GetVariable($variableID)['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $value = $this->toBool($payload);
                break;
            case VARIABLETYPE_INTEGER:
                $value = (int) round((float) str_replace(',', '.', (string) $payload));
                break;
            case VARIABLETYPE_FLOAT:
                $value = (float) str_replace(',', '.', (string) $payload);
                break;
            default:
                $value = (string) $payload;
                break;
        }
        $this->SetValue($ident, $value);
    }

    private function setStatusSince(int $seconds): void
    {
        $this->SetValue('mowerStatusSince', time() - $seconds);
        $this->SetValue('statusSinceDescriptive', $this->formatDuration($seconds));
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max($seconds, 0);
        $days = intdiv($seconds, 86400);
        if ($days > 0) {
            return $days . (($days == 1) ? ' Tag' : ' Tage');
        }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        if ($hours > 0) {
            return $hours . (($hours == 1) ? ' Stunde ' : ' Stunden ') . $minutes . (($minutes == 1) ? ' Minute' : ' Minuten');
        }
        return $minutes . (($minutes == 1) ? ' Minute' : ' Minuten');
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes', 'ja'], true);
    }

    private function cleanPayload(string $payload): string
    {
        // Reste doppelter UTF-8-Kodierung korrigieren
        $payload = str_replace(
            ['Ã¤', 'Ã¶', 'Ã¼', 'Ã„', 'Ã–', 'Ãœ', 'ÃŸ', 'Â°'],
            ['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß', '°'],
            $payload
        );
        // Steuerzeichen entfernen
        $payload = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $payload));
        // Wenn ein Prozentwert in Klammern vorhanden ist, exakt bis dort übernehmen
        if (preg_match('/^(.+\(\d{1,3}\s?%\))/u', $payload, $matches)) {
            $payload = $matches[1];
        }
        return $payload;
    }

    private function buildErrorListHTML(array $errors): string
    {
        if (count($errors) == 0) {
            return '<div style="padding:4px">Keine Fehlermeldungen</div>';
        }

        $html = '<table style="width:100%; border-collapse:collapse">';
        $html .= '<thead><tr>';
        foreach (['Nr.', 'Datum', 'Uhrzeit', 'Fehlercode', 'Beschreibung'] as $caption) {
            $html .= '<th style="text-align:left; padding:4px">' . $caption . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($errors as $index => $error) {
            $background = (($index % 2) == 0) ? 'rgba(128,128,128,0.15)' : 'transparent';
            $html .= '<tr style="background-color:' . $background . '">';
            $html .= '<td style="padding:4px">' . ($index + 1) . '</td>';
            $html .= '<td style="padding:4px">' . htmlspecialchars((string) ($error['date'] ?? '')) . '</td>';
            $html .= '<td style="padding:4px">' . htmlspecialchars((string) ($error['time'] ?? '')) . '</td>';
            $html .= '<td style="padding:4px">' . htmlspecialchars((string) ($error['error_code'] ?? '')) . '</td>';
            $html .= '<td style="padding:4px">' . htmlspecialchars((string) ($error['error_message'] ?? '')) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    private function addTimerWindow(array &$windows, string $start, string $end, array $days): void
    {
        $key = $start . '|' . $end;
        $windows[$key] = array_values(array_unique(array_merge($windows[$key] ?? [], $days)));
    }

    private function sendTimer(int $slot, bool $enabled, string $start, string $end, array $days): bool
    {
        $command = 'timer&timer=' . $slot . '&start=' . $start . '&end=' . $end;
        foreach (self::WEEKDAYS as $day => $weekday) {
            $command .= '&' . $weekday . '=' . (in_array($day, $days, true) ? '1' : '0');
        }
        $command .= '&enable=' . ($enabled ? '1' : '0');
        return $this->executeSimpleCommand($command);
    }

    private function getWeekPlanID(): int|false
    {
        $timerPlanActiveID = @$this->GetIDForIdent('TimerPlanActive');
        if ($timerPlanActiveID === false || $timerPlanActiveID == 0) {
            return false;
        }
        $weekPlanID = @IPS_GetObjectIDByIdent('TimerWeekPlan' . $this->InstanceID, $timerPlanActiveID);
        return ($weekPlanID === false || $weekPlanID == 0) ? false : $weekPlanID;
    }

    private function timeToMinutes(string $time): int|false
    {
        if (!preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', trim($time), $matches)) {
            return false;
        }
        return intval($matches[1]) * 60 + intval($matches[2]);
    }

    private function minutesToTime(int $minutes): string
    {
        $minutes = $minutes % 1440;
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function convertGpsToDecimal(string $pos): float|false
    {
        // Format des Robonect-Moduls z. B. 51°12.3456N
        $pos = str_replace(['Â°', '°'], '°', trim($pos));
        $pos = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $pos));
        if (!preg_match('/^([0-9]+)°([0-9]+(?:\.[0-9]+)?)\s*([NSEW])$/u', $pos, $matches)) {
            return false;
        }
        $decimal = (float) $matches[1] + ((float) $matches[2] / 60);
        if ($matches[3] === 'S' || $matches[3] === 'W') {
            $decimal *= -1;
        }
        return $decimal;
    }

    private function updateTimerInterval(): void
    {
        if ($this->ReadPropertyBoolean('HTTPUpdateTimer') && $this->ReadPropertyInteger('UpdateTimer') >= 10) {
            $this->SetTimerInterval('ROBONECT_UpdateTimer', $this->ReadPropertyInteger('UpdateTimer') * 1000);
        } else {
            $this->SetTimerInterval('ROBONECT_UpdateTimer', 0);
        }
    }

    private function setStatusIfChanged(int $status): void
    {
        if ($this->GetStatus() != $status) {
            $this->SetStatus($status);
        }
    }

    private function setOnline(bool $online): void
    {
        if ($this->GetValue('mowerOnline') !== $online) {
            $this->SetValue('mowerOnline', $online);
        }
    }

    private function log(string $topic, string $text): void
    {
        if ($this->ReadPropertyBoolean('DebugLog')) {
            $this->SendDebug($topic, $text, 0);
        }
    }

    //=== Variablen und Darstellungen ================================================================

    private function enumeration(array $options): array
    {
        $list = [];
        foreach ($options as $value => $caption) {
            $list[] = ['Value' => $value, 'Caption' => $caption, 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($list)];
    }

    private function booleanEnumeration(string $false, string $true): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode([
                ['Value' => false, 'Caption' => $false, 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => true, 'Caption' => $true, 'IconActive' => false, 'IconValue' => '', 'Color' => -1]
            ])
        ];
    }

    private function valuePresentation(string $suffix, int $digits = 0): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => $suffix, 'DIGITS' => $digits];
    }

    private function registerVariables(): void
    {
        $janein = $this->booleanEnumeration('Nein', 'Ja');

        //--- Grunddaten ---------------------------------------------------------------------------
        $this->RegisterVariableString('mowerName', 'Name', '', 0);
        $this->RegisterVariableString('mowerSerial', 'Seriennummer', '', 1);
        $this->RegisterVariableBoolean('mowerOnline', 'Robonect erreichbar', $janein, 2);
        $this->RegisterVariableInteger('lastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp', 3);

        //--- Bedienung ----------------------------------------------------------------------------
        $this->RegisterVariableInteger('mowerModeInteractive', 'Modus', $this->enumeration([0 => 'manuell', 1 => 'Timer']), 20);
        $this->EnableAction('mowerModeInteractive');
        $this->RegisterVariableInteger('manualAction', 'Aktion', $this->enumeration([0 => 'jetzt mähen', 1 => 'Pause', 2 => 'mähen beenden']), 21);
        $this->EnableAction('manualAction');

        //--- Status -------------------------------------------------------------------------------
        $this->RegisterVariableInteger('mowerMode', 'Modus', $this->enumeration([0 => 'automatisch', 1 => 'manuell', 2 => 'Zuhause', 3 => 'Demo']), 30);
        $this->RegisterVariableInteger('mowerStatus', 'Status', $this->enumeration([
            0  => 'Status wird ermittelt',
            1  => 'geparkt',
            2  => 'mäht',
            3  => 'sucht die Ladestation',
            4  => 'lädt',
            5  => 'sucht',
            7  => 'Fehlerstatus',
            8  => 'Schleifensignal verloren',
            16 => 'abgeschaltet',
            17 => 'schläft'
        ]), 31);
        $this->RegisterVariableString('mowerStatusPlain', 'Status (Klartext)', '', 32);
        $this->RegisterVariableInteger('mowerSubstatus', 'Substatus', '', 33);
        $this->RegisterVariableString('mowerSubstatusPlain', 'Substatus (Klartext)', '', 34);
        $this->RegisterVariableBoolean('mowerStopped', 'man. angehalten', $janein, 35);
        $this->RegisterVariableInteger('mowerStatusSince', 'Status seit', '~UnixTimestamp', 36);
        $this->RegisterVariableString('statusSinceDescriptive', 'Status seit', '', 37);
        $this->RegisterVariableInteger('mowerDistance', 'Entfernung zur Ladestation', $this->valuePresentation(' m'), 38);

        //--- Zustand ------------------------------------------------------------------------------
        $this->RegisterVariableInteger('mowerBatterySoc', 'Akkustand', '~Battery.100', 50);
        $this->RegisterVariableFloat('mowerVoltageBattery', 'Akku-Spannung', $this->valuePresentation(' V', 2), 51);
        $this->RegisterVariableFloat('mowerVoltageInternal', 'Interne Spannung', $this->valuePresentation(' V', 2), 52);
        $this->RegisterVariableFloat('mowerVoltageExternal', 'Externe Spannung', $this->valuePresentation(' V', 2), 53);
        $this->RegisterVariableInteger('mowerHours', 'Arbeitsstunden', $this->valuePresentation(' h', 0), 54);
        $this->RegisterVariableInteger('mowerWlanStatus', 'WLAN Signalstärke', '~Intensity.100', 55);
        $this->RegisterVariableInteger('mowerMqttStatus', 'MQTT Status', $this->enumeration([0 => 'offline', 1 => 'online']), 56);
        $this->RegisterVariableFloat('mowerTemperature', 'Temperatur im Rasenmäher', '~Temperature', 57);
        $this->RegisterVariableInteger('mowerHumidity', 'Feuchtigkeit im Rasenmäher', '~Humidity', 58);
        $this->RegisterVariableInteger('mowerBladesQuality', 'Qualität der Messer', '~Intensity.100', 59);
        $this->RegisterVariableInteger('mowerBladesOperatingHours', 'Betriebsstunden der Messer', $this->valuePresentation(' h', 0), 60);
        $this->RegisterVariableInteger('mowerBladesAge', 'Alter der Messer', $this->valuePresentation(' d', 0), 61);

        //--- Fehler -------------------------------------------------------------------------------
        $this->RegisterVariableString('mowerCurrentError', 'Aktueller Fehler', '', 69);
        $this->RegisterVariableInteger('mowerErrorCount', 'Anzahl Fehlermeldungen', '', 70);
        $this->RegisterVariableString('mowerErrorList', 'Fehlermeldungen', '~HTMLBox', 71);

        //--- GPS ----------------------------------------------------------------------------------
        $this->RegisterVariableString('mowerGpsLatitudeRaw', 'GPS Latitude (raw)', '', 80);
        $this->RegisterVariableString('mowerGpsLongitudeRaw', 'GPS Longitude (raw)', '', 81);
        $this->RegisterVariableFloat('mowerGpsLatitude', 'GPS Latitude', $this->valuePresentation('°', 6), 82);
        $this->RegisterVariableFloat('mowerGpsLongitude', 'GPS Longitude', $this->valuePresentation('°', 6), 83);

        //--- Timer --------------------------------------------------------------------------------
        $this->RegisterVariableInteger('mowerTimerStatus', 'Timer Status', $this->enumeration([0 => 'deaktiviert', 1 => 'aktiv', 2 => 'Standby']), 90);
        $this->RegisterVariableBoolean('TimerPlanActive', 'Timer-Plan aktiv', $janein, 91);
        $this->createWeekPlan();
        $this->RegisterVariableInteger('mowerNextTimerstart', 'nächster Timerstart', '~UnixTimestamp', 92);
        $this->RegisterVariableInteger('timerTransmitAction', 'Timer lesen/schreiben', $this->enumeration([0 => 'vom Robonect lesen', 1 => 'an Robonect übertragen']), 93);
        $this->EnableAction('timerTransmitAction');

        //--- Uhr ----------------------------------------------------------------------------------
        $this->RegisterVariableInteger('mowerUnixTimestamp', 'Interner Unix Zeitstempel', '~UnixTimestamp', 110);
    }

    private function createWeekPlan(): void
    {
        $timerPlanActiveID = $this->GetIDForIdent('TimerPlanActive');
        if (@IPS_GetObjectIDByIdent('TimerWeekPlan' . $this->InstanceID, $timerPlanActiveID) !== false) {
            return;
        }

        $weekPlanID = IPS_CreateEvent(EVENTTYPE_SCHEDULE);
        IPS_SetParent($weekPlanID, $timerPlanActiveID);
        IPS_SetName($weekPlanID, 'Timer Wochen Plan');
        IPS_SetIdent($weekPlanID, 'TimerWeekPlan' . $this->InstanceID);

        IPS_SetEventScheduleAction($weekPlanID, 1, 'mähen beenden', 0x000000, "SetValueBoolean(\$_IPS['TARGET'], false);");
        IPS_SetEventScheduleAction($weekPlanID, 2, 'mähen beginnen', 0x00FF00, "SetValueBoolean(\$_IPS['TARGET'], true);");

        for ($day = 0; $day <= 6; $day++) {
            IPS_SetEventScheduleGroup($weekPlanID, $day, 1 << $day);
            IPS_SetEventScheduleGroupPoint($weekPlanID, $day, 1, 0, 0, 0, 1);
        }

        IPS_SetEventActive($weekPlanID, true);
    }
}
