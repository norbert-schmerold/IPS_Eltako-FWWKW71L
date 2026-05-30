<?php

declare(strict_types=1);

/**
 * Eltako FWWKW71L – 2-channel PWM dimmer (WW/KW) for LED 12-36V DC.
 *
 * Replacement driver for the native "Hochauflösender WW/CW" module, which fails
 * to evaluate the actor's confirmation telegrams. Single-ID layout:
 *
 *   - ONE "Geräte-ID" (sender offset on the gateway BaseID); both channels send
 *     from it, the channel is carried in DataByte1.
 *   - ONE "Melde-ID" (the actor's bidirectional feedback address); both channels
 *     report from it, again distinguished by DataByte1.
 *   - ONE teach-in for both channels.
 *
 * Verified against the official Eltako telegram documentation (free profile
 * 07-3F-7F) and live sniffs.
 *
 * SEND (command) is always high-resolution: Device=165, value = (DB3<<8)|DB2
 * (0..1023), DB1 = channel (0x10=WW, 0x11=KW), DB0 = 0x0F (GFVS master); one
 * sender offset. Teach-in DB3..0 = FF F8 0D 87. DB1 = 0x02 requests a status
 * confirmation without changing the output.
 *
 * RECEIVE (confirmation) auto-detects either format (per the actor's "Dimmwert
 * in % senden" setting), and the detected format is shown in the form:
 *   Hi-res  : DB1 = channel, DB0 = 0x0E, value 10-bit in DB3:DB2; one Melde-ID.
 *   Percent : DB3 = 0x02, DB2 = 0..100 %, DB0 = 0x09/0x08; channel per ID
 *             (WW = Melde-ID = Base ID+1, KW = Melde-ID+1 = Base ID+2).
 */
class EltakoFWWKW71L extends IPSModule
{
    /**
     * MODULE GUID of the native EnOcean Gateway/Configurator (for ConnectParent).
     * This is a module id, NOT a data interface — see Symcon ConnectParent docs.
     */
    private const GATEWAY_MODULE_GUID = '{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}';
    /** Data INTERFACE GUID gateway <-> device: payload DataID + parentRequirements. */
    private const TX_DATAID = '{70E3075F-A35D-4DEB-AC20-C929A156FE48}';

    /** RORG 4BS (0xA5). */
    private const RORG_4BS = 165;

    // --- Telegram formats (the actor's "Dimmwert in % senden" PCT14 setting) ---
    /** Percentage format: DataByte2 = 0..100, two consecutive Melde-/Sender-IDs. */
    private const FORMAT_PERCENT = 'percent';
    /** High-resolution format: 10-bit value, channel in DataByte1, single ID. */
    private const FORMAT_HIRES = 'hires';

    // Percentage format bytes.
    /** DataByte3 marker of a percentage dim telegram. */
    private const DB3_DIM = 0x02;
    /** DataByte0 on/off for the percentage format. */
    private const DB0_ON = 0x09;
    private const DB0_OFF = 0x08;

    // High-resolution format.
    /**
     * High-resolution dim value is 10-bit (0..1023), split across two bytes:
     * DataByte3 = high 2 bits, DataByte2 = low byte. 1023 = 100 %.
     */
    private const VALUE_MAX = 1023;
    /** Channel selector in DataByte1 (verified): WW = 0x10, KW = 0x11. */
    private const DB1_WW = 0x10;
    private const DB1_KW = 0x11;
    /** DataByte0 of an outgoing hi-res command (the actor confirms with 0x0E). */
    private const DB0_CMD = 0x0F;

    /**
     * Teach-in (LRN) telegram for the free profile 07-3F-7F, per the official
     * Eltako telegram documentation ("Inhalte der Eltako-Funktelegramme"):
     * Lerntelegramm DB3..DB0 = 0xFF, 0xF8, 0x0D, 0x87.
     */
    private const TEACH_DB3 = 0xFF;
    private const TEACH_DB2 = 0xF8;
    private const TEACH_DB1 = 0x0D;
    private const TEACH_DB0 = 0x87;

    /** DataByte1 = 0x02 requests a confirmation telegram without changing state. */
    private const DB1_STATUS_REQUEST = 0x02;

    /** Auto-assigned "Wähle freie Geräte-ID" starts here (existing Eltako 1-47). */
    private const SENDER_OFFSET_BASE = 100;
    /** Discovery collection window in seconds. */
    private const DISCOVERY_WINDOW = 30;

    /**
     * Colour-temperature endpoints (Kelvin) for the standard ~TWColor slider used
     * by the native "Licht" tile. T = 0 % maps to warm, T = 100 % to cold.
     */
    private const KELVIN_WARM = 2700;
    private const KELVIN_COLD = 6500;

    public function Create()
    {
        parent::Create();

        // --- Properties (mirror the native module's single-ID form) ---
        $this->RegisterPropertyInteger('DeviceID', 0);     // Geräte-ID (sender offset)
        $this->RegisterPropertyString('MeldeID', '');      // bidi feedback base ID (hex)
        $this->RegisterPropertyBoolean('EmulateStatus', false);
        $this->RegisterPropertyBoolean('DebugPromiscuous', false);

        // --- Runtime state ---
        $this->RegisterAttributeBoolean('DiscoveryActive', false);
        $this->RegisterAttributeBoolean('DetectActive', false);
        // Last format auto-detected from incoming telegrams (for display).
        $this->RegisterAttributeString('DetectedFormat', '');
        // Last non-zero brightness per channel, restored when switching on.
        $this->RegisterAttributeInteger('Last_WW', 100);
        $this->RegisterAttributeInteger('Last_KW', 100);

        // --- Variables ---
        // CCT comfort controls (compute WW/KW) plus the raw channels (fully adjustable).
        // The presentations match what the native "Licht" tile expects (An/Aus,
        // Intensität, Farbtemperatur). The colour-temperature slider uses the new
        // presentation with the colour-temperature template and is limited to the
        // device's actual range (KELVIN_WARM..KELVIN_COLD).
        $this->RegisterVariableBoolean('Status', 'Status', '~Switch', 10);
        $this->RegisterVariableInteger('Helligkeit', 'Helligkeit', '~Intensity.100', 20);
        $this->RegisterVariableInteger('Farbtemperatur', 'Farbtemperatur', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'TEMPLATE'     => VARIABLE_TEMPLATE_SLIDER_COLOR_TEMPERATURE,
            'MIN'          => self::KELVIN_WARM,
            'MAX'          => self::KELVIN_COLD,
        ], 30);
        $this->RegisterVariableInteger('WW', 'Warmweiß', '~Intensity.100', 40);
        $this->RegisterVariableInteger('KW', 'Kaltweiß', '~Intensity.100', 50);

        $this->EnableAction('Status');
        $this->EnableAction('Helligkeit');
        $this->EnableAction('Farbtemperatur');
        $this->EnableAction('WW');
        $this->EnableAction('KW');

        // Start the colour temperature at a neutral, valid Kelvin value.
        if ((int) $this->GetValue('Farbtemperatur') < self::KELVIN_WARM) {
            $this->SetValue('Farbtemperatur', $this->tempToKelvin(50));
        }

        // --- Timers ---
        $this->RegisterTimer('DiscoveryStop', 0, 'EFW_StopDiscovery($_IPS[\'TARGET\']);');
        $this->RegisterTimer('DetectStop', 0, 'EFW_StopDetectMelde($_IPS[\'TARGET\']);');

        $this->SetBuffer('Discovery', json_encode([]));

        $this->ConnectParent(self::GATEWAY_MODULE_GUID);
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->ConnectParent(self::GATEWAY_MODULE_GUID);

        // Receive filter: promiscuous = everything, otherwise only 4BS (Device=165).
        if ($this->ReadPropertyBoolean('DebugPromiscuous')) {
            $this->SetReceiveDataFilter('.*');
        } else {
            $this->SetReceiveDataFilter('.*"Device":' . self::RORG_4BS . '.*');
        }

        // Sync the variables to the actor's actual state when the gateway is ready.
        if ($this->ReadPropertyInteger('DeviceID') > 0 && $this->HasActiveParent()) {
            $this->sendStatusRequest();
        }
    }

    // =====================================================================
    // Public API (prefix EFW_)
    // =====================================================================

    /**
     * Set the warm-white channel (sender offset = Geräte-ID).
     *
     * @param int $value PWM percentage, clamped to 0-100.
     */
    public function SetWW(int $value): void
    {
        $this->setChannel('WW', $value);
    }

    /**
     * Set the cold-white channel (sender offset = Geräte-ID + 1).
     *
     * @param int $value PWM percentage, clamped to 0-100.
     */
    public function SetKW(int $value): void
    {
        $this->setChannel('KW', $value);
    }

    /**
     * Set both channels in one call.
     */
    public function SetBoth(int $ww, int $kw): void
    {
        $this->SetWW($ww);
        $this->SetKW($kw);
    }

    /**
     * Switch both channels off (PWM 0).
     */
    public function SwitchOff(): void
    {
        $this->SetBoth(0, 0);
    }

    /**
     * Send the teach-in (LRN) telegram (free profile 07-3F-7F: FF F8 0D 87).
     * Set the actor's middle rotary switch to position 8 ("GFVS mit
     * hochauflösenden Dimmwerten einlernen") first; one teach-in covers both
     * channels (the actor distinguishes WW/KW via DataByte1).
     */
    public function TeachIn(): void
    {
        $offset = $this->ReadPropertyInteger('DeviceID');
        if ($offset <= 0) {
            echo $this->Translate('Bitte zuerst die Geräte-ID setzen.');
            return;
        }
        $this->SendDebug(
            'TX TeachIn',
            sprintf('offset=%d DB3..0=%02X %02X %02X %02X', $offset, self::TEACH_DB3, self::TEACH_DB2, self::TEACH_DB1, self::TEACH_DB0),
            0
        );
        $this->sendTelegram($offset, self::TEACH_DB3, self::TEACH_DB2, self::TEACH_DB1, self::TEACH_DB0);
    }

    /**
     * Pick the smallest free Geräte-ID (base + base+1 unused by other instances
     * of this module) and write it into the form field.
     */
    public function PickFreeDeviceID(): void
    {
        // Sending always uses a single offset (channel is in DataByte1), so one
        // offset per instance is enough.
        $used = [];
        foreach (IPS_GetInstanceListByModuleID(self::moduleId()) as $iid) {
            if ($iid === $this->InstanceID) {
                continue;
            }
            $d = (int) IPS_GetProperty($iid, 'DeviceID');
            if ($d > 0) {
                $used[$d] = true;
            }
        }
        $n = self::SENDER_OFFSET_BASE;
        while (isset($used[$n])) {
            $n++;
        }
        $this->UpdateFormField('DeviceID', 'value', $n);
    }

    /**
     * Start a discovery window: collect every incoming 4BS sender ID for
     * DISCOVERY_WINDOW seconds. Toggle the actor a few times so its Melde-ID
     * shows up with a high response count.
     */
    public function StartDiscovery(): void
    {
        $this->WriteAttributeBoolean('DiscoveryActive', true);
        $this->SetBuffer('Discovery', json_encode([]));
        $this->UpdateFormField('DiscoveryList', 'values', json_encode([]));
        $this->SetTimerInterval('DiscoveryStop', self::DISCOVERY_WINDOW * 1000);
        $this->SendDebug('Discovery', 'started (' . self::DISCOVERY_WINDOW . 's)', 0);
        // Provoke a confirmation so the actor's Melde-ID shows up in the list.
        $this->sendStatusRequest();
    }

    /**
     * Stop the discovery window (also called automatically by the timer).
     */
    public function StopDiscovery(): void
    {
        $this->WriteAttributeBoolean('DiscoveryActive', false);
        $this->SetTimerInterval('DiscoveryStop', 0);
        $this->SendDebug('Discovery', 'stopped', 0);
    }

    /**
     * Copy a discovered sender ID into the Melde-ID field. The user still has to
     * save the configuration to persist it.
     *
     * @param string $hexId Sender ID as uppercase hex string.
     */
    public function AdoptDiscovered(string $hexId): void
    {
        $this->UpdateFormField('MeldeID', 'value', strtoupper(trim($hexId)));
    }

    /**
     * Switch both channels fully on (100 %).
     */
    public function AllOn(): void
    {
        $this->SetBoth(100, 100);
    }

    /**
     * Auto-detect the Melde-ID: ask the actor for its state (status request,
     * which does not change the output) and capture the address it confirms
     * from. The result is written into the form's Melde-ID field; the user still
     * has to save.
     */
    public function DetectMelde(): void
    {
        if ($this->ReadPropertyInteger('DeviceID') <= 0) {
            $this->UpdateFormField('InfoLabel', 'caption', $this->Translate('Bitte zuerst die Geräte-ID setzen.'));
            return;
        }
        $this->WriteAttributeBoolean('DetectActive', true);
        $this->SetTimerInterval('DetectStop', 6000);
        $this->UpdateFormField('InfoLabel', 'caption', $this->Translate('Erkennung läuft … (Status wird abgefragt)'));
        $this->SendDebug('DetectMelde', 'started', 0);
        $this->sendStatusRequest();
    }

    /**
     * Stop the Melde-ID auto-detection (also called by the timer on timeout).
     */
    public function StopDetectMelde(): void
    {
        if ($this->ReadAttributeBoolean('DetectActive')) {
            $this->WriteAttributeBoolean('DetectActive', false);
        }
        $this->SetTimerInterval('DetectStop', 0);
    }

    // =====================================================================
    // Symcon hooks
    // =====================================================================

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'WW':
                $this->SetWW((int) $Value);
                $this->recomputeCct();
                break;
            case 'KW':
                $this->SetKW((int) $Value);
                $this->recomputeCct();
                break;
            case 'Helligkeit':
                $this->applyCct((int) $Value, $this->kelvinToTemp((int) $this->GetValue('Farbtemperatur')));
                break;
            case 'Farbtemperatur':
                // The ~TWColor slider delivers Kelvin.
                $this->applyCct((int) $this->GetValue('Helligkeit'), $this->kelvinToTemp((int) $Value));
                break;
            case 'Status':
                if ((bool) $Value) {
                    // Restore the last non-zero brightness per channel.
                    $ww = $this->ReadAttributeInteger('Last_WW');
                    $kw = $this->ReadAttributeInteger('Last_KW');
                    $this->SetBoth($ww > 0 ? $ww : 100, $kw > 0 ? $kw : 100);
                } else {
                    $this->SwitchOff();
                }
                $this->recomputeCct();
                break;
            default:
                throw new Exception('Invalid action ident: ' . $Ident);
        }
    }

    public function ReceiveData($JSONString)
    {
        if ($this->ReadPropertyBoolean('DebugPromiscuous')) {
            $this->SendDebug('RX promiscuous', $JSONString, 0);
        }

        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return '';
        }

        // 4BS only (field names verified against the native EnOcean modules).
        if ((int) ($data['Device'] ?? -1) !== self::RORG_4BS) {
            return '';
        }
        if (!isset($data['DeviceID'])) {
            return '';
        }

        $sender = (int) $data['DeviceID'];

        // Discovery sees every 4BS telegram so the Melde-ID can be identified.
        $this->maybeRecordDiscovery($sender, $data);

        // Melde-ID auto-detection (button): capture the address the actor confirms from.
        if ($this->ReadAttributeBoolean('DetectActive') && $this->captureMeldeId($sender, $data)) {
            return '';
        }

        $melde = $this->meldeIdValue();
        if ($melde === 0) {
            return '';
        }

        $db1 = (int) ($data['DataByte1'] ?? -1);
        $db3 = (int) ($data['DataByte3'] ?? 0);
        $db2 = (int) ($data['DataByte2'] ?? 0);
        $db0 = (int) ($data['DataByte0'] ?? 0);

        // Format auto-detection.
        // High-resolution: single Melde-ID, channel in DataByte1, 10-bit value.
        // DataByte0 = 0x0E marks a confirmation (a command/echo uses 0x0F).
        if ($sender === $melde && $db0 === 0x0E && ($db1 === self::DB1_WW || $db1 === self::DB1_KW)) {
            $channel = $db1 === self::DB1_WW ? 'WW' : 'KW';
            $level = $this->clamp((int) round((($db3 << 8) | $db2) * 100 / self::VALUE_MAX));
            $this->setDetectedFormat(self::FORMAT_HIRES);
            $this->applyFeedback($channel, $level, $level > 0);
            return '';
        }

        // Percentage: two Melde-IDs (base = WW, base+1 = KW), value in DataByte2,
        // on/off in DataByte0 bit0, DataByte3 = 0x02 marks the dim telegram.
        if (($sender === $melde || $sender === $melde + 1) && $db3 === self::DB3_DIM) {
            $channel = $sender === $melde ? 'WW' : 'KW';
            $on = ($db0 & 0x01) === 0x01;
            $level = $this->clamp($db2);
            $this->setDetectedFormat(self::FORMAT_PERCENT);
            $this->applyFeedback($channel, $on ? $level : 0, $on);
            return '';
        }

        return '';
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $base = $this->getBaseID();
        $offset = $this->ReadPropertyInteger('DeviceID');
        $baseStr = $base === null ? $this->Translate('unknown') : sprintf('0x%08X', $base);
        $senderStr = ($base === null || $offset <= 0) ? '—' : sprintf('0x%08X', $base + $offset);

        $json = json_encode($form);
        $json = str_replace(
            ['{{BASEID}}', '{{SENDER}}', '{{INFO}}'],
            [$baseStr, $senderStr, $this->infoCaption()],
            $json
        );

        return $json;
    }

    // =====================================================================
    // Internal helpers
    // =====================================================================

    /**
     * The single feedback (Melde-) ID; both channels report from it, the channel
     * is carried in DataByte1. Returns 0 when unset/invalid.
     */
    private function meldeIdValue(): int
    {
        $hex = trim($this->ReadPropertyString('MeldeID'));
        if ($hex === '' || !ctype_xdigit($hex)) {
            return 0;
        }
        return (int) hexdec($hex);
    }

    /** Channel selector byte (DataByte1) for a channel. */
    private function channelByte(string $channel): int
    {
        return $channel === 'KW' ? self::DB1_KW : self::DB1_WW;
    }

    /** Human-readable label for a telegram format. */
    private function formatLabel(string $format): string
    {
        if ($format === self::FORMAT_HIRES) {
            return $this->Translate('hochauflösend');
        }
        if ($format === self::FORMAT_PERCENT) {
            return $this->Translate('Prozent');
        }
        return $this->Translate('noch nichts empfangen');
    }

    /** Combined status line for the form (Melde-ID + detected receive format). */
    private function infoCaption(): string
    {
        $melde = $this->meldeIdValue();
        $meldeStr = $melde === 0 ? '—' : sprintf('%08X', $melde);
        return sprintf(
            $this->Translate('Melde-ID: %s   ·   empfangenes Format: %s   (Empfang wird automatisch erkannt)'),
            $meldeStr,
            $this->formatLabel($this->ReadAttributeString('DetectedFormat'))
        );
    }

    /** Remember the auto-detected receive format and reflect it in the form. */
    private function setDetectedFormat(string $format): void
    {
        if ($this->ReadAttributeString('DetectedFormat') === $format) {
            return;
        }
        $this->WriteAttributeString('DetectedFormat', $format);
        $this->UpdateFormField('InfoLabel', 'caption', $this->infoCaption());
        $this->SendDebug('Format', 'detected ' . $format, 0);
    }

    /**
     * During Melde-ID detection, capture the address the actor confirms from.
     * Excludes our own send echo (BaseID + offset) and accepts both formats:
     * hi-res confirm (channel byte + DataByte0 = 0x0E) or a percentage dim
     * telegram (DataByte3 = 0x02, LRN data bit set).
     */
    private function captureMeldeId(int $sender, array $data): bool
    {
        $base = $this->getBaseID();
        $offset = $this->ReadPropertyInteger('DeviceID');
        if ($base !== null && $offset > 0 && ($sender === $base + $offset || $sender === $base + $offset + 1)) {
            return false; // our own echo
        }

        $db1 = (int) ($data['DataByte1'] ?? -1);
        $db0 = (int) ($data['DataByte0'] ?? -1);
        $db3 = (int) ($data['DataByte3'] ?? -1);
        // Actor confirmations: hi-res uses DataByte0 = 0x0E, percent uses 0x09/0x08.
        // Our own send echo uses 0x0F (hi-res) — must NOT be mistaken for percent,
        // hence the exact 0x09/0x08 match (not just "bit 3 set", which 0x0F also has).
        $isHires = ($db1 === self::DB1_WW || $db1 === self::DB1_KW) && $db0 === 0x0E;
        $isPercent = $db3 === self::DB3_DIM && ($db0 === self::DB0_ON || $db0 === self::DB0_OFF);
        if (!$isHires && !$isPercent) {
            return false;
        }

        $hex = strtoupper(str_pad(dechex($sender), 8, '0', STR_PAD_LEFT));
        $this->StopDetectMelde();
        $this->UpdateFormField('MeldeID', 'value', $hex);
        $this->UpdateFormField('InfoLabel', 'caption', sprintf($this->Translate('Erkannt: %s — bitte speichern.'), $hex));
        $this->SendDebug('DetectMelde', 'detected ' . $hex, 0);
        return true;
    }

    /** Remember a channel's last non-zero brightness (for the Status-on restore). */
    private function rememberLast(string $channel, int $value): void
    {
        if ($value > 0) {
            $this->WriteAttributeInteger('Last_' . $channel, $value);
        }
    }

    /**
     * Send a dim command for one channel and optionally reflect the value
     * immediately (status emulation).
     *
     * The FWWKW71L command (free profile 07-3F-7F) is always high-resolution:
     * 10-bit value in DataByte3:DataByte2, channel in DataByte1 (0x10 = WW,
     * 0x11 = KW), DataByte0 = 0x0F (GFVS master). The percentage form only ever
     * appears in the actor's *confirmation*, never in the command.
     */
    private function setChannel(string $channel, int $value): void
    {
        $value = $this->clamp($value);
        $offset = $this->ReadPropertyInteger('DeviceID');
        if ($offset <= 0) {
            $this->SendDebug('TX ' . $channel, 'no Geräte-ID set, ignored', 0);
            return;
        }
        $this->rememberLast($channel, $value);

        $raw = (int) round($value * self::VALUE_MAX / 100);
        $db3 = ($raw >> 8) & 0xFF;   // high 2 bits
        $db2 = $raw & 0xFF;          // low byte
        $db1 = $this->channelByte($channel);
        $this->SendDebug('TX ' . $channel, sprintf('offset=%d pct=%d raw=%d', $offset, $value, $raw), 0);
        $this->sendTelegram($offset, $db3, $db2, $db1, self::DB0_CMD);

        // Status emulation: reflect the command immediately when bidi feedback
        // is not trusted/available; otherwise the variable follows the actor.
        if ($this->ReadPropertyBoolean('EmulateStatus')) {
            $this->SetValue($channel, $value);
            $this->recomputeStatus();
        }
    }

    /**
     * Ask the actor for its current state (DataByte1 = 0x02, "Bestätigungs-
     * telegramm anfordern"). The actor confirms without changing its output —
     * used for Melde-ID detection and for an initial sync.
     */
    private function sendStatusRequest(): void
    {
        $offset = $this->ReadPropertyInteger('DeviceID');
        if ($offset <= 0) {
            return;
        }
        $this->SendDebug('TX status request', 'offset=' . $offset, 0);
        $this->sendTelegram($offset, 0x00, 0x00, self::DB1_STATUS_REQUEST, self::DB0_CMD);
    }

    /**
     * Apply a confirmed channel state reported by the actor.
     */
    private function applyFeedback(string $channel, int $level, bool $on): void
    {
        $value = $on ? $level : 0;
        $this->rememberLast($channel, $value);
        $this->SetValue($channel, $value);
        $this->recomputeStatus();
        $this->recomputeCct();
        $this->SendDebug('RX feedback ' . $channel, sprintf('level=%d on=%d', $level, $on ? 1 : 0), 0);
    }

    /**
     * Assemble the gateway send JSON and hand it to the parent.
     */
    private function sendTelegram(int $offset, int $db3, int $db2, int $db1, int $db0): void
    {
        // The gateway interface requires the full DataByte0..DataByte12 set
        // (verified against the native EnOcean modules' BaseData template).
        $payload = [
            'DataID'        => self::TX_DATAID,
            'Device'        => self::RORG_4BS,
            'Status'        => 0,
            'DeviceID'      => $offset,
            'DestinationID' => -1,
            'DataLength'    => 4,
            'DataByte12'    => 0,
            'DataByte11'    => 0,
            'DataByte10'    => 0,
            'DataByte9'     => 0,
            'DataByte8'     => 0,
            'DataByte7'     => 0,
            'DataByte6'     => 0,
            'DataByte5'     => 0,
            'DataByte4'     => 0,
            'DataByte3'     => $db3,
            'DataByte2'     => $db2,
            'DataByte1'     => $db1,
            'DataByte0'     => $db0,
        ];
        $json = json_encode($payload);
        $this->SendDebug('TX -> Parent', $json, 0);
        $this->SendDataToParent($json);
    }

    /**
     * During an active discovery window, accumulate the sender ID in the buffer
     * and push the updated list into the form.
     */
    private function maybeRecordDiscovery(int $sender, array $data): void
    {
        if (!$this->ReadAttributeBoolean('DiscoveryActive')) {
            return;
        }
        // Hide our own send echo (DataByte0 = 0x0F) so only actor confirmations
        // (0x0E hi-res, 0x09/0x08 percent) show up as Melde-ID candidates.
        if ((int) ($data['DataByte0'] ?? -1) === self::DB0_CMD) {
            return;
        }

        $list = json_decode($this->GetBuffer('Discovery'), true);
        if (!is_array($list)) {
            $list = [];
        }

        $hex = strtoupper(str_pad(dechex($sender), 8, '0', STR_PAD_LEFT));
        $found = false;
        foreach ($list as &$row) {
            if ($row['SenderIDHex'] === $hex) {
                $row['Count']++;
                $row['DB2'] = (int) ($data['DataByte2'] ?? 0);
                $found = true;
                break;
            }
        }
        unset($row);

        if (!$found) {
            $list[] = [
                'SenderIDHex' => $hex,
                'DB2'         => (int) ($data['DataByte2'] ?? 0),
                'Count'       => 1,
            ];
        }

        $this->SetBuffer('Discovery', json_encode($list));
        $this->UpdateFormField('DiscoveryList', 'values', json_encode($list));
        $this->SendDebug('Discovery', 'saw ' . $hex, 0);
    }

    private function recomputeStatus(): void
    {
        $on = $this->GetValue('WW') > 0 || $this->GetValue('KW') > 0;
        $this->SetValue('Status', $on);
    }

    /**
     * Drive both channels from a brightness + colour-temperature pair and reflect
     * the values in the CCT variables.
     *
     * Additive mix (lossless round-trip with recomputeCct):
     *   brightness B = max(WW, KW); temperature T: 0 = warm (WW), 100 = cold (KW).
     *   T <= 50: WW = B,                 KW = B * T / 50
     *   T >  50: KW = B, WW = B * (100 - T) / 50
     */
    private function applyCct(int $brightness, int $temp): void
    {
        $b = $this->clamp($brightness);
        $t = $this->clamp($temp);
        if ($t <= 50) {
            $ww = $b;
            $kw = (int) round($b * $t / 50);
        } else {
            $kw = $b;
            $ww = (int) round($b * (100 - $t) / 50);
        }
        $this->SetWW($ww);
        $this->SetKW($kw);
        $this->SetValue('Helligkeit', $b);
        $this->SetValue('Farbtemperatur', $this->tempToKelvin($t));
    }

    /**
     * Derive the CCT variables from the current WW/KW values (inverse of applyCct).
     * Keeps the last colour temperature while the light is off (B = 0).
     */
    private function recomputeCct(): void
    {
        $ww = (int) $this->GetValue('WW');
        $kw = (int) $this->GetValue('KW');
        $b = max($ww, $kw);
        $this->SetValue('Helligkeit', $b);
        if ($b > 0) {
            $t = $ww >= $kw ? (int) round($kw / $ww * 50) : (int) round(100 - $ww / $kw * 50);
            $this->SetValue('Farbtemperatur', $this->tempToKelvin($t));
        }
    }

    /** Internal temperature (0..100, 0 = warm) -> Kelvin for the ~TWColor slider. */
    private function tempToKelvin(int $t): int
    {
        return self::KELVIN_WARM + (int) round($this->clamp($t) * (self::KELVIN_COLD - self::KELVIN_WARM) / 100);
    }

    /** Kelvin (from the ~TWColor slider) -> internal temperature (0..100). */
    private function kelvinToTemp(int $kelvin): int
    {
        return $this->clamp((int) round(($kelvin - self::KELVIN_WARM) * 100 / (self::KELVIN_COLD - self::KELVIN_WARM)));
    }

    /**
     * Best-effort read of the parent gateway's BaseID for display in the form.
     */
    private function getBaseID(): ?int
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parent === 0) {
            return null;
        }
        foreach (['BaseID', 'BaseAddress', 'Base', 'ChipID'] as $prop) {
            try {
                $value = IPS_GetProperty($parent, $prop);
            } catch (\Throwable $e) {
                continue;
            }
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value)) {
                $clean = ltrim(strtolower($value), '0x');
                return ctype_xdigit($clean) ? (int) hexdec($clean) : (int) $value;
            }
        }
        return null;
    }

    private function clamp(int $value, int $min = 0, int $max = 100): int
    {
        return max($min, min($max, $value));
    }

    private static function moduleId(): string
    {
        return '{BA799264-A70F-4CBF-BB9A-89E6492A22E9}';
    }
}
