# Eltako FWWKW71L – Symcon-Modul

Ersatz-Treiber für den **Eltako FWWKW71L** (2-Kanal-PWM-Dimmer für LED 12–36 V DC,
Warmweiß + Kaltweiß) unter **IP-Symcon 8** (Linux).

Das native Symcon-Modul ist defekt: es wertet die Bestätigungs-Telegramme des
Aktors nicht aus (unabhängig von der eingetragenen ReturnID). Dieses Modul baut
den Hardware-Treiber neu auf und liest die Rückmeldungen selbst.

> **Status: Phase 1 (Hardware-Treiber).** Gateway-Anbindung und das Dimm-/
> Rückmelde-Telegramm sind gegen einen Live-Mitschnitt des Original-Moduls
> verifiziert (Senden, Status-Auswertung WW/KW, Rausch-Filterung). Einzig das
> **Teach-In-Telegramm** ist noch `VERIFY@SETUP` und am echten Gerät zu
> bestätigen (bei Ersatz eines eingelernten Geräts nicht nötig).

## Architektur

- **Device-Modul** (`type: 3`), hängt sich als Child an das bestehende
  EnOcean-Gateway (`ConnectParent`).
- Telegramme: **4BS, freies Profil 07-3F-7F**, RORG `0xA5` (= `Device: 165`).
- **Eine Geräte-ID** (Sende-Offset auf der Gateway-BaseID): Kanal **WW** sendet
  über den Offset, **KW** über Offset **+ 1**. Ein Teach-In lernt beide Kanäle.
- **Eine Melde-ID** (Rückmelde-Adresse des Aktors): **WW** meldet über die
  Melde-ID, **KW** über Melde-ID **+ 1**.
- Dimm-/Rückmelde-Telegramm (gegen Live-Mitschnitt des Original-Moduls
  verifiziert, `DataLength = 4`):

  | Byte | Wert |
  | --- | --- |
  | `DataByte3` | `0x02` (Dimm-Telegramm) |
  | `DataByte2` | Helligkeit `0…100 %` |
  | `DataByte1` | `0x00` (interne Dimmgeschwindigkeit) |
  | `DataByte0` | `0x09` = ein, `0x08` = aus (Bit0 = An/Aus) |

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

1. **Geräte-ID** setzen: Wenn du das Original-Modul ersetzt, dieselbe Geräte-ID
   eintragen (z. B. `22`) – dann ist der Aktor bereits eingelernt und ein
   erneutes Teach-In entfällt. Für ein neues Gerät *Wähle verfügbare Geräte-ID*
   klicken (kleinste freie, ab `100`, um mit bestehenden Eltako-Geräten
   (Offsets 1–47) nicht zu kollidieren).
2. **Teach-In** (nur bei neuem Gerät): Aktor in den Lernmodus versetzen, dann
   *Einlernen* klicken. Ein Teach-In gilt für WW und KW.
3. **Melde-ID** ermitteln:
   - *Suchen (30 s)* starten und den Aktor mehrfach schalten.
   - In der Liste die ID mit hohem Zähler auswählen und per Button als Melde-ID
     übernehmen, danach speichern. (KW = Melde-ID + 1 wird automatisch genutzt.)
   - Alternativ direkt eintragen (z. B. `FFF01A81`).
4. **Status emulieren**: optional aktivieren, wenn die Variablen sofort den
   gesendeten Werten folgen sollen (statt erst auf die Rückmeldung zu warten).
5. **Test** über die Buttons *Test EIN/AUS/50 %*.

## Verifikation (`VERIFY@SETUP`)

GUIDs, Feldnamen **und** das Dimm-/Rückmelde-Telegramm (`DataByte3=0x02`,
`DataByte2`=Helligkeit, `DataByte0`=0x09/0x08) sind gegen einen Live-Mitschnitt
des Original-Moduls verifiziert. **Offen** bleibt nur das **Teach-In-Telegramm**
(`TEACH_DB3..0` in [`module.php`](EltakoFWWKW71L/module.php), aktuell der
Standard-Eltako-4BS-Teach `E0 40 0D 80`): am echten Gerät ein Einlernen
mitschneiden und bei Bedarf anpassen. Bei Ersatz eines bereits eingelernten
Geräts (gleiche Geräte-ID) ist Teach-In ohnehin nicht nötig.

## Public-API (für künftige Erweiterungs-Layer)

```php
EFW_SetWW($InstanceID, $value);            // 0..100
EFW_SetKW($InstanceID, $value);            // 0..100
EFW_SetBoth($InstanceID, $ww, $kw);
EFW_SwitchOff($InstanceID);
EFW_TeachIn($InstanceID);                  // ein Teach-In für beide Kanäle
EFW_PickFreeDeviceID($InstanceID);
EFW_StartDiscovery($InstanceID);
EFW_StopDiscovery($InstanceID);
EFW_AdoptDiscovered($InstanceID, $hexId);  // -> Melde-ID
```

Geplante Layer (bewusst **nicht** in diesem Modul): CCT-Komfort, Lichtszenen,
Last-State-Memory, Schlummer/Lichtwecker, Astro, Bidi-Watchdog. Die API hält
dafür typisierte, magic-freie Einstiegspunkte bereit.

## Lizenz

[MIT](LICENSE) © 2026 Norbert Schmerold
