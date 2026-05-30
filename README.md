# Eltako FWWKW71L – Symcon-Modul

Ersatz-Treiber für den **Eltako FWWKW71L** (2-Kanal-PWM-Dimmer für LED 12–36 V DC,
Warmweiß + Kaltweiß) unter **IP-Symcon 8** (Linux).

Das native Symcon-Modul ist defekt: es wertet die Bestätigungs-Telegramme des
Aktors nicht aus (unabhängig von der eingetragenen ReturnID). Dieses Modul baut
den Hardware-Treiber neu auf und liest die Rückmeldungen selbst.

> **Status: Phase 1 (Hardware-Treiber, minimal).** Sende-/Empfangs-Logik
> funktioniert, einige Telegramm-Details sind im Code mit `VERIFY@SETUP`
> markiert und müssen am echten Setup per Debug-Mitschnitt bestätigt werden,
> bevor man sich auf sie verlässt.

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
| `{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}` | ParentRequirement (EnOcean-Gateway) |
| `{DE2DA2C0-7A28-4D23-A9AA-6D1C7609C7EC}` | DataID Gateway → Device (Empfang) |
| `{70E3075F-A35D-4DEB-AC20-C929A156FE48}` | DataID Device → Gateway (Senden) |

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

Vor dem produktiven Einsatz die Annahmen über das Gateway-JSON bestätigen:

1. Property **„Debug: ALLE eingehenden Telegramme ausgeben"** aktivieren.
2. Debug-Fenster der Instanz öffnen (`SendDebug`-Ausgaben).
3. Am Aktor eine Statusänderung auslösen und das gedumpte RX-JSON prüfen:
   - Heißt das Absenderfeld wirklich `SenderID`?
   - Stimmen `Device`, `DataByte3`, `DataByte2`, `DataByte0`?
4. Beim Senden den Mitschnitt am Gateway gegen das gesendete JSON prüfen.

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
