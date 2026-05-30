<?php

declare(strict_types=1);

/**
 * Eltako FWWKW71L – 2-channel PWM dimmer (WW/KW) for LED 12-36V DC.
 *
 * Replacement driver for the native Symcon module, which fails to evaluate the
 * actor's confirmation telegrams. Mirrors the native module's single-ID layout:
 *
 *   - ONE "Geräte-ID" (sender offset on the gateway BaseID). Channel WW uses the
 *     offset, KW uses offset + 1.
 *   - ONE "Melde-ID" (the actor's bidirectional feedback address). WW reports
 *     from the Melde-ID, KW from Melde-ID + 1.
 *   - ONE teach-in for both channels.
 *
 * Telegram format verified against a live sniff of the native module
 * (EEP 07-3F-7F, 4BS): Device=165, DataLength=4,
 *   DataByte3 = 0x02 (dim telegram)
 *   DataByte2 = level 0..100 (%)
 *   DataByte1 = 0x00 (internal dim speed)
 *   DataByte0 = 0x09 (on) / 0x08 (off)   [bit0 = on/off, bit3 = LRN data bit]
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
    /** DataByte3 of a dim data/feedback telegram (verified). */
    private const DB3_DIM = 0x02;
    /** DataByte0 flags: bit3 = LRN data bit, bit0 = on/off (verified). */
    private const DB0_ON = 0x09;
    private const DB0_OFF = 0x08;

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

        // Only dim/feedback telegrams (DataByte3 = 0x02) carry a channel state.
        if ((int) ($data['DataByte3'] ?? -1) !== self::DB3_DIM) {
            return '';
        }

        $level = $this->clamp((int) ($data['DataByte2'] ?? 0));
        $on = ((int) ($data['DataByte0'] ?? 0) & 0x01) === 0x01;

        $idWW = $this->meldeId('WW');
        $idKW = $this->meldeId('KW');

        if ($idWW !== 0 && $sender === $idWW) {
            $this->applyFeedback('WW', $level, $on);
        } elseif ($idKW !== 0 && $sender === $idKW) {
            $this->applyFeedback('KW', $level, $on);
        }

        return '';
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $base = $this->getBaseID();
        $offset = $this->ReadPropertyInteger('DeviceID');
        $baseStr = $base === null ? $this->Translate('unknown') : sprintf('0x%08X', $base);
        $senderWW = ($base === null || $offset <= 0) ? '—' : sprintf('0x%08X', $base + $offset);
        $senderKW = ($base === null || $offset <= 0) ? '—' : sprintf('0x%08X', $base + $offset + 1);

        $meldeWW = $this->meldeId('WW');
        $meldeKW = $this->meldeId('KW');
        $meldeWWStr = $meldeWW === 0 ? '—' : sprintf('%08X', $meldeWW);
        $meldeKWStr = $meldeKW === 0 ? '—' : sprintf('%08X', $meldeKW);

        $json = json_encode($form);
        $json = str_replace(
            ['{{BASEID}}', '{{SENDER_WW}}', '{{SENDER_KW}}', '{{MELDE_WW}}', '{{MELDE_KW}}'],
            [$baseStr, $senderWW, $senderKW, $meldeWWStr, $meldeKWStr],
            $json
        );

        return $json;
    }

    // =====================================================================
    // Internal helpers
    // =====================================================================

    /**
     * Resolve a channel's sender offset: WW = Geräte-ID, KW = Geräte-ID + 1.
     */
    private function offset(string $channel): int
    {
        $base = $this->ReadPropertyInteger('DeviceID');
        return $channel === 'KW' ? $base + 1 : $base;
    }

    /**
     * Resolve a channel's feedback (Melde-) ID: WW = Melde-ID, KW = Melde-ID + 1.
     * Returns 0 when the Melde-ID is unset/invalid.
     */
    private function meldeId(string $channel): int
    {
        $hex = trim($this->ReadPropertyString('MeldeID'));
        if ($hex === '' || !ctype_xdigit($hex)) {
            return 0;
        }
        $base = (int) hexdec($hex);
        return $channel === 'KW' ? $base + 1 : $base;
    }

    /**
     * Send a dim command for one channel and (optionally) update the variable.
     */
    private function setChannel(string $channel, int $value): void
    {
        $value = $this->clamp($value);
        $offset = $this->offset($channel);
        if ($offset <= 0) {
            $this->SendDebug('TX ' . $channel, 'no Geräte-ID set, ignored', 0);
            return;
        }

        $db0 = $value > 0 ? self::DB0_ON : self::DB0_OFF;
        $this->SendDebug('TX ' . $channel, sprintf('offset=%d level=%d db0=0x%02X', $offset, $value, $db0), 0);
        // DataByte3=0x02, DataByte2=level, DataByte1=0 (internal speed), DataByte0=on/off.
        $this->sendTelegram($offset, self::DB3_DIM, $value, 0x00, $db0);

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
