# Eltako FWWKW71L – Symcon-Modul

Ersatz-Treiber für den **Eltako FWWKW71L** (2-Kanal-PWM-Dimmer für LED 12–36 V DC,
Warmweiß + Kaltweiß) unter **IP-Symcon 8** (Linux).

Das native Symcon-Modul ist defekt: es wertet die Bestätigungs-Telegramme des
Aktors nicht aus (unabhängig von der eingetragenen ReturnID). Dieses Modul baut
den Hardware-Treiber neu auf und liest die Rückmeldungen selbst.

> ⚠️ **Hinweis / Haftungsausschluss:** Dieses Modul – Code **und** Dokumentation –
> wurde **mit KI (Anthropic Claude)** erstellt. Es entstand für den privaten
> Eigengebrauch und ist **ohne Gewähr**. **Es wird keinerlei Haftung übernommen**
> für Schäden, Fehlfunktionen, Sachschäden oder Datenverlust, die aus der Nutzung
> entstehen. Einsatz auf eigene Gefahr – vor dem produktiven Einsatz selbst prüfen
> und testen.

> **Status: funktionsfähig.** Telegramm-Aufbau gegen die offizielle Eltako-Doku
> („Inhalte der Eltako-Funktelegramme", freies Profil 07-3F-7F) **und** gegen
> Live-Mitschnitte verifiziert: Senden, Status-Auswertung (hochauflösend + Prozent),
> Teach-In, automatische Melde-ID-Erkennung, Rausch-Filterung.

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

- **Teach-In** (Profil 07-3F-7F): `DataByte3..0 = FF F8 0D 87`.

### Empfang (Rückmeldung) – Haupt-Melde-ID + Kanaloffset

Der Aktor meldet von fortlaufenden Offsets auf seiner **Haupt-Melde-ID** (das ist
genau die „Rückmelde-ID", die PCT14 als `FF F0 09 80 + Kanalnummer` anzeigt). Im
Modul trägst du nur die **Haupt-ID** ein (z. B. `FFF00980`); die Kanaladressen
rechnet das Modul selbst:

| Adresse | Format | Inhalt |
| --- | --- | --- |
| **Haupt + 4** (Master) | hochauflösend: `DataByte0=0x0E`, Kanal in `DataByte1` (`0x10`/`0x11`), 10-Bit-Wert in `DataByte3:DataByte2` | **beide Kanäle**, 10-Bit – **bevorzugt** |
| **Haupt + 1** | Prozent: `DataByte3=0x02`, `DataByte2 = 0…100 %`, `DataByte0` = `0x09`/`0x08` | WW – Fallback |
| **Haupt + 2** | Prozent (wie oben) | KW – Fallback |

Das Modul nimmt automatisch das beste: Sobald das **hochauflösende Master-Telegramm**
(`Haupt+4`) gesehen wird, „rastet" es darauf ein und ignoriert die redundanten
Prozent-Telegramme. Sendet ein Aktor **nur** das Prozent-Telegramm, wird dieses
genutzt. Damit läuft das Modul mit beliebiger Aktor-Einstellung – **eines** der
Rückmelde-Telegramme genügt.

| GUID | Rolle | Wo |
| --- | --- | --- |
| `{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}` | **Modul-GUID** des nativen EnOcean-Gateways | `ConnectParent()` in `module.php` |
| `{70E3075F-A35D-4DEB-AC20-C929A156FE48}` | **Daten-Interface** Device → Gateway (Senden), DataID | `parentRequirements` + Payload-`DataID` |
| `{DE2DA2C0-7A28-4D23-A9AA-6D1C7609C7EC}` | **Daten-Interface** Gateway → Device (Empfang) | `implemented` |

## Aktor-Einstellungen am FWWKW71L

Das Modul ist bewusst genügsam: **mit dem Einlernen über die Drehschalter läuft es
auch ohne DAT71/PCT14.** Ein DAT71 ist nur zum Optimieren nötig – nicht jeder hat einen.

### 1. Einlernen über die Drehschalter (Pflicht, ohne DAT71)

Der Aktor hat drei Drehschalter:

- **Oberer (Einlern-Funktionswahl):** auf **10** = „Drehtaster und GFVS". Diese
  Position aktiviert beim Einlernen automatisch das **Bestätigungs-/Rückmelde­telegramm**
  – dadurch funktioniert die Rückmeldung in Symcon **ohne DAT71**.
  (Position **9** = „GFVS hochauflösende Dimmwerte" geht ebenfalls, sendet aber ggf.
  keine automatische Bestätigung; dann die Rückmeldung per DAT71 aktivieren –
  siehe unten. **Nicht** Position 8.)
- **Mittlerer:** zum Einlernen auf **LRN**, danach zurück auf die gewünschte
  **Mindesthelligkeit (%)** (nicht auf LRN/CLR stehen lassen).
- **Unterer:** **Dimmgeschwindigkeit** 1…6 (nicht OFF).

Ablauf: oberen → **10**, mittleren → **LRN**, im Modul *Einlernen* klicken, dann
mittleren zurück auf **%**. (Reset/CLR: mittleren auf CLR, oberen 8× zum
Linksanschlag drehen.)

### 2. Rückmeldung (Pflicht – eines genügt)

Das Modul versteht **beide** Rückmelde-Formate und wählt automatisch das beste:

- **Hochauflösendes „Dimmerwerttelegramm"** (Master, `Haupt+4`) – bevorzugt.
- **„Bestätigungstelegramm mit %-Dimmwert"** (`Haupt+1`/`+2`) – Fallback.

Solange der Aktor **mindestens eines** davon sendet (per Drehschalter-10 automatisch
aktiv), bekommst du Rückmeldung. **Die %-Bestätigung musst du nicht extra
einschalten** – lässt du sie aus, wird nur das hochauflösende Telegramm genutzt.

### 3. PCT14/DAT71 (optional, nur zum Optimieren)

| Einstellung | Pflicht? | Empfehlung |
| --- | --- | --- |
| **Dimmerwerttelegramm** | – | **ein** → garantiert das hochauflösende Master-Telegramm (das das Modul bevorzugt) |
| **Bestätigungstelegramm mit %-Dimmwert** | nein | **kann AUS** bleiben – das Modul nutzt das hochauflösende; an = nur Fallback + mehr Funk-Traffic |
| **Bestätigungstelegramm mit Tastertelegramm** | nein | **AUS** – das Modul ignoriert die RPS-Tastertelegramme (`Device 246`), reines Funk-Rauschen |
| **Betriebsart** | – | **Drehschalter** (Normalbetrieb) |
| **PWM-Frequenz** | nein | hoch (1–4 kHz) gegen Flackern/Brummen |
| **Mindesthelligkeit** | nein | nach Geschmack (unteres Dimm-Ende nutzbar machen) |
| **Verhalten nach Spannungsausfall (Memory)** / **Dimmwertspeicherung** | nein | bei Bedarf, damit der Aktor beim lokalen Einschalten den letzten Wert nimmt (statt 100 %) |

> Die **„Rückmelde-ID (Hex)"** in PCT14 – z. B. `FF F0 09 80 + Kanalnummer` – ist
> genau die **Haupt-Melde-ID**, die du im Modul einträgst (ohne Kanalnummer, also
> `FFF00980`). Das Modul leitet die Kanaladressen (`+1` WW, `+2` KW, `+4` Master)
> selbst ab.

## Installation

1. In Symcon **Kern-Instanzen → Modules** (Module Control) öffnen.
2. Repository hinzufügen:
   `https://github.com/norbert-schmerold/IPS_Eltako-FWWKW71L`
3. Neue Instanz anlegen: **Eltako FWWKW71L** (verbindet sich automatisch mit dem
   EnOcean-Gateway).

## Einrichtung

Pro physischem Aktor **eine** Instanz:

1. **Geräte-ID** setzen. Ersetzt du das Original-Modul, dieselbe Geräte-ID
   eintragen – dann ist der Aktor schon eingelernt. Für ein neues Gerät
   *Freie Geräte-ID wählen* klicken (sucht die niedrigste freie Sende-ID über alle
   EnOcean-Geräte am selben Gateway).
2. **Einlernen** (nur neues Gerät): am Aktor oberen Drehschalter auf **10** (bzw. 9),
   mittleren auf **LRN**, dann *Einlernen* klicken; mittleren zurück auf **%**.
3. **Melde-ID (Haupt)** ermitteln: *Automatisch erkennen* klicken – das Modul steuert
   Warmweiß und Kaltweiß kurz nacheinander an und trägt die **Haupt-Melde-ID** ein.
   Alternativ die **„Rückmelde-ID"** aus PCT14 ohne Kanalnummer direkt eintragen
   (z. B. `FFF00980`). Danach **speichern**. Das Formular zeigt zur Kontrolle die
   abgeleiteten Adressen (Master/WW/KW).
4. Fertig. Steuern und Status laufen, auch bei Bedienung am Aktor/Taster.

**Letzten Zustand merken** (Checkbox): an = beim Einschalten (Status-Schalter)
wird der zuletzt bekannte WW/KW-Zustand wiederhergestellt; aus = beim Einschalten
**Neutralweiß 100 %**.

Unter **Erweitert**:
- *Status emulieren* – setzt die Werte **sofort beim Senden** (die echte Rückmeldung
  korrigiert sie anschließend). Empfohlen: verhindert das kurze „Zurückblitzen" und
  das „nicht durchgeführt" der Kachel, weil die Variable sofort reagiert.
- *Debug* – alle eingehenden Telegramme ausgeben.

## Variablen (passend zur nativen „Licht"-Kachel)

- **Status** (`~Switch`, An/Aus) – beim Einschalten je nach Einstellung
  **„Letzten Zustand merken"**: **ein** → zurück auf die zuletzt bekannten
  WW/KW-Werte; **aus** → **Neutralweiß 100 %**.
- **Helligkeit** (`~Intensity.100`) + **Farbtemperatur** (Slider mit
  Farbtemperatur-Template, **auf 2700–6500 K begrenzt**) – CCT-Komfort über ein
  additives Mischmodell (`Helligkeit = max(WW,KW)`, verlustfreier Round-Trip mit
  WW/KW). Der Farbtemperatur-Slider bewegt sich in **100-K-Schritten** (Neutralweiß
  4600 K ist damit ein natürlicher Rastpunkt); die Variable selbst nimmt per
  Skript/`SetValue` **beliebige Werte (1 K)** an.
- **Warmweiß** / **Kaltweiß** – die Kanäle einzeln direkt regelbar;
  Helligkeit/Farbtemperatur folgen automatisch.

**Letzten Zustand merken / Neutralweiß:** Die Einstellung *„Letzten Zustand merken"*
steuert das Einschalt-Verhalten (Status-Schalter in Symcon). Für „einfach wieder
normales neutrales Licht" gibt es jederzeit **`EFW_AllOn`** (WW 100 % + KW 100 %);
den gemerkten Zustand stellt **`EFW_RestoreLast`** wieder her – beide lassen sich an
beliebige Symcon-Buttons/Ereignisse binden.

**Wandtaster (lokales Einschalten):** Optionale Einstellung *„Auch am Wandtaster …"*.
Ist sie **aus**, macht der Wandtaster genau das, was der Aktor tut (für „beim
Einschalten letzter Wert" am Wandtaster die **Dimmwertspeicherung des Aktors** in
PCT14 nutzen). Ist sie **an**, erkennt das Modul ein lokales Einschalten (OFF→ON, das
es nicht selbst ausgelöst hat) und stellt den **gemerkten Zustand** wieder her – das
funktioniert **ohne DAT71**, kann aber kurz aufblitzen (Aktor geht erst auf seinen
Einschaltwert, dann zieht das Modul nach) und überstimmt ein absichtliches lokales
Voll-Einschalten. Eigene Symcon-Befehle werden dabei ausgeblendet (kein Fehlauslösen).

**Native Licht-Kachel:** In der Kachel-Visualisierung der Instanz die Darstellung
**„Licht"** zuweisen → Status + Helligkeit + Farbtemperatur erscheinen in **einer**
Kachel.

## Public-API (für künftige Erweiterungs-Layer)

```php
EFW_SetWW($InstanceID, $value);            // 0..100
EFW_SetKW($InstanceID, $value);            // 0..100
EFW_SetBoth($InstanceID, $ww, $kw);
EFW_AllOn($InstanceID);                     // beide auf 100 %
EFW_SwitchOff($InstanceID);
EFW_RestoreLast($InstanceID);              // letzten gemerkten Zustand wiederherstellen
EFW_TeachIn($InstanceID);                  // ein Teach-In für beide Kanäle
EFW_PickFreeDeviceID($InstanceID);
EFW_DetectMelde($InstanceID);              // Haupt-Melde-ID automatisch erkennen
```

## Verifikation

GUIDs, Feldnamen und der Telegramm-Aufbau (Befehl, beide Rückmelde-Formate,
Teach-In `FF F8 0D 87`, Status-Anforderung `DataByte1=0x02`) sind gegen die
offizielle Eltako-Doku **„Inhalte der Eltako-Funktelegramme"** (freies Profil
07-3F-7F) und gegen **Live-Mitschnitte** verifiziert. Die Haupt-Melde-ID-Struktur
(`Haupt+1` WW, `+2` KW, `+4` Master, beide Kanäle in `DataByte1`) wurde aus einem
echten Mitschnitt (`base FFF00980`) und der PCT14-Anzeige (`FF F0 09 80 +
Kanalnummer`) bestätigt.

## Danksagung / Credits

- **Eltako** – Telegramm-Profil 07-3F-7F (offizielle Doku „Inhalte der
  Eltako-Funktelegramme") und PCT14/DAT71.
- **[nefiertsrebliS/MoreEnoceanFeatures](https://github.com/nefiertsrebliS/MoreEnoceanFeatures)**
  – Referenz für die Symcon-EnOcean-Integration. Die Funktion *„niedrigste freie
  Geräte-ID am Gateway"* (`EFW_PickFreeDeviceID`) ist der dortigen `FreeDeviceID()`
  **nachempfunden**; GUIDs und Telegramm-Feldnamen (`Device`, `DeviceID`,
  `DataLength`, `DataByte…`) wurden gegen dieses Projekt und die nativen
  Symcon-EnOcean-Module abgeglichen. **Props an nefiertsrebliS.**

> Es wurde **kein** Code 1:1 aus anderen Modulen kopiert; die genannten Stellen sind
> eigenständige Nachbauten bzw. Referenzen.

## Lizenz

[MIT](LICENSE) © 2026 Norbert Schmerold
