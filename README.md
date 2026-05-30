# Eltako FWWKW71L – Symcon-Modul

Ersatz-Treiber für den **Eltako FWWKW71L** (2-Kanal-PWM-Dimmer für LED 12–36 V DC,
Warmweiß + Kaltweiß) unter **IP-Symcon 8** (Linux).

Das native Symcon-Modul ist defekt: es wertet die Bestätigungs-Telegramme des
Aktors nicht aus (unabhängig von der eingetragenen ReturnID). Dieses Modul baut
den Hardware-Treiber neu auf und liest die Rückmeldungen selbst.

> **Status: funktionsfähig.** Telegramm-Aufbau gegen die offizielle Eltako-Doku
> („Inhalte der Eltako-Funktelegramme", freies Profil 07-3F-7F) **und** gegen
> Live-Mitschnitte verifiziert: Senden, Status-Auswertung (beide
> Rückmelde-Formate, automatisch erkannt), Teach-In, Rausch-Filterung.

## Architektur

- **Device-Modul** (`type: 3`), hängt sich als Child an das bestehende
  EnOcean-Gateway (`ConnectParent`). Nachbau des nativen Moduls
  **„Eltako FWWKW71L (Hochauflösender WW/CW)"**.
- Telegramme: **4BS, freies Profil 07-3F-7F**, RORG `0xA5` (= `Device: 165`).

### Senden (Befehl an den Aktor) – immer hochauflösend

Der FWWKW71L-Befehl ist laut Eltako-Profil 07-3F-7F **immer** hochauflösend –
es gibt keinen Prozent-Befehl. **Eine** Geräte-ID (Sende-Offset), Kanal im Byte:

| Byte | Wert |
| --- | --- |
| `DataByte3` | obere 2 Bit des Werts |
| `DataByte2` | untere 8 Bit → `Wert = DataByte3·256 + DataByte2`, `0…1023` (1023 = 100 %) |
| `DataByte1` | Kanal: `0x10` = WW, `0x11` = KW (`0x02` = Status anfordern) |
| `DataByte0` | `0x0F` (GFVS-Master) |

- **Teach-In** (Profil 07-3F-7F): `DataByte3..0 = FF F8 0D 87`. Am Aktor den
  **oberen** Drehschalter auf die GFVS-Einlernfunktion **9** (hochauflösende
  Dimmwerte) bzw. **10** (GFVS mit Bestätigungs-Telegramm) und den **mittleren**
  auf **LRN** stellen, dann *Einlernen*.

### Empfang (Rückmeldung) – Format wird automatisch erkannt

Je nach Aktor-Einstellung „Dimmwert in % senden" kommt die Bestätigung in einem
von zwei Formaten – das Modul **erkennt beide automatisch** und zeigt das
erkannte Format im Formular an:

- **Hochauflösend**: `DataByte1` = Kanal (`0x10`/`0x11`), `DataByte0 = 0x0E`,
  Wert 10-Bit in `DataByte3:DataByte2`. **Eine** Melde-ID.
- **Prozent**: `DataByte3 = 0x02`, `DataByte2` = `0…100 %`, `DataByte0` =
  `0x09`/`0x08`. Kanal über die **ID** – der Aktor sendet ab seiner Base-ID:
  WW = **Base ID+1** (= Melde-ID), KW = Base ID+2 (= Melde-ID + 1). Als Melde-ID
  zählt die **WW-Adresse (Base ID+1)**.

> **Master-Telegramm:** Zusätzlich sendet der Aktor ein **Master-Telegramm**
> (Base ID+4, z. B. `FFF00984`) – und zwar im **hochauflösenden** Format, auch
> wenn die Kanäle Prozent melden. Eine reine Status-Abfrage löst **nur** dieses
> Master-Telegramm aus, nicht die Kanäle. Deshalb wertet die Melde-ID-Erkennung
> die **echten Kanal-Rückmeldungen beim Schalten** aus (siehe Einrichtung) und
> bevorzugt die Prozent-Adresse (`Base ID+1`) gegenüber dem hochauflösenden
> Master. Das Master-Telegramm wird im Normalbetrieb ignoriert.

Drei GUIDs mit unterschiedlichen Rollen (gemäß
[Symcon-Datenfluss-Doku](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/datenfluss/)
und [ConnectParent](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/connectparent/)):

| GUID | Rolle | Wo |
| --- | --- | --- |
| `{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}` | **Modul-GUID** des nativen EnOcean-Gateways | `ConnectParent()` in `module.php` |
| `{70E3075F-A35D-4DEB-AC20-C929A156FE48}` | **Daten-Interface** Device → Gateway (Senden), DataID | `parentRequirements` + Payload-`DataID` |
| `{DE2DA2C0-7A28-4D23-A9AA-6D1C7609C7EC}` | **Daten-Interface** Gateway → Device (Empfang) | `implemented` |

`ConnectParent` erwartet laut Doku eine **Modul-ID**, nicht die Interface-GUID;
`parentRequirements`/`implemented` enthalten dagegen die **Datenpakettyp-GUIDs**.
GUIDs und Telegramm-Feldnamen (`Device`, `DeviceID`, `DestinationID`,
`DataLength`, `DataByte3..0`) gegen die nativen EnOcean-Gerätemodule verifiziert
(Referenz: [nefiertsrebliS/MoreEnoceanFeatures](https://github.com/nefiertsrebliS/MoreEnoceanFeatures)).

## Installation

1. In Symcon **Kern-Instanzen → Modules** (Module Control) öffnen.
2. Repository hinzufügen:
   `https://github.com/norbert-schmerold/IPS_Eltako-FWWKW71L`
3. Neue Instanz anlegen: **Eltako FWWKW71L** (verbindet sich automatisch mit dem
   EnOcean-Gateway).

## Einrichtung

Pro physischem Aktor **eine** Instanz. Die Konfiguration ist bewusst minimal –
Senden ist immer hochauflösend, das Empfangsformat wird automatisch erkannt:

1. **Geräte-ID** setzen. Ersetzt du das Original-Modul, dieselbe Geräte-ID
   eintragen (z. B. `36`) – dann ist der Aktor schon eingelernt. Für ein neues
   Gerät *Freie Geräte-ID wählen* klicken.
2. **Einlernen** (nur neues Gerät): am Aktor den **oberen Drehschalter auf 9**
   (GFVS hochauflösend) bzw. **10** (GFVS mit Bestätigung) und den **mittleren
   auf LRN** stellen, dann *Einlernen* klicken. Danach mittleren zurück auf die
   gewünschte Mindesthelligkeit (`%`).
3. **Melde-ID** ermitteln: *Automatisch erkennen* klicken und **innerhalb von
   20 s den Aktor am Taster EIN und AUS schalten**. Das Modul liest die
   Kanal-Rückmeldung mit und trägt die WW-Adresse ein. (Alternativ *Suchen*,
   Aktor schalten, niedrigste Adresse übernehmen – oder die Melde-ID direkt
   eintragen.) Danach **speichern**.
4. Fertig. Das Formular zeigt das **erkannte Empfangsformat** (hochauflösend
   oder Prozent). Steuern/Status laufen, auch bei Taster-Bedienung des Aktors.

Unter **Erweitert**: *Status emulieren* (Werte sofort aus gesendeten Befehlen
setzen) und *Debug* (alle Telegramme ausgeben).

## Verifikation

GUIDs, Feldnamen und der komplette Telegramm-Aufbau (Befehl, beide
Rückmelde-Formate, Teach-In `FF F8 0D 87`, Status-Anforderung `DataByte1=0x02`)
sind gegen die offizielle Eltako-Doku **„Inhalte der Eltako-Funktelegramme"**
(freies Profil 07-3F-7F) und gegen Live-Mitschnitte verifiziert. Eine
Offline-Testsuite (gestubbtes `IPSModule`) prüft Encoding/Decoding beider
Formate, CCT-Round-Trip, Teach-In und die Melde-ID-Erkennung.

## Public-API (für künftige Erweiterungs-Layer)

```php
EFW_SetWW($InstanceID, $value);            // 0..100
EFW_SetKW($InstanceID, $value);            // 0..100
EFW_SetBoth($InstanceID, $ww, $kw);
EFW_AllOn($InstanceID);                     // beide auf 100 %
EFW_SwitchOff($InstanceID);
EFW_TeachIn($InstanceID);                  // ein Teach-In für beide Kanäle
EFW_PickFreeDeviceID($InstanceID);
EFW_DetectMelde($InstanceID);              // Melde-ID automatisch erkennen
EFW_StartDiscovery($InstanceID);
EFW_StopDiscovery($InstanceID);
EFW_AdoptDiscovered($InstanceID, $hexId);  // -> Melde-ID
```

Variablen (alle bedienbar) – passend zur nativen **„Licht"-Kachel** von Symcon:
- **Status** (`~Switch`, An/Aus) – schaltet beim Einschalten auf die **zuletzt**
  bekannte Helligkeit je Kanal zurück (nicht stur 100 %).
- **Helligkeit** (`~Intensity.100`) + **Farbtemperatur** (neue Slider-Darstellung
  mit Farbtemperatur-Template, **auf 2700–6500 K begrenzt** – den tatsächlich
  einstellbaren Bereich) – CCT-Komfort, setzen WW/KW über ein additives
  Mischmodell: `Helligkeit = max(WW,KW)`. Verlustfreier Round-Trip mit WW/KW.
- **Warmweiß** / **Kaltweiß** – die Kanäle weiterhin einzeln direkt regelbar;
  Helligkeit/Farbtemperatur folgen automatisch.

**Native Licht-Kachel:** Die Variablen nutzen genau die Darstellungen, die
Symcons „Licht"-Objektdarstellung erwartet (Status = Schalter/An-Aus, Helligkeit
= Schieberegler/Intensität, Farbtemperatur = Schieberegler/Farbtemperatur). In
der Kachel-Visualisierung der Instanz die Darstellung **„Licht"** zuweisen →
Status + Helligkeit + Farbtemperatur erscheinen in **einer** Kachel.

Komfort:
- **Rückmeldeadresse automatisch erkennen**: stößt den Aktor an und übernimmt
  die ID, von der er bestätigt (`DataByte0 = 0x0E`), automatisch ins Melde-ID-Feld.

Geplante Layer (bewusst **nicht** in diesem Modul): CCT-Komfort, Lichtszenen,
Schlummer/Lichtwecker, Astro, Bidi-Watchdog. Die API hält dafür typisierte,
magic-freie Einstiegspunkte bereit.

## Lizenz

[MIT](LICENSE) © 2026 Norbert Schmerold
