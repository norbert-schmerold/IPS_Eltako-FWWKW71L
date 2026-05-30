<?php

declare(strict_types=1);

/**
 * Eltako FWWKW71L – 2-channel PWM dimmer (WW/KW) for LED 12-36V DC.
 *
 * Phase 1: minimal hardware driver. Sends 4BS PWM commands to the actor via the
 * EnOcean gateway and evaluates the actor's confirmation telegrams (the native
 * module fails to do this).
 *
 * Lines marked `VERIFY@SETUP` carry assumptions about the gateway's JSON field
 * names / telegram bytes that MUST be confirmed against a live sniff before they
 * are relied upon. Use the `DebugPromiscuous` property to dump raw RX telegrams.
 */
class EltakoFWWKW71L extends IPSModule
{
    /** EnOcean gateway data interface this module connects to. */
    private const PARENT_GUID = '{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}';
    /** DataID for Device -> Gateway (send). */
    private const TX_DATAID = '{70E3075F-A35D-4DEB-AC20-C929A156FE48}';

    /** RORG 4BS (0xA5). */
    private const RORG_4BS = 165;
    /** DB3 marker for the free-profile data telegram (07-3F-7F). VERIFY@SETUP. */
    private const DB3_DATA = 0x02;
    /** DB0 of an outgoing data telegram (LRN bit set => data, status flag on). VERIFY@SETUP. */
    private const DB0_DATA = 0x09;

    /** Auto-assigned sender offsets start here (existing Eltako devices use 1-47). */
    private const SENDER_OFFSET_BASE = 100;
    /** Bidi feedback is considered fresh for this many seconds. */
    private const BIDI_TIMEOUT = 60;
    /** Discovery collection window in seconds. */
    private const DISCOVERY_WINDOW = 30;

    private const CHANNELS = ['WW', 'KW'];

    public function Create()
    {
        parent::Create();

        // --- Properties ---
        $this->RegisterPropertyString('ReturnID_WW', '');
        $this->RegisterPropertyString('ReturnID_KW', '');
        $this->RegisterPropertyInteger('SenderOffset_WW', 0);
        $this->RegisterPropertyInteger('SenderOffset_KW', 0);
        $this->RegisterPropertyBoolean('DebugPromiscuous', false);

        // --- Attributes (effective, auto-assigned sender offsets / runtime state) ---
        $this->RegisterAttributeInteger('EffectiveOffset_WW', 0);
        $this->RegisterAttributeInteger('EffectiveOffset_KW', 0);
        $this->RegisterAttributeBoolean('DiscoveryActive', false);

        // --- Variables ---
        $this->RegisterVariableInteger('WW', 'Warm White', '~Intensity.100', 10);
        $this->RegisterVariableInteger('KW', 'Cold White', '~Intensity.100', 20);
        $this->RegisterVariableInteger('WW_Actual', 'Warm White (actual)', '~Intensity.100', 11);
        $this->RegisterVariableInteger('KW_Actual', 'Cold White (actual)', '~Intensity.100', 21);
        $this->RegisterVariableBoolean('Status', 'Status', '~Switch', 30);
        $this->RegisterVariableInteger('LastFeedback', 'Last feedback', '~UnixTimestamp', 40);
        $this->RegisterVariableBoolean('BidiStatus', 'Bidi OK', '~Switch', 50);

        $this->EnableAction('WW');
        $this->EnableAction('KW');
        $this->EnableAction('Status');

        // --- Timers ---
        $this->RegisterTimer('BidiCheck', 0, 'EFW_CheckBidi($_IPS[\'TARGET\']);');
        $this->RegisterTimer('DiscoveryStop', 0, 'EFW_StopDiscovery($_IPS[\'TARGET\']);');

        $this->SetBuffer('Discovery', json_encode([]));

        $this->ConnectParent(self::PARENT_GUID);
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->ConnectParent(self::PARENT_GUID);
        $this->autoAssignOffsets();

        // Receive filter: in promiscuous mode receive everything, otherwise only 4BS.
        if ($this->ReadPropertyBoolean('DebugPromiscuous')) {
            $this->SetReceiveDataFilter('.*');
        } else {
            // VERIFY@SETUP: field name/order of "Device" in the gateway's RX JSON.
            $this->SetReceiveDataFilter('.*"Device":' . self::RORG_4BS . '.*');
        }

        $this->SetTimerInterval('BidiCheck', self::BIDI_TIMEOUT * 1000);
        $this->CheckBidi();
    }

    // =====================================================================
    // Public API (prefix EFW_) — kept typed and side-effect-clean so future
    // layers (CCT, scenes, memory, astro, watchdog) can sit on top.
    // =====================================================================

    /**
     * Set the warm-white channel.
     *
     * @param int $value PWM percentage, clamped to 0-100.
     */
    public function SetWW(int $value): void
    {
        $value = $this->clamp($value);
        $this->sendPWM($this->effectiveOffset('WW'), $value);
        $this->SetValue('WW', $value);
        $this->recomputeStatus();
    }

    /**
     * Set the cold-white channel.
     *
     * @param int $value PWM percentage, clamped to 0-100.
     */
    public function SetKW(int $value): void
    {
        $value = $this->clamp($value);
        $this->sendPWM($this->effectiveOffset('KW'), $value);
        $this->SetValue('KW', $value);
        $this->recomputeStatus();
    }

    /**
     * Set both channels in one call.
     *
     * @param int $ww Warm-white PWM percentage (0-100).
     * @param int $kw Cold-white PWM percentage (0-100).
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
     * Send a 4BS teach-in (LRN) telegram for the given channel's sender ID.
     *
     * VERIFY@SETUP: the LRN byte layout below is the standard 4BS variable
     * teach-in for the free profile 07-3F-7F and must be confirmed by sniffing
     * a manual teach-in on the actor before being trusted.
     *
     * @param string $channel 'WW' or 'KW'.
     */
    public function TeachIn(string $channel): void
    {
        $channel = $this->requireChannel($channel);
        $offset = $this->effectiveOffset($channel);

        // Free profile 07-3F-7F => FUNC=0x07, TYPE=0x3F, MANUF=0x7F.
        $func = 0x07;
        $type = 0x3F;
        $manuf = 0x7F;
        $db3 = (($func << 2) | ($type >> 5)) & 0xFF;
        $db2 = (($type << 3) | ($manuf >> 8)) & 0xFF;
        $db1 = $manuf & 0xFF;
        $db0 = 0x80; // LRN type "with EEP+MANUF"; LRN bit (bit3)=0 => teach-in.

        $this->SendDebug(
            'TX TeachIn ' . $channel,
            sprintf('offset=%d DB3..0=%02X %02X %02X %02X', $offset, $db3, $db2, $db1, $db0),
            0
        );
        $this->sendTelegram($offset, $db3, $db2, $db1, $db0);
    }

    /**
     * Start a discovery window: collect every incoming 4BS sender ID for
     * DISCOVERY_WINDOW seconds and surface them in the configuration form.
     */
    public function StartDiscovery(): void
    {
        $this->WriteAttributeBoolean('DiscoveryActive', true);
        $this->SetBuffer('Discovery', json_encode([]));
        $this->UpdateFormField('DiscoveryList', 'values', json_encode([]));
        $this->SetTimerInterval('DiscoveryStop', self::DISCOVERY_WINDOW * 1000);
        $this->SendDebug('Discovery', 'started (' . self::DISCOVERY_WINDOW . 's)', 0);
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
     * Copy a discovered sender ID into the ReturnID field of a channel.
     * The user still has to save the configuration to persist it.
     *
     * @param string $channel 'WW' or 'KW'.
     * @param string $hexId   Sender ID as uppercase hex string.
     */
    public function AdoptDiscovered(string $channel, string $hexId): void
    {
        $channel = $this->requireChannel($channel);
        $this->UpdateFormField('ReturnID_' . $channel, 'value', strtoupper(trim($hexId)));
    }

    /**
     * Run a manual test ramp on one channel (0->100->0). Blocking; intended
     * only for the configuration-form test buttons.
     *
     * @param string $channel 'WW' or 'KW'.
     */
    public function TestRamp(string $channel): void
    {
        $channel = $this->requireChannel($channel);
        $offset = $this->effectiveOffset($channel);
        $this->SendDebug('TestRamp ' . $channel, 'start', 0);
        foreach ([0, 25, 50, 75, 100, 75, 50, 25, 0] as $step) {
            $this->sendPWM($offset, $step);
            IPS_Sleep(400);
        }
        $this->SetValue($channel, 0);
        $this->recomputeStatus();
    }

    /**
     * Re-evaluate the freshness of the last bidi feedback. Timer target.
     */
    public function CheckBidi(): void
    {
        $last = (int) $this->GetValue('LastFeedback');
        $ok = $last > 0 && (time() - $last) < self::BIDI_TIMEOUT;
        $this->SetValue('BidiStatus', $ok);
    }

    // =====================================================================
    // Symcon hooks
    // =====================================================================

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'WW':
                $this->SetWW((int) $Value);
                break;
            case 'KW':
                $this->SetKW((int) $Value);
                break;
            case 'Status':
                if ((bool) $Value) {
                    // No state memory yet (future layer) -> full on.
                    $this->SetBoth(100, 100);
                } else {
                    $this->SwitchOff();
                }
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

        // VERIFY@SETUP: every field name below ('Device', 'DataByte3', 'SenderID',
        // 'DataByte2', 'DataByte0') is an assumption — confirm via promiscuous dump.
        if ((int) ($data['Device'] ?? -1) !== self::RORG_4BS) {
            return '';
        }
        if ((int) ($data['DataByte3'] ?? -1) !== self::DB3_DATA) {
            return '';
        }
        if (!isset($data['SenderID'])) {
            return '';
        }

        $sender = (int) $data['SenderID'];
        $pct = $this->clamp((int) ($data['DataByte2'] ?? 0));
        $db0 = (int) ($data['DataByte0'] ?? 0);

        $this->maybeRecordDiscovery($sender, $data);

        $idWW = $this->returnId('WW');
        $idKW = $this->returnId('KW');

        if ($idWW !== 0 && $sender === $idWW) {
            $this->SetValue('WW_Actual', $pct);
            $this->onFeedback('WW', $pct, $db0);
        } elseif ($idKW !== 0 && $sender === $idKW) {
            $this->SetValue('KW_Actual', $pct);
            $this->onFeedback('KW', $pct, $db0);
        }

        return '';
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $base = $this->getBaseID();
        $baseStr = $base === null ? $this->Translate('unknown') : sprintf('0x%08X', $base);
        $offWW = $this->effectiveOffset('WW');
        $offKW = $this->effectiveOffset('KW');
        $senderWW = $base === null ? '—' : sprintf('0x%08X', $base + $offWW);
        $senderKW = $base === null ? '—' : sprintf('0x%08X', $base + $offKW);

        $last = (int) @$this->GetValue('LastFeedback');
        $lastStr = $last > 0 ? date('Y-m-d H:i:s', $last) : $this->Translate('never');
        $bidiStr = (bool) @$this->GetValue('BidiStatus') ? 'OK' : '—';

        $json = json_encode($form);
        $json = str_replace(
            ['{{BASEID}}', '{{SENDER_WW}}', '{{SENDER_KW}}', '{{OFF_WW}}', '{{OFF_KW}}', '{{LASTFB}}', '{{BIDI}}'],
            [$baseStr, $senderWW, $senderKW, (string) $offWW, (string) $offKW, $lastStr, $bidiStr],
            $json
        );

        return $json;
    }

    // =====================================================================
    // Internal helpers
    // =====================================================================

    /**
     * Build and send a 4BS PWM data telegram.
     *
     * @param int $offset   Sender offset (added to the gateway BaseID).
     * @param int $percent  PWM percentage (0-100).
     * @param int $rampByte DB1 transition/time byte (0x00 = default/instant).
     */
    private function sendPWM(int $offset, int $percent, int $rampByte = 0x00): void
    {
        $percent = $this->clamp($percent);
        $this->SendDebug(
            'TX sendPWM',
            sprintf('offset=%d pct=%d ramp=0x%02X', $offset, $percent, $rampByte),
            0
        );
        // VERIFY@SETUP: DB2 = raw percentage, DB1 = ramp byte, DB0 = 0x09.
        $this->sendTelegram($offset, self::DB3_DATA, $percent, $rampByte, self::DB0_DATA);
    }

    /**
     * Assemble the gateway send JSON and hand it to the parent.
     */
    private function sendTelegram(int $offset, int $db3, int $db2, int $db1, int $db0): void
    {
        // VERIFY@SETUP: full send-JSON field set / names against EltakoShutter BaseData.
        $payload = [
            'DataID'        => self::TX_DATAID,
            'Device'        => self::RORG_4BS,
            'Status'        => 0,
            'DeviceID'      => $offset,
            'DestinationID' => -1,
            'DataLength'    => 4,
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
     * Handle a matched confirmation telegram from the actor.
     *
     * @param string $channel 'WW' or 'KW'.
     * @param int    $pct     Reported PWM percentage.
     * @param int    $db0     Raw DB0 (bit0 = actor on/off flag, VERIFY@SETUP).
     */
    private function onFeedback(string $channel, int $pct, int $db0): void
    {
        $this->SetValue('LastFeedback', time());
        $this->SetValue('BidiStatus', true);
        $this->SetTimerInterval('BidiCheck', self::BIDI_TIMEOUT * 1000);

        // Combined status across both channels avoids per-channel flip-flop.
        // DB0 bit0 is the actor's own on/off flag and is logged for diagnostics.
        $on = $this->GetValue('WW_Actual') > 0 || $this->GetValue('KW_Actual') > 0;
        $this->SetValue('Status', $on);

        $this->SendDebug(
            'RX feedback ' . $channel,
            sprintf('pct=%d db0=0x%02X bit0=%d status=%s', $pct, $db0, $db0 & 0x01, $on ? 'on' : 'off'),
            0
        );
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

    /**
     * Resolve the effective sender offset for a channel.
     *
     * Explicit property (> 0) wins; otherwise the value auto-assigned in
     * ApplyChanges (stored as an attribute) is used.
     */
    private function autoAssignOffsets(): void
    {
        // Explicit offsets configured on any instance of this module.
        $explicit = [];
        foreach (IPS_GetInstanceListByModuleID(self::moduleId()) as $iid) {
            foreach (self::CHANNELS as $ch) {
                $o = (int) IPS_GetProperty($iid, 'SenderOffset_' . $ch);
                if ($o > 0) {
                    $explicit[] = $o;
                }
            }
        }

        // Deterministic, instance-unique base for auto-assigned offsets.
        $instances = IPS_GetInstanceListByModuleID(self::moduleId());
        sort($instances);
        $idx = (int) array_search($this->InstanceID, $instances, true);
        $autoBase = self::SENDER_OFFSET_BASE + $idx * 2;

        $slot = 0;
        foreach (self::CHANNELS as $ch) {
            $prop = $this->ReadPropertyInteger('SenderOffset_' . $ch);
            $attr = $this->ReadAttributeInteger('EffectiveOffset_' . $ch);

            if ($prop > 0) {
                $eff = $prop;
            } elseif ($attr > 0) {
                $eff = $attr;
            } else {
                $eff = $autoBase + $slot;
                while (in_array($eff, $explicit, true)) {
                    $eff += 2;
                }
            }

            if ($attr !== $eff) {
                $this->WriteAttributeInteger('EffectiveOffset_' . $ch, $eff);
            }
            $slot++;
        }
    }

    private function effectiveOffset(string $channel): int
    {
        return $this->ReadAttributeInteger('EffectiveOffset_' . $this->requireChannel($channel));
    }

    /**
     * Read a ReturnID property as an integer (0 when unset/invalid).
     */
    private function returnId(string $channel): int
    {
        $hex = trim($this->ReadPropertyString('ReturnID_' . $channel));
        if ($hex === '' || !ctype_xdigit($hex)) {
            return 0;
        }
        return (int) hexdec($hex);
    }

    private function recomputeStatus(): void
    {
        $on = $this->GetValue('WW') > 0 || $this->GetValue('KW') > 0;
        $this->SetValue('Status', $on);
    }

    /**
     * Best-effort read of the parent gateway's BaseID for display in the form.
     * VERIFY@SETUP: confirm the gateway's actual property name.
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

    private function requireChannel(string $channel): string
    {
        $channel = strtoupper(trim($channel));
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new Exception('Invalid channel: ' . $channel . " (expected 'WW' or 'KW')");
        }
        return $channel;
    }

    private static function moduleId(): string
    {
        return '{BA799264-A70F-4CBF-BB9A-89E6492A22E9}';
    }
}
