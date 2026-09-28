### IP-Symcon Modul zur Einbindung eines mit einem Robonect-WLAN-Modul erweiterten Mähroboters

<img src="./imgs/Robonect%20Logo.png">

PHP-Modul zur Einbindung von [Robonect](https://www.robonect-shop.de)-WLAN-Modulen zur Steuerung eines Mähroboters.

Nutzung auf eigene Gefahr ohne Gewähr. Das Modul kann jederzeit überarbeitet werden, so dass Anpassungen für eine weitere Nutzung notwendig sein können. Bei der Weiterentwicklung wird möglichst auf Kompatibilität geachtet.

**Bei der Installation einer neuen Version sollte man in der [Versionshistorie](#5-versionshistorie) schauen, ob man evtl. manuell tätig werden muss!**

Das Modul ist ein Hobby. Wer sich mit einer kleinen Spende für dessen Entwicklung (ohne Recht auf irgendwelche Ansprüche) bedanken möchte, kann dies gerne via Paypal-Spende machen:

<a href="https://www.paypal.com/donate/?hosted_button_id=LAZR2DLZ2E6SU"><img src="./imgs/SpendenMitPaypal.png"></a>

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Systemanforderungen](#2-systemanforderungen)
3. [Installation](#3-installation)
4. [Module](#4-module)
5. [Versionshistorie](#5-versionshistorie)

## 1. Funktionsumfang

Das Modul ist für die Handhabung von lokalen Mährobotern via eingebauten [Robonect](https://www.robonect-shop.de)-WLAN-Modulen gedacht. Das Modul soll nicht dazu dienen, komplette Konfigurationen der Mähroboter über das Robonect-Modul zu ermöglichen bzw. diese via IP-Symcon vorzunehmen. Dies sollte weiterhin über die Robonect-Modul-HTML-Seite gemacht werden.

Das Ziel des Moduls ist eine bessere und simple Einbindung in die IP-Symcon Haussteuerung:

- Abfrage von Status, Akku, Spannungen, Klima, Messer, WLAN, GPS, Timer und Fehlerspeicher per HTTP (lokal, ohne Cloud)
- optional Empfang der Daten in Echtzeit per MQTT über den in IP-Symcon eingebauten MQTT-Server
- Steuerung (Modus, jetzt mähen, Pause, nach Hause, Aufträge planen) über Skript-Funktionen und direkt in der Visualisierung
- Übertragung der Mäh-Timer zwischen Robonect und einem Symcon Wochenplan in beide Richtungen

## 2. Systemanforderungen

- IP-Symcon ab Version 8.0 (getestet mit IP-Symcon 9.0)
- Installiertes Robonect Modul im Mähroboter (getestet mit einem Robonect-HX Modul in einem Husqvarna Automower 105)
- Für MQTT (optional): ein MQTT-Server in IP-Symcon

## 3. Installation

Vor der Installation des Moduls in IP-Symcon muss das Robonect-Modul (die Hardware) im Rasenmäher vollständig installiert und eingerichtet sein. Da dieses Modul lokal auf das Robonect-Modul im Rasenmäher zugreift, muss im lokalen WLAN dem Robonect-Hardwaremodul eine statische IP zugewiesen sein.

Das Modul wird über den Module Store bzw. im Objektbaum unter Kern Instanzen → Modules mit der URL `https://github.com/IPSCoyote/Robonect` hinzugefügt.

Als nächstes wird eine Instanz des Robonect-Robonect HX Moduls angelegt

<p align="center">
  <img width="322" height="335" src="./imgs/Instanz%20anlegen.png">
</p>

welches anschließend konfiguriert werden muss.

### 3.1 Konfiguration

<p align="center">
  <img width="800" height="462" src="./imgs/Konfiguration.png">
</p>

#### Login Daten
Die statische IP-Adresse des Robonect-Hardware-Moduls sowie der Admin Benutzer und sein Passwort. Dies wird benötigt, um einerseits Daten abzurufen und andererseits auch den Mähroboter steuern zu können.

#### Automatische Updates
Hier kann man die automatischen Updates des Moduls sowie das zugehörige Zeitintervall (10 - 3600 Sekunden) einstellen. Sollte MQTT genutzt werden, kann das Update-Intervall höher ausfallen. Aber leider liefert MQTT nicht alle Daten, weshalb man nicht auf die automatischen Updates komplett verzichten sollte, sondern das Intervall höher einstellt.

Mit **Fehlerliste bei jedem Update mit abrufen** wird bei jedem Update auch der Fehlerspeicher gelesen. Ein eigenes Ereignis mit `ROBONECT_UpdateErrorList` ist dann nicht mehr nötig.

#### MQTT (optional)
Über MQTT sendet das Robonect-Modul Änderungen sofort an IP-Symcon. Dafür wird der in IP-Symcon eingebaute MQTT-Server genutzt:

1. In der Robonect-Instanz auf **Gateway ändern** klicken und einen **MQTT Server** auswählen bzw. neu anlegen (IP-Symcon legt dabei auch den zugehörigen Server Socket an, Standard-Port 1883).
2. Im MQTT Server ggf. Benutzername und Passwort vergeben.
3. Im Robonect-Webinterface unter *Kommunikation → MQTT-Client* die IP-Adresse von IP-Symcon als Broker, den Port und ggf. Benutzer/Passwort eintragen und ein Topic festlegen (z. B. `Robonect`).
4. Dasselbe Topic in der Robonect-Instanz unter **MQTT Topic** eintragen. Das Modul reagiert nur auf dieses Topic.

Wird MQTT nicht genutzt, bleibt das Feld leer. IP-Symcon zeigt dann in der Instanz den Hinweis an, dass keine übergeordnete Instanz vorhanden ist - das Modul funktioniert per HTTP trotzdem vollständig.

#### Vorgabewerte
Hier können ggf. notwendige Defaultwerte festgelegt werden. So kann man das Mähen des Mähroboters mit einem Befehl starten, der eine Mähdauer erwartet (oder z. B. über die Visualisierung direkt). Die dann benötigte Mähzeit wird hier festgelegt.

#### Debugging Tools
Für die Fehlersuche kann das Debug-Log aktiviert werden (Ausgabe im Debug-Fenster der Instanz). Ansonsten sollte es aber deaktiviert bleiben.

#### Aktionen
Unterhalb der Konfiguration stehen Schaltflächen zum Testen der Verbindung, zum Abrufen der Fehlerliste, zum Lesen/Schreiben der Timer und zum Löschen des Fehlerspeichers zur Verfügung.

#### Instanzstatus

| Code | Bedeutung |
| :---: | :--- |
| 102 | Robonect gefunden |
| 200 | Konfiguration fehlt |
| 201 | IP Adresse ungültig |
| 202 | Kein Gerät antwortet an der IP Adresse |
| 203 | Kein JSON wird an der IP Adresse geliefert |
| 204 | Kein Robonect an IP Adresse erreichbar |
| 205 | Anmeldung fehlgeschlagen (Benutzer/Passwort prüfen) |

## 4. Module

### 4.1 Robonect HX Wifi Modul

#### 4.1.1. Status Variablen

Im Folgenden werden die verfügbaren Statusvariablen mit ihren Eigenschaften, Werten und Zugriffsmöglichkeiten aufgelistet. Wenn Funktionen verfügbar sind, sind diese im Anschluss aufgelistet.

- RO = **R**ead **O**nly<br>
- RW = **R**ead **W**rite enabled<br>
- WF = **W**eb**f**ront change enabled (die Variablen können zwecks Anzeige natürlich alle in die Visualisierung eingebunden werden)
- (MQTT) = Wert wird nur per MQTT geliefert

|Name                         | Type | Optionen | Werte | Funktionen |
|:-----------------------------| :---: |  :---:  | :---  | :---: |
|`Name`                       | String | RO | Name des Rasenmähers (lt. Einstellung) | |
|`Seriennummer`               | String | RO | Seriennummer des Rasenmähers | |
|`Robonect erreichbar`        | Boolean | RO | Antwortet das Robonect-Modul per HTTP? | |
|`Letzte Aktualisierung`      | Integer | RO | Zeitpunkt des letzten erfolgreichen Updates | |
|`Modus`                      | Integer | WF | Bedienbarer Modus in der Visualisierung! (manuell oder Timer) | |
|`Aktion`                     | Integer | WF | Bedienbare Aktion in der Visualisierung! ('jetzt mähen', 'Pause', 'mähen beenden') | |
|`Modus`                      | Integer | RW | Aktueller Modus des Rasenmähers | Kann über diverse Methoden (ggf. indirekt) gesetzt werden |
|`Status`                     | Integer | RO | aktueller Status des Rasenmähers | |
|`Status (Klartext)`          | String | RO | aktueller Status des Rasenmähers, geliefert vom Rasenmäher! (MQTT) | |
|`Substatus`                  | Integer | RO | aktueller Substatus des Rasenmähers (MQTT) | |
|`Substatus (Klartext)`       | String | RO | aktueller Substatus des Rasenmähers, geliefert vom Rasenmäher! (MQTT) | |
|`man. angehalten`            | Boolean | RW | Angabe, ob der Rasenmäher manuell angehalten wurde | Kann u. a. über [Start](#start-int-instanz-) / [Stop](#stop-int-instanz-) beeinflusst werden |
|`Status seit`                | Integer | RO | Seit wann gilt der aktuelle Status als TimeStamp.<br>Während des Mähens ist der Wert verlässlich. Ansonsten kann er durch das "Schlafen" des Mähers falsch sein! | |
|`Status seit`                | String | RO | Seit wann gilt der aktuelle Status als Text (z. B. "2 Stunden 5 Minuten").<br>Während des Mähens ist der Wert verlässlich. Ansonsten kann er durch das "Schlafen" des Mähers falsch sein! | |
|`Entfernung zur Ladestation` | Integer | RO | Entfernung in Metern (sofern vom Mäher geliefert) | |
|`Akkustand`                  | Integer | RO | SoC der Batterie | |
|`Akku-Spannung`              | Float | RO | Spannung der Batterie | |
|`Interne Spannung`           | Float | RO | Interne Spannung | |
|`Externe Spannung`           | Float | RO | Externe Spannung | |
|`Arbeitsstunden`             | Integer | RO | Arbeitsstunden des Rasenmähers | |
|`WLAN Signalstärke`          | Integer | RO | Signalstärke des WLAN in % | |
|`MQTT Status`                | Integer | RO | online/offline (MQTT) | |
|`Temperatur im Rasenmäher`   | Float | RO | Temperatur im Innern des Rasenmähers | |
|`Feuchtigkeit im Rasenmäher` | Integer | RO | Feuchtigkeit im Innern des Rasenmähers | |
|`Qualität der Messer`        | Integer | RO | Zustand der Messer in % (sofern vom Mäher geliefert) | |
|`Betriebsstunden der Messer` | Integer | RO | Betriebsstunden der Messer (sofern vom Mäher geliefert) | |
|`Alter der Messer`           | Integer | RO | Alter der Messer in Tagen (sofern vom Mäher geliefert) | |
|`Aktueller Fehler`           | String | RO | Fehlermeldung, solange der Mäher im Fehlerstatus ist, sonst leer | |
|`Anzahl Fehlermeldungen`     | Integer | RO | Anzahl der Fehlermeldungen im Fehlerspeicher.<br>Leider etwas unzuverlässig, da das Robonect-Modul nicht verlässlich die Daten liefert. | Kann über [UpdateErrorList](#updateerrorlist-int-instanz-) aktualisiert werden. |
|`Fehlermeldungen`            | String | RO | Fehlermeldungen im Fehlerspeicher als HTML-Tabelle.<br>Leider etwas unzuverlässig, da das Robonect-Modul nicht verlässlich die Daten liefert. | Kann über [UpdateErrorList](#updateerrorlist-int-instanz-) aktualisiert werden. |
|`GPS Latitude / Longitude (raw)` | String | RO | GPS-Position, wie sie das Robonect-Modul liefert (nur mit GPS-Modul) | |
|`GPS Latitude / Longitude`   | Float | RO | GPS-Position in Dezimalgrad (nur mit GPS-Modul) | |
|`Timer Status`               | Integer | RW | Status des Timers | Kann über die Moduswahl beeinflusst werden. |
|`Timer-Plan aktiv`           | Boolean | RO | Ist ein interner Timer des Rasenmähers aktiv?<br>Unter der Variable befindet sich ein Wochenplan, der die Timer-Definitionen im Rasenmäher darstellt. Er kann in beide Richtungen übertragen werden!<br>ACHTUNG: Der Wochenplan muss(!) "Timer Wochen Plan" heißen. Ansonsten würde ein neuer Plan angelegt! | Der unterliegende Wochenplan kann über die Funktion [GetTimerFromMower](#gettimerfrommower-int-instanz-) aus dem Rasenmäher aktualisiert werden.<br>Mit der Funktion [SetTimerToMower](#settimertomower-int-instanz-) kann er in den Rasenmäher übertragen werden. |
|`nächster Timerstart`        | Integer | RO | UNIX Zeitstempel des nächsten Timerstarts lt. Rasenmäher | |
|`Timer lesen/schreiben`      | Integer | WF | Timer in der Visualisierung vom Robonect lesen bzw. an den Robonect übertragen | |
|`Interner Unix Zeitstempel`  | Integer | RO | Zeitstempel der internen Uhr des Rasenmähers | |

#### 4.1.2. Funktionen

Das Präfix der Funktionen ist `ROBONECT`. Da PHP bei Funktionsnamen nicht auf Groß-/Kleinschreibung achtet, funktionieren bestehende Skripte mit `Robonect_...` unverändert weiter.

#### Update( int $Instanz )
Mit dieser Funktion können die Statusvariablen manuell aktualisiert werden. Liefert `true` bei Erfolg.
```
ROBONECT_Update( $Instanz ); // Aktualisieren der Statusvariablen
```

#### UpdateErrorList( int $Instanz )
Mit dieser Funktion kann die Fehlerliste (sowie die Anzahl der Fehler) manuell aktualisiert werden. Leider ist die Robonect-Hardware hier etwas zickig und liefert nicht immer Daten :(
Alternativ kann in der Konfiguration **Fehlerliste bei jedem Update mit abrufen** aktiviert werden.
```
ROBONECT_UpdateErrorList( $Instanz ); // Aktualisiert die Fehlerdaten
```

#### ClearErrors( int $Instanz )
Mit dieser Funktion können die Fehlermeldungen gelöscht werden.
```
ROBONECT_ClearErrors( $Instanz ); // Löschen des Fehlerspeichers
```

#### SetMode( int $Instanz, string $mode )
Mit dieser Funktion kann der Modus des Rasenmähers gesetzt werden.
```
ROBONECT_SetMode( $Instanz, 'home' ); // Fährt den Rasenmäher in seine Ladestation
```
Erlaubte Modi sind:
* home : Wechseln des Modus nach "Home"
* eod : Wechseln des Modus nach "Feierabend" (EndOfDay)
* man : Wechseln des Modus nach "Manuell"
* auto : Wechseln des Modus nach "Automatisch"

#### StartMowingNow( int $Instanz, int $duration )
Startet das Mähen des Rasenmähers für eine angegebene Zeit in Minuten (10 - 1440). Der Rasenmäher fährt anschließend wieder in seine Ladestation. Bei `0` wird die Mähzeit aus den Vorgabewerten genutzt.
```
ROBONECT_StartMowingNow( $Instanz, 90 ); // lässt den Rasenmäher für 90 Minuten mähen
```

#### ScheduleJob( int $Instanz, int $duration, string $modeAfter, string $start, string $stop )
Plant einen Job für den Rasenmäher ein.
```
ROBONECT_ScheduleJob( $Instanz, 90, 'home', '20:00', '23:00' ); // lässt den Rasenmäher ab 20:00 für 90 Minuten mähen und fährt ihn anschließend wieder in die Ladestation
```
Erklärung der Parameter:
* duration: Die Dauer in Minuten, welche gemäht werden soll (10 - 1440)
* modeAfter: der Modus, der nach dem Mähen angefahren werden soll ( '' = 'home')
* start: Startzeit im Format HH:MM, zu welcher der Auftrag beginnen soll ( '' = sofort )
* stop: Endzeit im Format HH:MM, zu welcher der Job beendet werden soll ( '' = Start + Dauer + 5 Minuten )

Ein weiteres Beispiel:
```
ROBONECT_ScheduleJob( $Instanz, 90, '', '', '' ); // entspricht dem Kommando StartMowingNow( $Instanz, 90 )
```

#### DriveHome( int $Instanz )
Fährt den Rasenmäher in seine Ladestation.
```
ROBONECT_DriveHome( $Instanz ); // Fährt den Rasenmäher in seine Ladestation
```

#### Start( int $Instanz )
Mit dieser Funktion kann der Rasenmäher wieder gestartet werden (Rückkehr zum letzten Modus), wenn er zuvor gestoppt wurde!
```
ROBONECT_Start( $Instanz ); // Starten des Rasenmähers
```

#### Stop( int $Instanz )
Mit dieser Funktion kann der Rasenmäher gestoppt werden.
```
ROBONECT_Stop( $Instanz ); // Stoppen des Rasenmähers
```

#### GetTimerFromMower( int $Instanz )
Mit dieser Funktion können die programmierten Timer des Rasenmähers ausgelesen und der Wochenplan unterhalb der Status-Variable "Timer-Plan aktiv" aktualisiert werden.
Die Methode liefert entweder die Timer-Daten als Array oder FALSE.
ACHTUNG: Timer im Wochenplan werden gelöscht und neu geschrieben!
```
ROBONECT_GetTimerFromMower( $Instanz ); // Auslesen der Timer
```

#### SetTimerToMower( int $Instanz )
Mit dieser Funktion können die programmierten Timer des Wochenplans unterhalb der Status-Variable "Timer-Plan aktiv" in den Rasenmäher übertragen werden. Gleiche Zeitfenster an mehreren Tagen werden zu einem Timer zusammengefasst (max. 14 Timer).
ACHTUNG! Die bestehenden Timer im Rasenmäher werden überschrieben!
```
ROBONECT_SetTimerToMower( $Instanz ); // Übertragen der Timer
```

## 5. Versionshistorie

### Version 2.0
Überarbeitung für IP-Symcon 8.0 / 9.0

**Bitte beachten (manuell tätig werden):**
- Mindestversion ist jetzt IP-Symcon 8.0.
- MQTT läuft jetzt über den in IP-Symcon eingebauten **MQTT Server** statt über einen einfachen Server Socket. Wer MQTT genutzt hat: die alte Server Socket-Instanz löschen und wie unter [MQTT (optional)](#mqtt-optional) beschrieben einrichten. Wer MQTT nicht nutzt, muss nichts tun.
- Die Variablen nutzen jetzt Darstellungen statt eigener Variablenprofile. Die alten Profile `ROBONECT_*` werden nicht mehr benötigt und können gelöscht werden, sofern sie nicht anderweitig verwendet werden.
- Wer `UpdateErrorList` bisher über ein eigenes Ereignis aufgerufen hat, kann stattdessen **Fehlerliste bei jedem Update mit abrufen** aktivieren.

Neu:
- Variablen "Robonect erreichbar", "Letzte Aktualisierung", "Aktueller Fehler" und "Entfernung zur Ladestation"
- Option "Fehlerliste bei jedem Update mit abrufen"
- Instanzstatus "Anmeldung fehlgeschlagen" bei falschem Benutzer/Passwort
- Schaltflächen zum Testen und Bedienen in der Instanzkonfiguration
- Klima-Werte aus dem Health-Abruf

Fehlerbehebungen:
- Meldung "Semaphore Robonect..._ErrorList ... wurde nicht korrekt verlassen" behoben
- ClearErrors setzte die Fehleranzahl nicht zurück
- ScheduleJob lehnte Startzeiten vor 10:00 Uhr ab
- SetTimerToMower meldete Fehler beim Übertragen nicht zuverlässig und legte einen Timer zu viel an
- Werte per MQTT (z. B. "man. angehalten") wurden nicht passend zum Variablentyp umgewandelt
- "Pause" wurde in der Visualisierung nicht korrekt zurückgesetzt
- Externe Spannung wurde in mV statt V angezeigt
- HTML-Tabelle der Fehlermeldungen war fehlerhaft aufgebaut
- Verbindungsfehler wurden nicht erkannt (kürzere Timeouts, Status wird korrekt gesetzt)

### Version 1.1
Fehlerbehebungen und Erweiterung um GPS Daten

### Version 1.0.1 / .2 / .3 / .4
Kleinere Fehlerbehebungen
