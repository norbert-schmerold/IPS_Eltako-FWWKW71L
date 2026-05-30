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
 * Telegram format verified against a live sniff of the native module
 * (EEP 07-3F-7F, 4BS, high resolution): Device=165, DataLength=4,
 *   DataByte3 = high 2 bits of a 10-bit value (0..1023)
 *   DataByte2 = low byte of that value          (1023 = 100 %)
 *   DataByte1 = channel: 0x10 = WW, 0x11 = KW
 *   DataByte0 = 0x0F (command); the actor confirms with 0x0E
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
    /**
     * High-resolution dim value is 10-bit (0..1023), split across two bytes:
     * DataByte3 = high 2 bits, DataByte2 = low byte. 1023 = 100 %.
     */
    private const VALUE_MAX = 1023;
    /** Channel selector in DataByte1 (verified): WW = 0x10, KW = 0x11. */
    private const DB1_WW = 0x10;
    private const DB1_KW = 0x11;
    /** DataByte0 of an outgoing command (the actor confirms with 0x0E). */
    private const DB0_CMD = 0x0F;

    /**
     * 4BS teach-in telegram (standard Eltako variable teach for dim actors).
     * VERIFY@SETUP: confirm against a real FWWKW71L teach-in sniff.
     */
    private const TEACH_DB3 = 0xE0;
    private const TEACH_DB2 = 0x40;
    private const TEACH_DB1 = 0x0D;
    private const TEACH_DB0 = 0x80;

    /** Auto-assigned "Wähle freie Geräte-ID" starts here (existing Eltako 1-47). */
    private const SENDER_OFFSET_BASE = 100;
    /** Discovery collection window in seconds. */
    private const DISCOVERY_WINDOW = 30;

    private const CHANNELS = ['WW', 'KW'];

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

        // --- Variables ---
        $this->RegisterVariableInteger('WW', 'Warmweiß', '~Intensity.100', 10);
        $this->RegisterVariableInteger('KW', 'Kaltweiß', '~Intensity.100', 20);
        $this->RegisterVariableBoolean('Status', 'Status', '~Switch', 30);

        $this->EnableAction('WW');
        $this->EnableAction('KW');
        $this->EnableAction('Status');

        // --- Timers ---
        $this->RegisterTimer('DiscoveryStop', 0, 'EFW_StopDiscovery($_IPS[\'TARGET\']);');

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
     * Send the teach-in (LRN) telegram. ONE teach-in covers both channels: the
     * actor learns the base sender ID and maps WW=offset, KW=offset+1 itself.
     */
    public function TeachIn(): void
    {
        $offset = $this->ReadPropertyInteger('DeviceID');
        if ($offset <= 0) {
            echo $this->Translate('Please set a Geräte-ID first.');
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
        $used = [];
        foreach (IPS_GetInstanceListByModuleID(self::moduleId()) as $iid) {
            if ($iid === $this->InstanceID) {
                continue;
            }
            $d = (int) IPS_GetProperty($iid, 'DeviceID');
            if ($d > 0) {
                $used[$d] = true;
                $used[$d + 1] = true;
            }
        }
        $n = self::SENDER_OFFSET_BASE;
        while (isset($used[$n]) || isset($used[$n + 1])) {
            $n += 2;
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

        // Only the actor's own Melde-ID carries our channel state.
        $melde = $this->meldeIdValue();
        if ($melde === 0 || $sender !== $melde) {
            return '';
        }

        // Channel from DataByte1 (0x10 = WW, 0x11 = KW); ignore anything else.
        $db1 = (int) ($data['DataByte1'] ?? -1);
        if ($db1 === self::DB1_WW) {
            $channel = 'WW';
        } elseif ($db1 === self::DB1_KW) {
            $channel = 'KW';
        } else {
            return '';
        }

        // 10-bit value (DataByte3 high bits, DataByte2 low byte) -> 0..100 %.
        $raw = (((int) ($data['DataByte3'] ?? 0)) << 8) | ((int) ($data['DataByte2'] ?? 0));
        $level = $this->clamp((int) round($raw * 100 / self::VALUE_MAX));
        $this->applyFeedback($channel, $level, $level > 0);

        return '';
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $base = $this->getBaseID();
        $offset = $this->ReadPropertyInteger('DeviceID');
        $baseStr = $base === null ? $this->Translate('unknown') : sprintf('0x%08X', $base);
        $senderStr = ($base === null || $offset <= 0) ? '—' : sprintf('0x%08X', $base + $offset);

        $melde = $this->meldeIdValue();
        $meldeStr = $melde === 0 ? '—' : sprintf('%08X', $melde);

        $json = json_encode($form);
        $json = str_replace(
            ['{{BASEID}}', '{{SENDER}}', '{{MELDE}}'],
            [$baseStr, $senderStr, $meldeStr],
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

    /**
     * Send a dim command for one channel and (optionally) update the variable.
     * Both channels use the same sender offset (Geräte-ID); the channel is
     * encoded in DataByte1. The 0..100 % value is scaled to the 10-bit telegram.
     */
    private function setChannel(string $channel, int $value): void
    {
        $value = $this->clamp($value);
        $offset = $this->ReadPropertyInteger('DeviceID');
        if ($offset <= 0) {
            $this->SendDebug('TX ' . $channel, 'no Geräte-ID set, ignored', 0);
            return;
        }

        $raw = (int) round($value * self::VALUE_MAX / 100);
        $db3 = ($raw >> 8) & 0xFF;   // high 2 bits
        $db2 = $raw & 0xFF;          // low byte
        $db1 = $this->channelByte($channel);

        $this->SendDebug(
            'TX ' . $channel,
            sprintf('offset=%d pct=%d raw=%d DB3..0=%02X %02X %02X %02X', $offset, $value, $raw, $db3, $db2, $db1, self::DB0_CMD),
            0
        );
        $this->sendTelegram($offset, $db3, $db2, $db1, self::DB0_CMD);

        // Status emulation: reflect the command immediately when bidi feedback
        // is not trusted/available; otherwise the variable follows the actor.
        if ($this->ReadPropertyBoolean('EmulateStatus')) {
            $this->SetValue($channel, $value);
            $this->recomputeStatus();
        }
    }

    /**
     * Apply a confirmed channel state reported by the actor.
     */
    private function applyFeedback(string $channel, int $level, bool $on): void
    {
        $value = $on ? $level : 0;
        $this->SetValue($channel, $value);
        $this->recomputeStatus();
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
