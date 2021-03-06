<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';  // globale Funktionen
require_once __DIR__ . '/../libs/local.php';   // lokale Funktionen

class Syslog extends IPSModule
{
    use SyslogCommonLib;
    use SyslogLocalLib;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('server', '');
        $this->RegisterPropertyInteger('port', '514');
        $this->RegisterPropertyString('default_severity', 'info');
        if (defined('LOG_LOCAL0')) {
            $this->RegisterPropertyString('default_facility', 'local0');
        } else {
            $this->RegisterPropertyString('default_facility', 'user');
        }
        $this->RegisterPropertyString('default_program', 'ipsymcon');

        $this->RegisterPropertyInteger('update_interval', '0');

        $msgtypes = [
            ['msgtype' => KL_MESSAGE, 'title' => 'MESSAGE', 'active' => true],
            ['msgtype' => KL_SUCCESS, 'title' => 'SUCCESS', 'active' => true],
            ['msgtype' => KL_NOTIFY, 'title' => 'NOTIFY', 'active' => true],
            ['msgtype' => KL_WARNING, 'title' => 'WARNING', 'active' => true],
            ['msgtype' => KL_ERROR, 'title' => 'ERROR', 'active' => true],
            ['msgtype' => KL_DEBUG, 'title' => 'DEBUG', 'active' => false],
            ['msgtype' => KL_CUSTOM, 'title' => 'CUSTOM', 'active' => true],
        ];
        $this->RegisterPropertyString('msgtypes', json_encode($msgtypes));
        $exclude_filters = [
            ['field' => 'Sender', 'expression' => 'VariableManager'],
        ];
        $this->RegisterPropertyString('exclude_filters', json_encode($exclude_filters));

        $this->RegisterPropertyBoolean('with_tstamp_vars', false);

        $this->RegisterTimer('CheckMessages', 0, 'Syslog_CheckMessages(' . $this->InstanceID . ');');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $with_tstamp_vars = $this->ReadPropertyBoolean('with_tstamp_vars');

        $vpos = 0;

        $this->MaintainVariable('LastMessage', $this->Translate('Timestamp of last message'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $with_tstamp_vars);
        $this->MaintainVariable('LastCycle', $this->Translate('Last cycle'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $with_tstamp_vars);

        $syslog_server = $this->ReadPropertyString('server');
        $syslog_port = $this->ReadPropertyInteger('port');
        $default_severity = $this->ReadPropertyString('default_severity');
        $default_facility = $this->ReadPropertyString('default_facility');
        $default_program = $this->ReadPropertyString('default_program');

        if ($syslog_server != '' && $syslog_port > 0) {
            $ok = true;
            if ($default_severity != '') {
                if ($this->decode_severity($default_severity) == -1) {
                    echo 'unsupported value "' . $default_severity . '" for property "' . severity . '"';
                    $ok = false;
                }
            }
            if ($default_facility != '') {
                if ($this->decode_facility($default_facility) == -1) {
                    echo 'unsupported value "' . $default_facility . '" for property "' . facility . '"';
                    $ok = false;
                }
            }
            if ($default_program == '') {
                echo 'no value for property "program"';
                $ok = false;
            }
            $this->SetStatus($ok ? IS_ACTIVE : self::$IS_INVALIDCONFIG);
        } else {
            $this->SetStatus(IS_INACTIVE);
        }

        $this->SetUpdateInterval();
    }

    public function GetConfigurationForm()
    {
        $formElements = $this->GetFormElements();
        $formActions = $this->GetFormActions();
        $formStatus = $this->GetFormStatus();

        $form = json_encode(['elements' => $formElements, 'actions' => $formActions, 'status' => $formStatus]);
        if ($form == '') {
            $this->SendDebug(__FUNCTION__, 'json_error=' . json_last_error_msg(), 0);
            $this->SendDebug(__FUNCTION__, '=> formElements=' . print_r($formElements, true), 0);
            $this->SendDebug(__FUNCTION__, '=> formActions=' . print_r($formActions, true), 0);
            $this->SendDebug(__FUNCTION__, '=> formStatus=' . print_r($formStatus, true), 0);
        }
        return $form;
    }

    private function GetFormElements()
    {
        $formElements = [];

        $formElements[] = [
            'type'    => 'ValidationTextBox',
            'name'    => 'server',
            'caption' => 'Server'
        ];
        $formElements[] = [
            'type'    => 'NumberSpinner',
            'name'    => 'port',
            'caption' => 'Port'
        ];
        $formElements[] = [
            'type'    => 'Label',
            'caption' => 'default settings'
        ];
        $formElements[] = [
            'type'    => 'Label',
            'caption' => 'possible values for severity: emerg, alert, crit, err, warning, notice, info, debug'
        ];
        $formElements[] = [
            'type'    => 'ValidationTextBox',
            'name'    => 'default_severity',
            'caption' => 'severity'
        ];
        $formElements[] = [
            'type'    => 'Label',
            'caption' => 'possible values for facility: auth, local0, local1, local2, local3, local4, local5, local6, local7, user'
        ];
        $formElements[] = [
            'type'    => 'ValidationTextBox',
            'name'    => 'default_facility',
            'caption' => 'facility'
        ];
        $formElements[] = [
            'type'    => 'ValidationTextBox',
            'name'    => 'default_program',
            'caption' => 'program'
        ];
        $formElements[] = [
            'type'    => 'Label',
            'caption' => ''
        ];
        $formElements[] = [
            'type'    => 'Label',
            'caption' => 'transfer IPS-messages to syslog'
        ];
        $formElements[] = [
            'type'    => 'Label',
            'caption' => 'Check messages every X seconds'
        ];
        $formElements[] = [
            'type'    => 'NumberSpinner',
            'name'    => 'update_interval',
            'caption' => 'Seconds'
        ];

        $columns = [];
        $columns[] = [
            'caption' => 'Name',
            'name'    => 'title',
            'width'   => '150px'
        ];
        $columns[] = [
            'caption' => 'Active',
            'name'    => 'active',
            'width'   => 'auto',
            'edit'    => [
                'type'    => 'CheckBox',
                'caption' => 'Message is active'
            ]
        ];
        $columns[] = [
            'caption' => 'Type',
            'name'    => 'msgtype',
            'width'   => 'auto',
            'save'    => true,
            'visible' => false];
        $formElements[] = [
            'type'     => 'List',
            'name'     => 'msgtypes',
            'caption'  => 'Messages',
            'rowCount' => 7,
            'add'      => false,
            'delete'   => false,
            'columns'  => $columns];

        $options = [
            [
                'caption' => 'Sender',
                'value'   => 'Sender'
            ],
            [
                'caption' => 'Text',
                'value'   => 'Text'
            ],
        ];

        $columns = [];
        $columns[] = [
            'caption' => 'Field',
            'name'    => 'field',
            'add'     => 'Sender',
            'width'   => '150px',
            'edit'    => [
                'caption' => 'Field',
                'type'    => 'Select',
                'name'    => 'field',
                'options' => $options
            ]
        ];
        $columns[] = [
            'caption' => 'Regular expression for named field',
            'name'    => 'expression',
            'add'     => '',
            'width'   => 'auto',
            'edit'    => [
                'type' => 'ValidationTextBox'
            ]
        ];
        $formElements[] = [
            'type'     => 'List',
            'name'     => 'exclude_filters',
            'caption'  => 'Exclude filter',
            'rowCount' => 5,
            'add'      => true,
            'delete'   => true,
            'columns'  => $columns
        ];

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'with_tstamp_vars',
            'caption' => 'Variables for Timestamps'
        ];

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        $formActions[] = [
            'type'    => 'Button',
            'caption' => 'Testmessage',
            'onClick' => 'Syslog_TestMessage($id);'
        ];
        $formActions[] = [
            'type'    => 'Button',
            'caption' => 'Check messages',
            'onClick' => 'Syslog_CheckMessages($id);'
        ];

        return $formActions;
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->InitialSnapshot();
        }
    }

    protected function SetUpdateInterval()
    {
        $sec = $this->ReadPropertyInteger('update_interval');
        $msec = $sec > 0 ? $sec * 1000 : 0;
        $this->SetTimerInterval('CheckMessages', $msec);
    }

    protected function InitialSnapshot()
    {
        $r = IPS_GetSnapshotChanges(0);
        $snapshot = json_decode($r, true);
        $this->SetBuffer('TimeStamp', $snapshot[0]['TimeStamp']);
    }

    public function CheckMessages()
    {
        $type2severity = [
            KL_ERROR   => 'error',
            KL_WARNING => 'warning',
            KL_MESSAGE => 'info',
            KL_CUSTOM  => 'info',
            KL_SUCCESS => 'notice',
            KL_NOTIFY  => 'notice',
            KL_DEBUG   => 'debug',
        ];

        $TimeStamp = $this->GetBuffer('TimeStamp');
        if ($TimeStamp == '' || $TimeStamp == 0) {
            $this->InitialSnapshot();
            $TimeStamp = $this->GetBuffer('TimeStamp');
        }

        $this->SendDebug(__FUNCTION__, 'start cycle with TimeStamp=' . $TimeStamp, 0);

        $sdata = @IPS_GetSnapshotChanges($TimeStamp);
        if ($sdata == '') {
            $this->SetStatus(self::$IS_NOSNAPSHOT);
            $old_ts = $TimeStamp;
            $this->InitialSnapshot();
            $TimeStamp = $this->GetBuffer('TimeStamp');
            $this->SendDebug(__FUNCTION__, 'unable to get snapshot (old=' . $old_ts . ', new=' . $TimeStamp . ') , resetting', 0);
            $this->LogMessage('unable to get snapshot (old=' . $old_ts . ', new=' . $TimeStamp . ') , resetting', KL_NOTIFY);
            $sdata = @IPS_GetSnapshotChanges($TimeStamp);
            if ($sdata == '') {
                $this->SendDebug(__FUNCTION__, 'unable to get snapshot (#' . $TimeStamp . '), reset failed', 0);
                $this->LogMessage('unable to get snapshot (#' . $TimeStamp . ') , reset failed', KL_NOTIFY);
                return;
            }
        }
        $udata = utf8_encode($sdata);
        $snapshot = json_decode($udata, true);
        if ($snapshot == '') {
            $txt = strlen($udata) > 7000 ? substr($udata, 0, 7000) . '...' : $r;
            $this->SendDebug(__FUNCTION__, 'unable to decode json-data, error=' . json_last_error() . ', len=' . strlen($sdata) . ', data=' . $txt . '...', 0);
            $this->LogMessage('unable to decode json-data, error=' . json_last_error() . ', length of data=' . strlen($sdata), KL_NOTIFY);
            $this->SetStatus(self::$IS_BADDATA);
            return;
        }

        $active_types = [];
        $s = $this->ReadPropertyString('msgtypes');
        $msgtypes = json_decode($s, true);
        if ($msgtypes != '') {
            foreach ($msgtypes as $msgtype) {
                if ($msgtype['active']) {
                    $active_types[] = $msgtype['msgtype'];
                }
            }
        }

        $s = $this->ReadPropertyString('exclude_filters');
        $exclude_filters = json_decode($s, true);

        $last_tstamp = 0;
        $n_accepted = 0;
        foreach ($snapshot as $obj) {
            $SenderID = $obj['SenderID'];
            $TimeStamp = $obj['TimeStamp'];
            $Message = $obj['Message'];
            $Data = $obj['Data'];

            if ($SenderID == $this->InstanceID) {
                continue;
            }

            $sender = '';
            $text = '';
            $tstamp = 0;

            switch ($Message) {
                case KL_MESSAGE:
                case KL_SUCCESS:
                case KL_NOTIFY:
                case KL_WARNING:
                case KL_ERROR:
                case KL_DEBUG:
                case KL_CUSTOM:
                    $sender = $Data[0];
                    $text = utf8_decode($Data[1]);
                    $tstamp = $Data[2];
                    break;
                default:
                    break;
            }

            if ($sender != '' && $exclude_filters != '') {
                foreach ($exclude_filters as $filter) {
                    if ($filter['field'] != 'Sender') {
                        continue;
                    }
                    $expr = $filter['expression'];
                    if (preg_match('/^[^\/].*[^\/]$/', $expr)) {
                        $expr = '/' . $expr . '/';
                    }
                    if (preg_match($expr, $sender)) {
                        // $this->SendDebug(__FUNCTION__, 'expr=' . $expr . ', sender=' . $sender . ' => suppress', 0);
                        $sender = '';
                        break;
                    }
                }
            }
            if ($sender == '') {
                continue;
            }

            if ($text != '' && $exclude_filters != '') {
                foreach ($exclude_filters as $filter) {
                    if ($filter['field'] != 'Text') {
                        continue;
                    }
                    $expr = $filter['expression'];
                    if (preg_match('/^[^\/].*[^\/]$/', $expr)) {
                        $expr = '/' . $expr . '/';
                    }
                    if (preg_match($expr, $text)) {
                        // $this->SendDebug(__FUNCTION__, 'expr=' . $expr . ', text=' . $text . ' => suppress', 0);
                        $text = '';
                        break;
                    }
                }
            }
            if ($text == '') {
                continue;
            }

            if ($tstamp) {
                $last_tstamp = $tstamp;
            }

            $ts = $tstamp ? date('d.m.Y H:i:s', $tstamp) : '';
            $n_txt = strlen($text);
            $txt = $n_txt > 1024 ? substr($text, 0, 1024) . '...' : $text;
            $this->SendDebug(__FUNCTION__, 'SenderID=' . $SenderID . ', Message=' . $Message . ', sender=' . $sender . ', tetx-len=' . $n_txt . ', text=' . utf8_decode($txt) . ', tstamp=' . $ts, 0);

            if (in_array($Message, $active_types) && isset($type2severity[$Message])) {
                $severity = $type2severity[$Message];
            } else {
                $severity = '';
            }
            if ($severity != '') {
                $this->Message($txt, $severity);
                $n_accepted++;
            }
        }

        $this->SendDebug(__FUNCTION__, 'length of data=' . strlen($sdata) . ', messages=' . count($snapshot) . ', sent=' . $n_accepted, 0);

        $this->SetBuffer('TimeStamp', $TimeStamp);

        $with_tstamp_vars = $this->ReadPropertyBoolean('with_tstamp_vars');
        if ($with_tstamp_vars) {
            if ($last_tstamp) {
                $this->SetValue('LastMessage', $last_tstamp);
            }
            $this->SetValue('LastCycle', time());
        }
        $this->SetStatus(IS_ACTIVE);
    }

    public function TestMessage()
    {
        $this->Message('Testnachricht');
    }

    public function Message(string $msg, string $severity = null, string $facility = null, string $program = null)
    {
        $server = $this->ReadPropertyString('server');
        $port = $this->ReadPropertyInteger('port');
        $default_severity = $this->ReadPropertyString('default_severity');
        $default_facility = $this->ReadPropertyString('default_facility');
        $default_program = $this->ReadPropertyString('default_program');

        if ($severity == null || $severity == '') {
            $severity = $default_severity;
        }
        $_severity = $this->decode_severity($severity);
        if ($_severity == -1) {
            echo 'unsupported severity "' . $default_severity . '"';
            return -1;
        }
        if ($facility == null || $facility == '') {
            $facility = $default_facility;
        }
        $_facility = $this->decode_facility($facility);
        if ($_facility == -1) {
            echo 'unsupported facility "' . $default_facility . '"';

            return -1;
        }
        if ($program == null || $program == '') {
            $program = $default_program;
        }

        $pri = $_facility + $_severity;
        $host = gethostname();
        $timestamp = date('Y-m-d\TH:i:sP');
        $msgid = date('Uu');
        $procid = '-';
        $sdata = '-';

        $syslog_message = '<' . $pri . '>'
            . '1'
            . ' '
            . $timestamp
            . ' '
            . $host
            . ' '
            . $program
            . ' '
            . $procid
            . ' '
            . $msgid
            . ' '
            . $sdata
            . ' '
            . $msg;

        $this->SendDebug(__FUNCTION__, 'server=' . $server . ', port=' . $port . ', message="' . $syslog_message . '"', 0);

        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$sock) {
            echo 'unable to create socket(' . $server . ':' . $port . ')';
            return -1;
        }
        $l = strlen($syslog_message);
        $n = socket_sendto($sock, $syslog_message, $l, 0, $server, (int) $port);
        if ($n != $l) {
            echo 'unable to set messages "' . $syslog_message . '" to socket(' . $server . ':' . $port . ')';
            return -1;
        }
        socket_close($sock);
    }

    public function Error(string $msg)
    {
        $this->Message($msg, 'error', null, null);
    }

    public function Warning(string $msg)
    {
        $this->Message($msg, 'warn', null, null);
    }

    public function Notice(string $msg)
    {
        $this->Message($msg, 'notice', null, null);
    }

    public function Info(string $msg)
    {
        $this->Message($msg, 'info', null, null);
    }

    private function decode_facility($str)
    {
        if (defined('LOG_LOCAL0')) {
            $str2facility = [
                'auth'    => LOG_AUTH,
                'local0'  => LOG_LOCAL0,
                'local1'  => LOG_LOCAL1,
                'local2'  => LOG_LOCAL2,
                'local3'  => LOG_LOCAL3,
                'local4'  => LOG_LOCAL4,
                'local5'  => LOG_LOCAL5,
                'local6'  => LOG_LOCAL6,
                'local7'  => LOG_LOCAL7,
                'user'    => LOG_USER,
            ];
        } else {
            $str2facility = [
                'auth'    => LOG_AUTH,
                'user'    => LOG_USER,
            ];
        }

        $str = strtolower($str);
        foreach ($str2facility as $key => $val) {
            if ($key == $str) {
                return $val;
            }
        }

        return -1;
    }

    private function decode_severity($str)
    {
        $str2severity = [
            'emerg'         => LOG_EMERG,
            'emergency'     => LOG_EMERG,
            'alert'         => LOG_ALERT,
            'crit'          => LOG_CRIT,
            'critical'      => LOG_CRIT,
            'err'           => LOG_ERR,
            'error'         => LOG_ERR,
            'warn'          => LOG_WARNING,
            'warning'       => LOG_WARNING,
            'notice'        => LOG_NOTICE,
            'info'          => LOG_INFO,
            'informational' => LOG_INFO,
            'debug'         => LOG_DEBUG,
        ];

        $str = strtolower($str);
        foreach ($str2severity as $key => $val) {
            if ($key == $str) {
                return $val;
            }
        }

        return -1;
    }
}
