# Eltako FWWKW71L – Symcon-Modul

Ersatz-Treiber für den **Eltako FWWKW71L** (2-Kanal-PWM-Dimmer für LED 12–36 V DC,
Warmweiß + Kaltweiß) unter **IP-Symcon 8** (Linux).

Das native Symcon-Modul ist defekt: es wertet die Bestätigungs-Telegramme des
Aktors nicht aus (unabhängig von der eingetragenen ReturnID). Dieses Modul baut
den Hardware-Treiber neu auf und liest die Rückmeldungen selbst.

> **Status: Phase 1 (Hardware-Treiber, minimal).** Gateway-Anbindung
> (Parent-GUID, Sende-/Empfangs-Feldnamen) ist gegen die nativen
> EnOcean-Module verifiziert. Verbleibende, **FWWKW-spezifische**
> Telegramm-Details (DB3/DB0-Datenbytes, Teach-In-LRN-Layout, PWM↔DataByte2)
> sind im Code mit `VERIFY@SETUP` markiert und müssen am echten Setup per
> Debug-Mitschnitt bestätigt werden, bevor man sich auf sie verlässt.

## Architektur

- **Device-Modul** (`type: 3`), hängt sich als Child an das bestehende
  EnOcean-Gateway (`ConnectParent`).
- Telegramme: **4BS, freies Profil 07-3F-7F**, RORG `0xA5` (= `Device: 165`).
- Pro Kanal eine eigene physische Sender-ID (Gateway-BaseID + Offset). Jeder
  Kanal wird separat in den Aktor eingelernt; der PWM-Wert steckt in `DataByte2`.
- Der Aktor meldet Statusänderungen mit seinen eigenen IDs zurück
  (BaseID +1 = WW, +2 = KW), die gegen die konfigurierten **ReturnIDs** gematcht
  werden.

| GUID | Bedeutung |
| --- | --- |
| `{70E3075F-A35D-4DEB-AC20-C929A156FE48}` | ParentRequirement (EnOcean-Gateway) **und** DataID Device → Gateway (Senden) |
| `{DE2DA2C0-7A28-4D23-A9AA-6D1C7609C7EC}` | Implemented-Interface, DataID Gateway → Device (Empfang) |

Die GUIDs und die Telegramm-Feldnamen (`Device`, `DeviceID`, `DestinationID`,
`DataLength`, `DataByte3..0`) wurden gegen die nativen EnOcean-Gerätemodule
verifiziert (Referenz: [nefiertsrebliS/MoreEnoceanFeatures](https://github.com/nefiertsrebliS/MoreEnoceanFeatures)).

## Installation

1. In Symcon **Kern-Instanzen → Modules** (Module Control) öffnen.
2. Repository hinzufügen:
   `https://github.com/norbert-schmerold/IPS_Eltako-FWWKW71L`
3. Neue Instanz anlegen: **Eltako FWWKW71L** (verbindet sich automatisch mit dem
   EnOcean-Gateway).

## Einrichtung

1. **Sender-Offsets** stehen auf `0` (= automatisch). Auto-Zuweisung beginnt bei
   `100`, um nicht mit bestehenden Eltako-Geräten (Offsets 1–47) zu kollidieren.
   Bei Bedarf manuell überschreiben.
2. **Teach-In** je Kanal: Aktor in den Lernmodus versetzen, dann im Formular
   *Teach-In WW* bzw. *Teach-In KW* klicken.
3. **ReturnIDs** ermitteln:
   - *Discovery (30s)* starten und am Aktor eine Statusänderung auslösen.
   - In der Liste die passende ID auswählen und per Button als ReturnID WW/KW
     übernehmen, danach speichern.
   - Alternativ die IDs direkt eintragen (z. B. `FFF01A81` / `FFF01A82`).
4. **Test** über die Buttons *Test EIN/AUS/50%/Rampe*.

## Verifikation der Telegramm-Felder (`VERIFY@SETUP`)

Die **Feldnamen** des Gateway-JSON (`Device`, `DeviceID`, `DataByteN`) sind
gegen die nativen EnOcean-Module verifiziert. Offen bleiben die
**FWWKW-spezifischen Byte-Werte** — vor dem produktiven Einsatz bestätigen:

1. Property **„Debug: ALLE eingehenden Telegramme ausgeben"** aktivieren.
2. Debug-Fenster der Instanz öffnen (`SendDebug`-Ausgaben).
3. Am Aktor eine Statusänderung auslösen und das gedumpte RX-JSON prüfen:
   - Welcher `DataByte3`-Wert kennzeichnet das Daten-/Rückmelde-Telegramm?
     (Konstante `DB3_DATA`, aktuell `0x02`.)
   - Steckt der PWM-Wert in `DataByte2`, der An/Aus-Flag in `DataByte0`?
   - Welche Aktor-`DeviceID` meldet sich (→ ReturnID)?
4. Beim Senden den Mitschnitt am Gateway gegen das gesendete JSON prüfen
   (Teach-In-LRN-Bytes `DB3..0`).

Stimmen die Feldnamen nicht, in [`module.php`](EltakoFWWKW71L/module.php) an den
`VERIFY@SETUP`-Stellen anpassen.

## Public-API (für künftige Erweiterungs-Layer)

```php
EFW_SetWW($InstanceID, $value);            // 0..100
EFW_SetKW($InstanceID, $value);            // 0..100
EFW_SetBoth($InstanceID, $ww, $kw);
EFW_SwitchOff($InstanceID);
EFW_TeachIn($InstanceID, $channel);        // 'WW' | 'KW'
EFW_StartDiscovery($InstanceID);
EFW_StopDiscovery($InstanceID);
EFW_AdoptDiscovered($InstanceID, $channel, $hexId);
EFW_TestRamp($InstanceID, $channel);       // 'WW' | 'KW'
EFW_CheckBidi($InstanceID);
```

Geplante Layer (bewusst **nicht** in diesem Modul): CCT-Komfort, Lichtszenen,
Last-State-Memory, Schlummer/Lichtwecker, Astro, Bidi-Watchdog. Die API hält
dafür typisierte, magic-freie Einstiegspunkte bereit.

## Lizenz

[MIT](LICENSE) © 2026 Norbert Schmerold
