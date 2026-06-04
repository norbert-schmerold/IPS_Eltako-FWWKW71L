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

    // --- Feedback addressing (verified against live sniffs, base 0xFFF00980) ---
    // The actor reports from consecutive offsets on its own base ("Haupt-Melde-ID"):
    //   +1 = WW (percent), +2 = KW (percent), +4 = hi-res Master (BOTH channels,
    //   channel in DataByte1). The Master is emitted in every configuration (also
    //   with "Dimmwert in % senden" off), carries both channels and is 10-bit
    //   precise, so it is the preferred feedback source; the percent telegrams are a
    //   fallback for actors that only send the % "Bestätigungstelegramm".
    private const MELDE_OFF_WW = 1;
    private const MELDE_OFF_KW = 2;
    private const MELDE_OFF_MASTER = 4;

    // Percent telegram bytes (used only by Melde-ID detection as a fallback).
    /** DataByte3 marker of a percentage dim telegram. */
    private const DB3_DIM = 0x02;
    /** DataByte0 on/off for the percentage format. */
    private const DB0_ON = 0x09;
    private const DB0_OFF = 0x08;

    /**
     * High-resolution dim value is 10-bit (0..1023), split across two bytes:
     * DataByte3 = high 2 bits, DataByte2 = low byte. 1023 = 100 %.
     */
    private const VALUE_MAX = 1023;
    /** Channel selector in DataByte1 (verified): WW = 0x10, KW = 0x11. */
    private const DB1_WW = 0x10;
    private const DB1_KW = 0x11;
    /** DataByte0 marks a confirmation (0x0E) vs an outgoing command/echo (0x0F). */
    private const DB0_CONFIRM = 0x0E;
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

    /** Melde-ID detection: per-channel probe window in milliseconds (WW then KW). */
    private const DETECT_PHASE_MS = 5000;

    /**
     * Reliability resend. EnOcean is an unacknowledged radio: on a rapid off/on or
     * when something else transmits at the same instant (a motion detector is the
     * classic culprit), one of the two channel telegrams of a SetBoth() can be lost,
     * leaving only WW or only KW lit. After every command we latch the intended level
     * and, a short moment later, verify the actor actually reached it — resending just
     * the channel that did not arrive (see ResendCheck()).
     */
    private const RESEND_DELAY_MS = 700;
    private const RESEND_MAX_TRIES = 2;

    /**
     * Colour-temperature endpoints (Kelvin) for the standard ~TWColor slider used
     * by the native "Licht" tile. T = 0 % maps to warm, T = 100 % to cold.
     */
    private const KELVIN_WARM = 2700;
    private const KELVIN_COLD = 6500;
    /**
     * Slider step for the colour-temperature control (Kelvin). The slider moves in
     * 100 K steps so the neutral midpoint (4600 K) is a natural detent; the variable
     * itself stays a plain integer (any value via script/SetValue).
     */
    private const KELVIN_STEP = 100;

    /**
     * Slider "Verwendung" (USAGE_TYPE) of the modern presentation. The native "Licht"
     * tile assigns roles by this value: the slider tagged Intensität becomes the tile's
     * brightness. Only the overall "Helligkeit" carries USAGE_INTENSITY; the raw WW/KW
     * channels stay USAGE_STANDARD so they are shown as plain detail sliders and never
     * get picked as the tile brightness (which previously latched onto KW because all
     * three shared the legacy "~Intensity.*" profile = Intensität).
     */
    private const USAGE_STANDARD  = 0;
    private const USAGE_INTENSITY = 2;

    public function Create()
    {
        parent::Create();

        // --- Properties (mirror the native module's single-ID form) ---
        $this->RegisterPropertyInteger('DeviceID', 0);     // Geräte-ID (sender offset)
        $this->RegisterPropertyString('MeldeID', '');      // bidi feedback base ID (hex)
        $this->RegisterPropertyBoolean('EmulateStatus', false);
        $this->RegisterPropertyBoolean('DebugPromiscuous', false);

        // --- Runtime state ---
        $this->RegisterAttributeBoolean('DetectActive', false);
        // Active Melde-ID detection phase: '' (idle), 'WW' or 'KW'.
        $this->RegisterAttributeString('DetectPhase', '');
        // Latched once the hi-res Master telegram (Haupt+4) is seen → ignore the
        // redundant percent telegrams from then on (reset on ApplyChanges).
        $this->RegisterAttributeBoolean('MasterSeen', false);
        // Reliability resend: the level last commanded per channel (-1 = none pending)
        // and the remaining resend attempts. Transient command-delivery state only —
        // not colour memory; cleared as soon as the actor confirms the target.
        $this->RegisterAttributeInteger('DesiredWW', -1);
        $this->RegisterAttributeInteger('DesiredKW', -1);
        $this->RegisterAttributeInteger('ResendTries', 0);

        // --- Variables ---
        // CCT comfort controls (compute WW/KW) plus the raw channels (fully adjustable).
        // The presentations match what the native "Licht" tile expects and, crucially,
        // assign the slider roles explicitly via the modern Slider presentation instead
        // of the legacy "~Intensity.*" profile: the tile reads the brightness from the
        // slider tagged Intensität (USAGE_INTENSITY), the colour temperature from the
        // one tagged via the colour-temperature template, and on/off from the Switch.
        //   - Status         : Switch (An/Aus)
        //   - Helligkeit     : Slider, Intensität  → THE tile brightness (overall level)
        //   - Farbtemperatur : Slider, colour-temperature template (KELVIN_WARM..COLD)
        //   - WW / KW        : Slider, Standard     → plain % detail channels, not brightness
        // Previously all three % variables shared "~Intensity.100" (= Intensität), so the
        // tile picked the last one (KW) as its brightness instead of "Helligkeit".
        $this->RegisterVariableBoolean('Status', 'Status', '~Switch', 10);
        $this->RegisterVariableInteger('Helligkeit', 'Helligkeit', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'USAGE_TYPE'   => self::USAGE_INTENSITY,
            'MIN'          => 0,
            'MAX'          => 100,
            'STEP_SIZE'    => 1,
            'DIGITS'       => 0,
            'SUFFIX'       => ' %',
        ], 20);
        $this->RegisterVariableInteger('Farbtemperatur', 'Farbtemperatur', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'TEMPLATE'     => VARIABLE_TEMPLATE_SLIDER_COLOR_TEMPERATURE,
            'MIN'          => self::KELVIN_WARM,
            'MAX'          => self::KELVIN_COLD,
            'STEP_SIZE'    => self::KELVIN_STEP,
            'DIGITS'       => 0,
        ], 30);
        $this->RegisterVariableInteger('WW', 'Warmweiß', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'USAGE_TYPE'   => self::USAGE_STANDARD,
            'MIN'          => 0,
            'MAX'          => 100,
            'STEP_SIZE'    => 1,
            'DIGITS'       => 0,
            'SUFFIX'       => ' %',
        ], 40);
        $this->RegisterVariableInteger('KW', 'Kaltweiß', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'USAGE_TYPE'   => self::USAGE_STANDARD,
            'MIN'          => 0,
            'MAX'          => 100,
            'STEP_SIZE'    => 1,
            'DIGITS'       => 0,
            'SUFFIX'       => ' %',
        ], 50);

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
        $this->RegisterTimer('DetectStop', 0, 'EFW_StopDetectMelde($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ResendCheck', 0, 'EFW_ResendCheck($_IPS[\'TARGET\']);');

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

        // Re-evaluate the feedback format after a (re)configuration: prefer the
        // hi-res Master again, fall back to percent until a Master is seen.
        $this->WriteAttributeBoolean('MasterSeen', false);

        // Drop any pending reliability resend from a previous configuration.
        $this->stopResend();

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
     * On the actor, set the UPPER rotary switch to the GFVS teach function —
     * position 9 ("GFVS/FFD mit hochauflösenden Dimmwerten") or 10 ("Drehtaster
     * und GFVS", which also enables the confirmation telegram) — and the MIDDLE
     * rotary to LRN, then press this. One teach-in covers both channels (the
     * actor distinguishes WW/KW via DataByte1).
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
     * Pick the lowest free Geräte-ID (sender offset) across *all* EnOcean device
     * instances on the same gateway — not just this module — mirroring the native
     * Symcon / MoreEnoceanFeatures "nächste freie Sende-ID" search.
     *
     * Every EnOcean sending device (native, MEF and this module) stores its sender
     * offset in the integer property "DeviceID", so we collect those from all
     * device instances (type 3) sharing our gateway (same ConnectionID) and return
     * the lowest unused offset in 1..127 (the EnOcean BaseID's 128-address range,
     * offset 0 = BaseID itself).
     */
    public function PickFreeDeviceID(): void
    {
        $gateway = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];

        $used = [];
        if ($gateway > 0) {
            foreach (IPS_GetInstanceList() as $iid) {
                if ((int) IPS_GetInstance($iid)['ConnectionID'] !== $gateway) {
                    continue; // not a device on our gateway
                }
                $config = json_decode(IPS_GetConfiguration($iid), true);
                if (is_array($config) && isset($config['DeviceID']) && is_int($config['DeviceID']) && $config['DeviceID'] > 0) {
                    $used[$config['DeviceID']] = true;
                }
            }
        }

        $n = 1;
        while ($n <= 127 && isset($used[$n])) {
            $n++;
        }
        if ($n > 127) {
            $this->UpdateFormField('InfoLabel', 'caption', $this->Translate('Keine freie Geräte-ID (1–127) am Gateway verfügbar.'));
            return;
        }
        $this->UpdateFormField('DeviceID', 'value', $n);
    }

    /**
     * Switch both channels fully on (WW 100 % + KW 100 %) = neutral white at full
     * brightness. The easy "back to normal light" action.
     */
    public function AllOn(): void
    {
        $this->SetBoth(100, 100);
    }

    /**
     * Auto-detect the Melde-ID by actively driving each channel and recording the
     * address the actor confirms from — no manual button toggling needed.
     *
     * The module drives WW (a 0→100 toggle, guaranteeing a state change), waits a
     * short window, then drives KW. Because *we* triggered each channel, the
     * address that answers is unambiguously that channel's confirmation address —
     * even in percent mode, where the channel is encoded only in the address
     * (warmweiß = Base ID+1, kaltweiß = Base ID+2). The actor's hi-res Master
     * telegram (Base ID+4, DataByte1 = 0x09) matches no channel confirmation and
     * is ignored. StopDetectMelde() runs the two-phase state machine; finishDetect()
     * maps the captured addresses to the WW base.
     */
    public function DetectMelde(): void
    {
        if ($this->ReadPropertyInteger('DeviceID') <= 0) {
            $this->UpdateFormField('InfoLabel', 'caption', $this->Translate('Bitte zuerst die Geräte-ID setzen.'));
            return;
        }
        if (!$this->HasActiveParent()) {
            $this->UpdateFormField('InfoLabel', 'caption', $this->Translate('Gateway nicht verbunden – bitte zuerst verbinden.'));
            return;
        }
        $this->SetBuffer('DetectWW', json_encode([]));
        $this->SetBuffer('DetectKW', json_encode([]));
        $this->WriteAttributeBoolean('DetectActive', true);
        $this->WriteAttributeString('DetectPhase', 'WW');
        $this->UpdateFormField('InfoLabel', 'caption', $this->Translate('Erkennung läuft – Warmweiß wird angesteuert …'));
        $this->SendDebug('DetectMelde', 'phase WW: probing', 0);
        $this->probeChannel('WW');
        $this->SetTimerInterval('DetectStop', self::DETECT_PHASE_MS);
    }

    /**
     * Two-phase detection state machine (driven by the DetectStop timer): after the
     * WW probe window drive KW, after the KW window evaluate and write the Melde-ID
     * via finishDetect(). A stray call while idle just clears the timer.
     */
    public function StopDetectMelde(): void
    {
        if (!$this->ReadAttributeBoolean('DetectActive')) {
            $this->SetTimerInterval('DetectStop', 0);
            return;
        }

        if ($this->ReadAttributeString('DetectPhase') === 'WW') {
            $this->WriteAttributeString('DetectPhase', 'KW');
            $this->UpdateFormField('InfoLabel', 'caption', $this->Translate('Erkennung läuft – Kaltweiß wird angesteuert …'));
            $this->SendDebug('DetectMelde', 'phase KW: probing', 0);
            $this->probeChannel('KW');
            $this->SetTimerInterval('DetectStop', self::DETECT_PHASE_MS);
            return;
        }

        $this->WriteAttributeBoolean('DetectActive', false);
        $this->WriteAttributeString('DetectPhase', '');
        $this->SetTimerInterval('DetectStop', 0);
        $this->finishDetect();
    }

    /**
     * Reliability resend (driven by the ResendCheck timer): compare each channel's
     * commanded target against the level the actor actually confirmed and resend the
     * channel(s) that did not arrive. This catches the "only WW or only KW came on"
     * failure from a dropped channel telegram during rapid off/on or RF congestion
     * (e.g. a motion detector transmitting at the same instant).
     *
     * The target is always the *latest* command, so a quick off→on (or on→off) simply
     * overrides the previous target — the resend can never revive a superseded state.
     * With trusted feedback (EmulateStatus off) only the missing channel is resent;
     * without it (EmulateStatus on) the resend repeats blindly for its retry budget.
     */
    public function ResendCheck(): void
    {
        $tries = $this->ReadAttributeInteger('ResendTries');
        if ($tries <= 0) {
            $this->stopResend();
            return;
        }

        $resent = false;
        foreach (['WW', 'KW'] as $channel) {
            $desired = $this->ReadAttributeInteger($channel === 'KW' ? 'DesiredKW' : 'DesiredWW');
            if ($desired < 0) {
                continue; // no pending command for this channel
            }
            if ($this->confirmedLevel($channel) === $desired) {
                continue; // actor already at the target
            }
            $this->txChannel($channel, $desired);
            $resent = true;
            $this->SendDebug('Resend ' . $channel, sprintf('desired=%d tries=%d', $desired, $tries), 0);
        }

        if (!$resent) {
            $this->stopResend(); // every pending channel confirmed at its target
            return;
        }

        $this->WriteAttributeInteger('ResendTries', $tries - 1);
        $this->SetTimerInterval('ResendCheck', self::RESEND_DELAY_MS);
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
                // The module keeps no colour memory: while off the colour is reset to
                // neutral, so brightness from off comes on as neutral white. While on,
                // the current colour temperature is used.
                $this->applyCct((int) $Value, $this->kelvinToTemp((int) $this->GetValue('Farbtemperatur')));
                break;
            case 'Farbtemperatur':
                // The ~TWColor slider delivers Kelvin (slider steps in KELVIN_STEP).
                $this->applyCct((int) $this->GetValue('Helligkeit'), $this->kelvinToTemp((int) $Value));
                break;
            case 'Status':
                if ((bool) $Value) {
                    $this->SetBoth(100, 100);          // always on as neutral white, full
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

        // Melde-ID auto-detection (button): collect candidate confirmation addresses.
        if ($this->ReadAttributeBoolean('DetectActive')) {
            $this->recordDetectCandidate($sender, $data);
        }

        $haupt = $this->meldeIdValue();
        if ($haupt === 0) {
            return '';
        }

        $db1 = (int) ($data['DataByte1'] ?? -1);
        $db3 = (int) ($data['DataByte3'] ?? 0);
        $db2 = (int) ($data['DataByte2'] ?? 0);
        $db0 = (int) ($data['DataByte0'] ?? 0);

        // Preferred: hi-res Master (Haupt + 4) — both channels report here, the channel
        // is in DataByte1 (0x10 = WW, 0x11 = KW), the 10-bit value in DataByte3:DataByte2,
        // DataByte0 = 0x0E marks the confirmation (a command/echo uses 0x0F). Present
        // whenever the actor sends the "Dimmerwerttelegramm" and 10-bit precise, so once
        // we see it we latch onto it and ignore the redundant percent telegrams.
        if ($sender === $haupt + self::MELDE_OFF_MASTER && $db0 === self::DB0_CONFIRM
            && ($db1 === self::DB1_WW || $db1 === self::DB1_KW)) {
            if (!$this->ReadAttributeBoolean('MasterSeen')) {
                $this->WriteAttributeBoolean('MasterSeen', true);
            }
            $channel = $db1 === self::DB1_WW ? 'WW' : 'KW';
            $level = $this->clamp((int) round((($db3 << 8) | $db2) * 100 / self::VALUE_MAX));
            $this->applyFeedback($channel, $level, $level > 0);
            return '';
        }

        // Fallback: percent per channel (WW = Haupt + 1, KW = Haupt + 2), value in
        // DataByte2 (0..100 %), on/off in DataByte0 bit 0, DataByte3 = 0x02. Used only
        // until a Master telegram is seen, so an actor that only sends the percent
        // "Bestätigungstelegramm" still reports — without a double-update once a Master
        // arrives.
        if (!$this->ReadAttributeBoolean('MasterSeen') && $db3 === self::DB3_DIM
            && ($sender === $haupt + self::MELDE_OFF_WW || $sender === $haupt + self::MELDE_OFF_KW)) {
            $channel = $sender === $haupt + self::MELDE_OFF_WW ? 'WW' : 'KW';
            $on = ($db0 & 0x01) === 0x01;
            $level = $this->clamp($db2);
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
     * The Haupt-/base Melde-ID (the actor's feedback base). The hi-res Master at
     * Haupt + 4 carries both channels (channel in DataByte1); the percent telegrams
     * sit at Haupt + 1 (WW) / Haupt + 2 (KW). Returns 0 when unset/invalid.
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

    /**
     * Status line for the form: the Haupt-Melde-ID and the addresses derived from it
     * (Master = +4, WW = +1, KW = +2), so it can be cross-checked against a sniff.
     */
    private function infoCaption(): string
    {
        $haupt = $this->meldeIdValue();
        if ($haupt === 0) {
            return $this->Translate('Melde-ID (Haupt): —');
        }
        return sprintf(
            $this->Translate('Melde-ID (Haupt): %08X   ·   Master: %08X   ·   WW: %08X   ·   KW: %08X'),
            $haupt,
            $haupt + self::MELDE_OFF_MASTER,
            $haupt + self::MELDE_OFF_WW,
            $haupt + self::MELDE_OFF_KW
        );
    }

    /**
     * During Melde-ID detection, record the address the actor confirms from into
     * the current phase's buffer (DetectWW or DetectKW). Excludes our own send echo
     * (BaseID + offset) and accepts both confirmation formats: hi-res (channel byte
     * + DataByte0 = 0x0E) or percent (DataByte3 = 0x02 + DataByte0 = 0x09/0x08).
     * finishDetect() then derives the Haupt base from the answering addresses
     * (Master = sender − 4, WW = sender − 1, KW = sender − 2).
     */
    private function recordDetectCandidate(int $sender, array $data): void
    {
        $base = $this->getBaseID();
        $offset = $this->ReadPropertyInteger('DeviceID');
        if ($base !== null && $offset > 0 && $sender === $base + $offset) {
            return; // our own echo
        }

        $db1 = (int) ($data['DataByte1'] ?? -1);
        $db0 = (int) ($data['DataByte0'] ?? -1);
        $db3 = (int) ($data['DataByte3'] ?? -1);
        $isHires = ($db1 === self::DB1_WW || $db1 === self::DB1_KW) && $db0 === self::DB0_CONFIRM;
        $isPercent = $db3 === self::DB3_DIM && ($db0 === self::DB0_ON || $db0 === self::DB0_OFF);
        if (!$isHires && !$isPercent) {
            return;
        }

        $key = $this->ReadAttributeString('DetectPhase') === 'KW' ? 'DetectKW' : 'DetectWW';
        $list = json_decode($this->GetBuffer($key), true);
        if (!is_array($list)) {
            $list = [];
        }
        foreach ($list as $c) {
            if ((int) $c['s'] === $sender) {
                return; // already recorded this phase
            }
        }
        $list[] = ['s' => $sender, 'h' => $isHires];
        $this->SetBuffer($key, json_encode($list));
        $this->SendDebug('DetectMelde', $key . ' candidate ' . strtoupper(str_pad(dechex($sender), 8, '0', STR_PAD_LEFT)) . ($isHires ? ' (hires)' : ' (percent)'), 0);
    }

    /**
     * Drive one channel with a 0→100 toggle so the actor emits its confirmation for
     * that channel regardless of the prior level. Side-effect-free (no variable or
     * Last_* updates) — purely a stimulus for Melde-ID detection.
     */
    private function probeChannel(string $channel): void
    {
        $this->txChannel($channel, 0);
        $this->txChannel($channel, 100);
    }

    /**
     * Evaluate the two probe windows and write the Haupt-Melde-ID (the feedback base).
     *
     * During each probe the actor answers from the hi-res Master (Haupt + 4, channel
     * in DataByte1) and — if "% senden" is on — from the percent channel address
     * (WW = Haupt + 1, KW = Haupt + 2). The Master is preferred (always present, both
     * channels); the base is the answering address minus its known offset, so there
     * is no "lowest ID" guesswork and no off-by-one.
     */
    private function finishDetect(): void
    {
        $ww = json_decode($this->GetBuffer('DetectWW'), true);
        $kw = json_decode($this->GetBuffer('DetectKW'), true);
        $ww = is_array($ww) ? $ww : [];
        $kw = is_array($kw) ? $kw : [];

        $master = [];
        $wwPercent = [];
        $kwPercent = [];
        foreach ($ww as $c) {
            if (!empty($c['h'])) {
                $master[] = (int) $c['s'];
            } else {
                $wwPercent[] = (int) $c['s'];
            }
        }
        foreach ($kw as $c) {
            if (!empty($c['h'])) {
                $master[] = (int) $c['s'];
            } else {
                $kwPercent[] = (int) $c['s'];
            }
        }

        $haupt = null;
        if (count($master) > 0) {
            sort($master);
            $haupt = $master[0] - self::MELDE_OFF_MASTER;
        } elseif (count($wwPercent) > 0) {
            sort($wwPercent);
            $haupt = $wwPercent[0] - self::MELDE_OFF_WW;
        } elseif (count($kwPercent) > 0) {
            sort($kwPercent);
            $haupt = $kwPercent[0] - self::MELDE_OFF_KW;
        }

        if ($haupt === null || $haupt <= 0) {
            $this->UpdateFormField('InfoLabel', 'caption', $this->Translate('Keine Rückmeldung erkannt. Aktor eingelernt und Dimmerwerttelegramm aktiv (PCT14)? Sonst Melde-ID manuell eintragen.'));
            $this->SendDebug('DetectMelde', 'no candidates', 0);
            return;
        }

        $hex = strtoupper(str_pad(dechex($haupt), 8, '0', STR_PAD_LEFT));
        $masterHex = strtoupper(str_pad(dechex($haupt + self::MELDE_OFF_MASTER), 8, '0', STR_PAD_LEFT));
        $this->UpdateFormField('MeldeID', 'value', $hex);
        $this->UpdateFormField('InfoLabel', 'caption', sprintf($this->Translate('Erkannt – Haupt-Melde-ID %s (Master %s). Bitte speichern.'), $hex, $masterHex));
        $this->SendDebug('DetectMelde', 'picked Haupt ' . $hex, 0);
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
        if ($this->ReadPropertyInteger('DeviceID') <= 0) {
            $this->SendDebug('TX ' . $channel, 'no Geräte-ID set, ignored', 0);
            return;
        }
        $this->txChannel($channel, $value);

        // Latch the target and arm the reliability resend so a dropped channel
        // telegram (rapid off/on, motion-detector RF collision) is corrected.
        $this->WriteAttributeInteger($channel === 'KW' ? 'DesiredKW' : 'DesiredWW', $value);
        $this->armResend();

        // Status emulation: reflect the command immediately when bidi feedback
        // is not trusted/available; otherwise the variable follows the actor.
        if ($this->ReadPropertyBoolean('EmulateStatus')) {
            $this->SetValue($channel, $value);
            $this->recomputeStatus();
        }
    }

    /**
     * Encode and send a hi-res dim command for one channel — the wire-level part of
     * setChannel(), shared with the detection probe. No variable side effects.
     */
    private function txChannel(string $channel, int $value): void
    {
        $value = $this->clamp($value);
        $offset = $this->ReadPropertyInteger('DeviceID');
        if ($offset <= 0) {
            return;
        }
        $raw = (int) round($value * self::VALUE_MAX / 100);
        $db3 = ($raw >> 8) & 0xFF;   // high 2 bits
        $db2 = $raw & 0xFF;          // low byte
        $db1 = $this->channelByte($channel);
        $this->SendDebug('TX ' . $channel, sprintf('offset=%d pct=%d raw=%d', $offset, $value, $raw), 0);
        $this->sendTelegram($offset, $db3, $db2, $db1, self::DB0_CMD);
    }

    /** Arm (or re-arm) the reliability resend with a fresh retry budget. */
    private function armResend(): void
    {
        $this->WriteAttributeInteger('ResendTries', self::RESEND_MAX_TRIES);
        $this->SetTimerInterval('ResendCheck', self::RESEND_DELAY_MS);
    }

    /** Clear the resend state — target reached, superseded, or retries exhausted. */
    private function stopResend(): void
    {
        $this->SetTimerInterval('ResendCheck', 0);
        $this->WriteAttributeInteger('ResendTries', 0);
        $this->WriteAttributeInteger('DesiredWW', -1);
        $this->WriteAttributeInteger('DesiredKW', -1);
    }

    /**
     * The level the actor has confirmed for a channel, used as ground truth for the
     * reliability resend. With bidi feedback (the normal mode) the channel variable
     * holds the actor's last confirmation. In status-emulation mode there is no
     * independent confirmation — the variable just echoes the command — so report -1
     * ("unknown"), which makes ResendCheck() repeat blindly for its retry budget.
     */
    private function confirmedLevel(string $channel): int
    {
        if ($this->ReadPropertyBoolean('EmulateStatus')) {
            return -1;
        }
        return (int) $this->GetValue($channel);
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
     * Apply a confirmed channel state reported by the actor. The module keeps no
     * memory of the state — it simply reflects what the actor reports.
     */
    private function applyFeedback(string $channel, int $level, bool $on): void
    {
        $value = $on ? $level : 0;
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
        // No colour memory: while off the colour is shown as neutral white, so the
        // next switch-on comes on neutral.
        $this->SetValue('Farbtemperatur', $this->tempToKelvin($b > 0 ? $t : 50));
    }

    /**
     * Derive the CCT variables from the current WW/KW values (inverse of applyCct).
     * While off (B = 0) the colour resets to neutral white — the module keeps no
     * colour memory; an external CCT/circadian module can drive the colour when on.
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
        } else {
            $this->SetValue('Farbtemperatur', $this->tempToKelvin(50));
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
}
