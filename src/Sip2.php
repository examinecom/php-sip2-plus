<?php

namespace Examine\Sip2;

use Examine\Sip2\Transport\StreamTransport;
use Examine\Sip2\Transport\TransportInterface;

/**
 * PHP SIP2 Plus - Core SIP2 message builder/parser.
 *
 * This file is part of the Examine.com PHP SIP2 Plus library. It provides the
 * Sip2 class used to construct SIP2 requests and parse SIP2 responses when
 * communicating with Integrated Library Systems (ILS).
 *
 * Credits
 * - Original implementation: John Wohlers <john@wohlershome.net> (cap60552/php-sip2)
 *
 * @link      https://github.com/examinecom/php-sip2-plus
 * @link      https://github.com/cap60552/php-sip2
 *
 * @license   GPL-3.0  See the LICENSE file distributed with this source code.
 * @copyright Copyright (c) Examine.com, derived from work by John Wohlers
 */
class Sip2
{
    /**
     * instance hostname
     *
     * @var string
     */
    public $hostname;

    /**
     * port number
     *
     * @var int
     */
    public $port = 6002;

    /**
     * language code (001 == english)
     *
     * @var string
     */
    public $language = '001';

    /**
     * patron identifier (AA)
     *
     * @var string
     */
    public $patron = '';

    /**
     * patron password (AD)
     *
     * @var string
     */
    public $patronpwd = '';

    /**
     * terminal password (AC)
     *
     * @var string
     */
    public $AC = '';

    /**
     * maximum number of resends allowed before we give up
     *
     * @var int
     */
    public $maxretry = 3;

    /**
     * field terminator
     *
     * @var string
     */
    public $fldTerminator = '|';

    /**
     * message terminator
     *
     * @var string
     */
    public $msgTerminator = "\r";

    /**
     * login encryption algorithm type (0 = plain text)
     *
     * @var int
     */
    public $UIDalgorithm = 0;

    /**
     * password encryption algorithm type (undocumented)
     *
     * @var int
     */
    public $PWDalgorithm = 0;

    /**
     * location code
     *
     * @var string
     */
    public $scLocation = '';

    /**
     * toggle crc checking and appending
     *
     * @var bool
     */
    public $withCrc = true;

    /**
     * toggle the use of sequence numbers
     *
     * @var bool
     */
    public $withSeq = true;

    /**
     * debug logging toggle
     *
     * @var bool
     */
    public $debug = false;

    /**
     * value for the AO field
     *
     * @var string
     */
    public $AO = 'WohlersSIP';

    /**
     * value for the AN field
     *
     * @var string
     */
    public $AN = 'SIPCHK';

    /**
     * internal sequence number
     */
    private int $seq = -1;

    /**
     * internal retry counter
     */
    private int $retry = 0;

    /**
     * internal message build buffer
     */
    private string $msgBuild = '';

    /**
     * internal message build toggle
     */
    private bool $noFixed = false;

    /**
     * Optionally inject a transport for I/O; defaults to StreamTransport
     */
    public function __construct(private readonly ?TransportInterface $transport = new StreamTransport)
    {
    }

    /**
     * Generate Patron Status (code 23) request messages in sip2 format
     *
     * @return string SIP2 request message
     *
     * @api
     */
    public function msgPatronStatusRequest()
    {
        /* Server Response: Patron Status Response message. */
        $this->_newMessage('23');
        $this->_addFixedOption($this->language, 3);
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addVarOption('AO', $this->AO);
        $this->_addVarOption('AA', $this->patron);
        $this->_addVarOption('AC', $this->AC);
        $this->_addVarOption('AD', $this->patronpwd);

        return $this->_returnMessage();
    }

    /**
     * Generate Checkout (code 11) request messages in sip2 format
     *
     * @param  string  $item  value for the variable length required AB field
     * @param  string  $nbDateDue  optional override for default due date (default '')
     * @param  string  $scRenewal  value for the renewal portion of the fixed length field (default N)
     * @param  string  $itmProp  value for the variable length optional CH field (default '')
     * @param  string  $fee  value for the variable length optional BO field (default N)
     * @param  string  $noBlock  value for the blocking portion of the fixed length field (default N)
     * @param  string  $cancel  value for the variable length optional BI field (default N)
     * @return string SIP2 request message
     *
     * @api
     */
    public function msgCheckout($item, $nbDateDue = '', $scRenewal = 'N', $itmProp = '', $fee = 'N', $noBlock = 'N', $cancel = 'N')
    {
        /* Checkout an item  (11) - untested */
        $this->_newMessage('11');
        $this->_addFixedOption($scRenewal, 1);
        $this->_addFixedOption($noBlock, 1);
        $this->_addFixedOption($this->_datestamp(), 18);
        if ($nbDateDue != '') {
            /* override defualt date due */
            $this->_addFixedOption($this->_datestamp($nbDateDue), 18);
        } else {
            /* send a blank date due to allow ACS to use default date due computed for item */
            $this->_addFixedOption('', 18);
        }
        $this->_addVarOption('AO', $this->AO);
        $this->_addVarOption('AA', $this->patron);
        $this->_addVarOption('AB', $item);
        $this->_addVarOption('AC', $this->AC);
        $this->_addVarOption('CH', $itmProp, true);
        $this->_addVarOption('AD', $this->patronpwd, true);
        $this->_addVarOption('BO', $fee, true); /* Y or N */
        $this->_addVarOption('BI', $cancel, true); /* Y or N */

        return $this->_returnMessage();
    }

    /**
     * Generate Checkin (code 09) request messages in sip2 format
     *
     * @param  string  $item  value for the variable length required AB field
     * @param  string  $itmReturnDate  value for the return date portion of the fixed length field
     * @param  string  $itmLocation  value for the variable length required AP field (default '')
     * @param  string  $itmProp  value for the variable length optional CH field (default '')
     * @param  string  $noBlock  value for the blocking portion of the fixed length field (default N)
     * @param  string  $cancel  value for the variable length optional BI field (default N)
     * @return string SIP2 request message
     *
     * @api
     */
    public function msgCheckin($item, $itmReturnDate, $itmLocation = '', $itmProp = '', $noBlock = 'N', $cancel = '')
    {
        /* Checkin an item (09) - untested */
        if ($itmLocation == '') {
            /* If no location is specified, assume the defualt location of the SC, behavior suggested by spec */
            $itmLocation = $this->scLocation;
        }

        $this->_newMessage('09');
        $this->_addFixedOption($noBlock, 1);
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addFixedOption($this->_datestamp($itmReturnDate), 18);
        $this->_addVarOption('AP', $itmLocation);
        $this->_addVarOption('AO', $this->AO);
        $this->_addVarOption('AB', $item);
        $this->_addVarOption('AC', $this->AC);
        $this->_addVarOption('CH', $itmProp, true);
        $this->_addVarOption('BI', $cancel, true); /* Y or N */

        return $this->_returnMessage();
    }

    /**
     * Generate Block Patron (code 11) request messages in sip2 format
     *
     * @param  string  $message  message value for the required variable length AL field
     * @param  string  $retained  value for the retained portion of the fixed length field (default N)
     * @return string SIP2 request message
     *
     * @api
     */
    public function msgBlockPatron($message, $retained = 'N')
    {
        /* Blocks a patron, and responds with a patron status response  (01) - untested */
        $this->_newMessage('01');
        $this->_addFixedOption($retained, 1); /* Y if card has been retained */
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addVarOption('AO', $this->AO);
        $this->_addVarOption('AL', $message);
        $this->_addVarOption('AA', $this->patron);
        $this->_addVarOption('AC', $this->AC);

        return $this->_returnMessage();
    }

    /**
     * Generate SC Status (code 99) request messages in sip2 format
     *
     * @param  int  $status  status code
     * @param  int  $width  message width (default 80)
     * @param  int  $version  prootocol version (default 2)
     * @return string|false SIP2 request message or false on error
     *
     * @api
     */
    public function msgSCStatus($status = 0, $width = 80, $version = 2)
    {
        /* selfcheck status message, this should be sent immediatly after login  - untested */
        /* status codes, from the spec:
        * 0 SC unit is OK
        * 1 SC printer is out of paper
        * 2 SC is about to shut down
        */

        if ($version > 3) {
            $version = 2;
        }

        if ($status < 0 || $status > 2) {
            $this->_debugmsg('SIP2: Invalid status passed to msgSCStatus');

            return false;
        }

        $this->_newMessage('99');
        $this->_addFixedOption($status, 1);
        $this->_addFixedOption($width, 3);
        $this->_addFixedOption(sprintf('%03.2f', $version), 4);

        return $this->_returnMessage();
    }

    /**
     * Generate ACS Resend (code 97) request messages in sip2 format
     *
     * @return string SIP2 request message
     *
     * @api
     */
    public function msgRequestACSResend()
    {
        /* Used to request a resend due to CRC mismatch - No sequence number is used */
        $this->_newMessage('97');

        return $this->_returnMessage(false);
    }

    /**
     * Generate login (code 93) request messages in sip2 format
     *
     * @param  string  $sipLogin  login value for the CN field
     * @param  string  $sipPassword  password value for the CO field
     * @return string SIP2 request message
     *
     * @api
     */
    public function msgLogin($sipLogin, $sipPassword)
    {
        /* Login (93) - untested */
        $this->_newMessage('93');
        $this->_addFixedOption($this->UIDalgorithm, 1);
        $this->_addFixedOption($this->PWDalgorithm, 1);
        $this->_addVarOption('CN', $sipLogin);
        $this->_addVarOption('CO', $sipPassword);
        $this->_addVarOption('CP', $this->scLocation, true);

        return $this->_returnMessage();
    }

    /**
     * Generate Patron Information (code 63) request messages in sip2 format
     *
     * @param  string  $type  type of information request (none, hold, overdue, charged, fine, recall, unavail)
     * @param  string  $start  value for BP field (default 1)
     * @param  string  $end  value for BQ field (default 5)
     * @return string SIP2 request message
     *
     * @api
     */
    public function msgPatronInformation(string $type, $start = '1', $end = '5')
    {
        /*
        * According to the specification:
        * Only one category of items should be  requested at a time, i.e. it would take 6 of these messages,
        * each with a different position set to Y, to get all the detailed information about a patron's items.
        */
        $summary['none'] = '      ';
        $summary['hold'] = 'Y     ';
        $summary['overdue'] = ' Y    ';
        $summary['charged'] = '  Y   ';
        $summary['fine'] = '   Y  ';
        $summary['recall'] = '    Y ';
        $summary['unavail'] = '     Y';

        /* Request patron information */
        $this->_newMessage('63');
        $this->_addFixedOption($this->language, 3);
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addFixedOption(sprintf('%-10s', $summary[$type]), 10);
        $this->_addVarOption('AO', $this->AO);
        $this->_addVarOption('AA', $this->patron);
        $this->_addVarOption('AC', $this->AC, true);
        $this->_addVarOption('AD', $this->patronpwd, true);
        $this->_addVarOption('BP', $start, true); /* old function version used padded 5 digits, not sure why */
        $this->_addVarOption('BQ', $end, true); /* old function version used padded 5 digits, not sure why */

        return $this->_returnMessage();
    }

    /**
     * Generate End Patron Session (code 35) request messages in sip2 format
     *
     * @return string SIP2 request message
     *
     * @api
     */
    public function msgEndPatronSession()
    {
        /*  End Patron Session, should be sent before switching to a new patron. (35) - untested */
        $this->_newMessage('35');
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addVarOption('AO', $this->AO);
        $this->_addVarOption('AA', $this->patron);
        $this->_addVarOption('AC', $this->AC, true);
        $this->_addVarOption('AD', $this->patronpwd, true);

        return $this->_returnMessage();
    }

    /**
     * Generate Fee Paid (code 37) request messages in sip2 format
     *
     * @param  int  $feeType  value for the fee type portion of the fixed length field
     * @param  int  $pmtType  value for payment type portion of the fixed length field
     * @param  string  $pmtAmount  value for the payment amount variable length required BV field
     * @param  string  $curType  value for the currency type portion of the fixed field
     * @param  string  $feeId  value for the fee id variable length optional CG field
     * @param  string  $transId  value for the transaction id variable length optional BK field
     * @return string|false SIP2 request message or false on error
     *
     * @api
     */
    public function msgFeePaid($feeType, $pmtType, $pmtAmount, $curType = 'USD', $feeId = '', $transId = '')
    {
        /* Fee payment function (37) - untested */
        /* Fee Types: */
        /* 01 other/unknown */
        /* 02 administrative */
        /* 03 damage */
        /* 04 overdue */
        /* 05 processing */
        /* 06 rental */
        /* 07 replacement */
        /* 08 computer access charge */
        /* 09 hold fee */

        /* Value Payment Type */
        /* 00   cash */
        /* 01   VISA */
        /* 02   credit card */

        if (! is_numeric($feeType) || $feeType > 99 || $feeType < 1) {
            /* not a valid fee type - exit */
            $this->_debugmsg("SIP2: (msgFeePaid) Invalid fee type: {$feeType}");

            return false;
        }

        if (! is_numeric($pmtType) || $pmtType > 99 || $pmtType < 0) {
            /* not a valid payment type - exit */
            $this->_debugmsg("SIP2: (msgFeePaid) Invalid payment type: {$pmtType}");

            return false;
        }

        $this->_newMessage('37');
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addFixedOption(sprintf('%02d', $feeType), 2);
        $this->_addFixedOption(sprintf('%02d', $pmtType), 2);
        $this->_addFixedOption($curType, 3);
        $this->_addVarOption('BV', $pmtAmount); /* due to currancy format localization, it is up to the programmer to properly format their payment amount */
        $this->_addVarOption('AO', $this->AO);
        $this->_addVarOption('AA', $this->patron);
        $this->_addVarOption('AC', $this->AC, true);
        $this->_addVarOption('AD', $this->patronpwd, true);
        $this->_addVarOption('CG', $feeId, true);
        $this->_addVarOption('BK', $transId, true);

        return $this->_returnMessage();
    }

    /**
     * Generate Item Information (code 17) request messages in sip2 format
     *
     * @param  string  $item  value for the variable length required AB field
     * @return string SIP2 request message
     *
     * @api
     */
    public function msgItemInformation($item)
    {

        $this->_newMessage('17');
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addVarOption('AO', $this->AO);
        $this->_addVarOption('AB', $item);
        $this->_addVarOption('AC', $this->AC, true);

        return $this->_returnMessage();
    }

    /**
     * Generate Item Status (code 19) request messages in sip2 format
     *
     * @param  string  $item  value for the variable length required AB field
     * @param  string  $itmProp  value for the variable length required CH field
     * @return string SIP2 request message
     *
     * @api
     */
    public function msgItemStatus($item, $itmProp = '')
    {
        /* Item status update function (19) - untested */
        $this->_newMessage('19');
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addVarOption('AO', $this->AO);
        $this->_addVarOption('AB', $item);
        $this->_addVarOption('AC', $this->AC, true);
        $this->_addVarOption('CH', $itmProp);

        return $this->_returnMessage();
    }

    /**
     * Generate Patron Enable (code 25) request messages in sip2 format
     *
     * @return string SIP2 request message
     *
     * @api
     */
    public function msgPatronEnable()
    {
        /* Patron Enable function (25) - untested */
        /* This message can be used by the SC to re-enable canceled patrons. It should only be used for system testing and validation. */
        $this->_newMessage('25');
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addVarOption('AO', $this->AO);
        $this->_addVarOption('AA', $this->patron);
        $this->_addVarOption('AC', $this->AC, true);
        $this->_addVarOption('AD', $this->patronpwd, true);

        return $this->_returnMessage();
    }

    /**
     * Generate Hold (code 15) request messages in sip2 format
     *
     * @param  string  $mode  value for the mode portion of the fixed length field
     * @param  string  $expDate  value for the optional variable length BW field
     * @param  string  $holdtype  value for the optional variable length BY field
     * @param  string  $item  value for the optional variable length AB field
     * @param  string  $title  value for the optional variable length AJ field
     * @param  string  $fee  value for the optional variable length BO field
     * @param  string  $pkupLocation  value for the optional variable length BS field
     * @return string|false SIP2 request message or false on error
     *
     * @api
     */
    public function msgHold($mode, $expDate = '', $holdtype = '', $item = '', $title = '', $fee = 'N', $pkupLocation = '')
    {
        /* mode validity check */
        /*
         * - remove hold
         * + place hold
         * * modify hold
         */
        if (!str_contains('-+*', $mode)) {
            /* not a valid mode - exit */
            $this->_debugmsg("SIP2: Invalid hold mode: {$mode}");

            return false;
        }

        if ($holdtype != '' && ($holdtype < 1 || $holdtype > 9)) {
            /*
             * Valid hold types range from 1 - 9
             * 1   other
             * 2   any copy of title
             * 3   specific copy
             * 4   any copy at a single branch or location
             */
            $this->_debugmsg("SIP2: Invalid hold type code: {$holdtype}");

            return false;
        }

        $this->_newMessage('15');
        $this->_addFixedOption($mode, 1);
        $this->_addFixedOption($this->_datestamp(), 18);
        if ($expDate != '') {
            /* hold expiration date,  due to the use of the datestamp function, we have to check here for empty value. when datestamp is passed an empty value it will generate a current datestamp */
            $this->_addVarOption('BW', $this->_datestamp($expDate), true); /* spec says this is fixed field, but it behaves like a var field and is optional... */
        }
        $this->_addVarOption('BS', $pkupLocation, true);
        $this->_addVarOption('BY', $holdtype, true);
        $this->_addVarOption('AO', $this->AO);
        $this->_addVarOption('AA', $this->patron);
        $this->_addVarOption('AD', $this->patronpwd, true);
        $this->_addVarOption('AB', $item, true);
        $this->_addVarOption('AJ', $title, true);
        $this->_addVarOption('AC', $this->AC, true);
        $this->_addVarOption('BO', $fee, true); /* Y when user has agreed to a fee notice */

        return $this->_returnMessage();
    }

    /**
     * Generate Renew (code 29) request messages in sip2 format
     *
     * @param  string  $item  value for the variable length optional AB field
     * @param  string  $title  value for the variable length optional AJ field
     * @param  string  $nbDateDue  value for the due date portion of the fixed length field
     * @param  string  $itmProp  value for the variable length optional CH field
     * @param  string  $fee  value for the variable length optional BO field
     * @param  string  $noBlock  value for the blocking portion of the fixed length field
     * @param  string  $thirdParty  value for the party section of the fixed length field
     * @return string SIP2 request message
     *
     * @api
     */
    public function msgRenew($item = '', $title = '', $nbDateDue = '', $itmProp = '', $fee = 'N', $noBlock = 'N', $thirdParty = 'N')
    {
        /* renew a single item (29) - untested */
        $this->_newMessage('29');
        $this->_addFixedOption($thirdParty, 1);
        $this->_addFixedOption($noBlock, 1);
        $this->_addFixedOption($this->_datestamp(), 18);
        if ($nbDateDue != '') {
            /* override default date due */
            $this->_addFixedOption($this->_datestamp($nbDateDue), 18);
        } else {
            /* send a blank date due to allow ACS to use default date due computed for item */
            $this->_addFixedOption('', 18);
        }
        $this->_addVarOption('AO', $this->AO);
        $this->_addVarOption('AA', $this->patron);
        $this->_addVarOption('AD', $this->patronpwd, true);
        $this->_addVarOption('AB', $item, true);
        $this->_addVarOption('AJ', $title, true);
        $this->_addVarOption('AC', $this->AC, true);
        $this->_addVarOption('CH', $itmProp, true);
        $this->_addVarOption('BO', $fee, true); /* Y or N */

        return $this->_returnMessage();
    }

    /**
     * Generate Renew All (code 65) request messages in sip2 format
     *
     * @param  string  $fee  value for the optional variable length BO field
     * @return string SIP2 request message
     *
     * @api
     */
    public function msgRenewAll($fee = 'N')
    {
        /* renew all items for a patron (65) - untested */
        $this->_newMessage('65');
        $this->_addVarOption('AO', $this->AO);
        $this->_addVarOption('AA', $this->patron);
        $this->_addVarOption('AD', $this->patronpwd, true);
        $this->_addVarOption('AC', $this->AC, true);
        $this->_addVarOption('BO', $fee, true); /* Y or N */

        return $this->_returnMessage();
    }

    /**
     * Parse the response returned from Patron Status request messages
     *
     * @param  string  $response  response string from the SIP2 backend
     * @return array parsed SIP2 response message
     *
     * @api
     */
    public function parsePatronStatusResponse($response): array
    {
        return ['fixed' => [
            'PatronStatus' => substr($response, 2, 14),
            'Language' => substr($response, 16, 3),
            'TransactionDate' => substr($response, 19, 18),
        ], 'variable' => $this->_parsevariabledata($response, 37)];
    }

    /**
     * Parse the response returned from Checkout request messages
     *
     * @param  string  $response  response string from the SIP2 backend
     * @return array parsed SIP2 response message
     *
     * @api
     */
    public function parseCheckoutResponse($response): array
    {
        return ['fixed' => [
            'Ok' => substr($response, 2, 1),
            'RenewalOk' => substr($response, 3, 1),
            'Magnetic' => substr($response, 4, 1),
            'Desensitize' => substr($response, 5, 1),
            'TransactionDate' => substr($response, 6, 18),
        ], 'variable' => $this->_parsevariabledata($response, 24)];
    }

    /**
     * Parse the response returned from Checkin request messages
     *
     * @param  string  $response  response string from the SIP2 backend
     * @return array parsed SIP2 response message
     *
     * @api
     */
    public function parseCheckinResponse($response): array
    {
        return ['fixed' => [
            'Ok' => substr($response, 2, 1),
            'Resensitize' => substr($response, 3, 1),
            'Magnetic' => substr($response, 4, 1),
            'Alert' => substr($response, 5, 1),
            'TransactionDate' => substr($response, 6, 18),
        ], 'variable' => $this->_parsevariabledata($response, 24)];
    }

    /**
     * Parse the response returned from SC Status request messages
     *
     * @param  string  $response  response string from the SIP2 backend
     * @return array parsed SIP2 response message
     *
     * @api
     */
    public function parseACSStatusResponse($response): array
    {
        return ['fixed' => [
            'Online' => substr($response, 2, 1),
            'Checkin' => substr($response, 3, 1),  /* is Checkin by the SC allowed ? */
            'Checkout' => substr($response, 4, 1),  /* is Checkout by the SC allowed ? */
            'Renewal' => substr($response, 5, 1),  /* renewal allowed? */
            'PatronUpdate' => substr($response, 6, 1),  /* is patron status updating by the SC allowed ? (status update ok) */
            'Offline' => substr($response, 7, 1),
            'Timeout' => substr($response, 8, 3),
            'Retries' => substr($response, 11, 3),
            'TransactionDate' => substr($response, 14, 18),
            'Protocol' => substr($response, 32, 4),
        ], 'variable' => $this->_parsevariabledata($response, 36)];
    }

    /**
     * Parse the response returned from login request messages
     *
     * @param  string  $response  response string from the SIP2 backend
     * @return array parsed SIP2 response message
     *
     * @api
     */
    public function parseLoginResponse($response): array
    {
        return ['fixed' => [
            'Ok' => substr($response, 2, 1),
        ], 'variable' => []];
    }

    /**
     * Parse the response returned from Patron Information request messages
     *
     * @param  string  $response  response string from the SIP2 backend
     * @return array parsed SIP2 response message
     *
     * @api
     */
    public function parsePatronInfoResponse($response): array
    {
        return ['fixed' => [
            'PatronStatus' => substr($response, 2, 14),
            'Language' => substr($response, 16, 3),
            'TransactionDate' => substr($response, 19, 18),
            'HoldCount' => intval(substr($response, 37, 4)),
            'OverdueCount' => intval(substr($response, 41, 4)),
            'ChargedCount' => intval(substr($response, 45, 4)),
            'FineCount' => intval(substr($response, 49, 4)),
            'RecallCount' => intval(substr($response, 53, 4)),
            'UnavailableCount' => intval(substr($response, 57, 4)),
        ], 'variable' => $this->_parsevariabledata($response, 61)];
    }

    /**
     * Parse the response returned from End Session request messages
     *
     * @param  string  $response  response string from the SIP2 backend
     * @return array parsed SIP2 response message
     *
     * @api
     */
    public function parseEndSessionResponse($response): array
    {
        return ['fixed' => [
            'EndSession' => substr($response, 2, 1),
            'TransactionDate' => substr($response, 3, 18),
        ], 'variable' => $this->_parsevariabledata($response, 21)];
    }

    /**
     * Parse the response returned from Fee Paid request messages
     *
     * @param  string  $response  response string from the SIP2 backend
     * @return array parsed SIP2 response message
     *
     * @api
     */
    public function parseFeePaidResponse($response): array
    {
        return ['fixed' => [
            'PaymentAccepted' => substr($response, 2, 1),
            'TransactionDate' => substr($response, 3, 18),
        ], 'variable' => $this->_parsevariabledata($response, 21)];
    }

    /**
     * Parse the response returned from Item Info request messages
     *
     * @param  string  $response  response string from the SIP2 backend
     * @return array parsed SIP2 response message
     *
     * @api
     */
    public function parseItemInfoResponse($response): array
    {
        return ['fixed' => [
            'CirculationStatus' => intval(substr($response, 2, 2)),
            'SecurityMarker' => intval(substr($response, 4, 2)),
            'FeeType' => intval(substr($response, 6, 2)),
            'TransactionDate' => substr($response, 8, 18),
        ], 'variable' => $this->_parsevariabledata($response, 26)];
    }

    /**
     * Parse the response returned from Item Status request messages
     *
     * @param  string  $response  response string from the SIP2 backend
     * @return array parsed SIP2 response message
     *
     * @api
     */
    public function parseItemStatusResponse($response): array
    {
        return ['fixed' => [
            'PropertiesOk' => substr($response, 2, 1),
            'TransactionDate' => substr($response, 3, 18),
        ], 'variable' => $this->_parsevariabledata($response, 21)];
    }

    /**
     * Parse the response returned from Patron Enable request messages
     *
     * @param  string  $response  response string from the SIP2 backend
     * @return array parsed SIP2 response message
     *
     * @api
     */
    public function parsePatronEnableResponse($response): array
    {
        return ['fixed' => [
            'PatronStatus' => substr($response, 2, 14),
            'Language' => substr($response, 16, 3),
            'TransactionDate' => substr($response, 19, 18),
        ], 'variable' => $this->_parsevariabledata($response, 37)];
    }

    /**
     * Parse the response returned from Hold request messages
     *
     * @param  string  $response  response string from the SIP2 backend
     * @return array parsed SIP2 response message
     *
     * @api
     */
    public function parseHoldResponse($response): array
    {
        return ['fixed' => [
            'Ok' => substr($response, 2, 1),
            'available' => substr($response, 3, 1),
            'TransactionDate' => substr($response, 4, 18),
            'ExpirationDate' => substr($response, 22, 18),
        ], 'variable' => $this->_parsevariabledata($response, 40)];
    }

    /**
     * Parse the response returned from Renew request messages
     *
     * @param  string  $response  response string from the SIP2 backend
     * @return array parsed SIP2 response message
     *
     * @api
     */
    public function parseRenewResponse($response): array
    {
        return ['fixed' => [
            'Ok' => substr($response, 2, 1),
            'RenewalOk' => substr($response, 3, 1),
            'Magnetic' => substr($response, 4, 1),
            'Desensitize' => substr($response, 5, 1),
            'TransactionDate' => substr($response, 6, 18),
        ], 'variable' => $this->_parsevariabledata($response, 24)];
    }

    /**
     * Parse the response returned from Renew All request messages
     *
     * @param  string  $response  response string from the SIP2 backend
     * @return array parsed SIP2 response message
     *
     * @api
     */
    public function parseRenewAllResponse($response): array
    {
        return ['fixed' => [
            'Ok' => substr($response, 2, 1),
            'Renewed' => substr($response, 3, 4),
            'Unrenewed' => substr($response, 7, 4),
            'TransactionDate' => substr($response, 11, 18),
        ], 'variable' => $this->_parsevariabledata($response, 29)];
    }

    /**
     * Send a message to the backend SIP2 system and read response
     *
     * @param  string  $message  The message text to send to the backend system (request)
     * @return string|false Raw string response returned from the backend system (response)
     *
     * @api
     */
    public function get_message($message)
    {
        /* sends the current message, and gets the response */
        $result = '';
        $terminator = '';

        $this->_debugmsg('SIP2: Sending SIP2 request...');
        $writeResult = $this->transport->write($message);
        if ($writeResult === false) {
            $this->_debugmsg('SIP2: Failed to write to transport.');

            return false;
        }

        $this->_debugmsg('SIP2: Request Sent, Reading response');

        // Read until CR (\x0D)
        while ($terminator !== "\x0D") {
            $byte = $this->transport->readByte();
            if ($byte === false) {
                // EOF or error
                break;
            }
            $terminator = $byte;
            $result .= $byte;
        }

        $this->_debugmsg("SIP2: {$result}");

        /* test message for CRC validity */
        if ($this->_check_crc($result)) {
            /* reset the retry counter on successful send */
            $this->retry = 0;
            $this->_debugmsg('SIP2: Message from ACS passed CRC check');
        } else {
            /* CRC check failed, request a resend */
            $this->retry++;
            if ($this->retry < $this->maxretry) {
                /* try again */
                $this->_debugmsg("SIP2: Message failed CRC check, retrying ({$this->retry})");

                return $this->get_message($message);
            }
            /* give up */
            $this->_debugmsg("SIP2: Failed to get valid CRC after {$this->maxretry} retries.");
            return false;
        }

        return $result;
    }

    /**
     * Open a socket connection to a backend SIP2 system
     *
     * @return bool The socket connection status
     *
     * @api
     */
    public function connect()
    {
        /* Socket Communications */
        $this->_debugmsg('SIP2: --- BEGIN SIP communication ---');

        $this->_debugmsg("SIP2: Attempting to connect to '{$this->hostname}' on port '{$this->port}'...");

        /* open a connection to the host */
        $result = $this->transport->connect($this->hostname, (int) $this->port);
        if (! $result) {
            $this->_debugmsg('SIP2: transport connect() failed.');
        } else {
            $this->_debugmsg('SIP2: --- TRANSPORT READY ---');
        }

        /* return the result from the connect */
        return $result;
    }

    /**
     * Disconnect from the backend SIP2 system (close socket)
     *
     * @api
     */
    public function disconnect(): void
    {
        /*  Close the transport */
        $this->transport->close();
    }

    /* internal utillity functions */

    /**
     * Generate a SIP2 compatable datestamp
     * From the spec:
     * YYYYMMDDZZZZHHMMSS.
     * All dates and times are expressed according to the ANSI standard X3.30 for date and X3.43 for time.
     * The ZZZZ field should contain blanks (code $20) to represent local time. To represent universal time,
     *  a Z character(code $5A) should be put in the last (right hand) position of the ZZZZ field.
     * To represent other time zones the appropriate character should be used; a Q character (code $51)
     * should be put in the last (right hand) position of the ZZZZ field to represent Atlantic Standard Time.
     * When possible local time is the preferred format.
     *
     * @param  int|string  $timestamp  Unix timestamp to format (default uses current time)
     * @return string a SIP2 compatible date/time stamp
     *
     * @internal
     */
    private function _datestamp($timestamp = ''): string
    {
        if ($timestamp != '') {
            /* Generate a proper date time from the date provided */
            return date('Ymd    His', $timestamp);
        }
        /* Current Date/Time */
        return date('Ymd    His');
    }

    /**
     * Parse variable length fields from SIP2 responses
     *
     * @param  string  $response  [description]
     * @param  int  $start  [description]
     * @return array an array containing the parsed variable length data fields
     *
     * @internal
     */
    private function _parsevariabledata($response, int $start): array
    {
        $result = [];
        $response = trim($response);
        $result['Raw'] = $this->withCrc ? explode('|', substr($response, $start, -6)) : explode('|', substr($response, $start));
        foreach ($result['Raw'] as $item) {
            $field = substr($item, 0, 2);
            $value = substr($item, 2);
            /**
             * SD returns some odd values on ocassion, Unable to locate the purpose in spec, so I strip from
             * the parsed array. Orig values will remain in ['raw'] element
             */
            $clean = trim($value, "\x00..\x1F");
            if (trim($clean) !== '') {
                $result[$field][] = $clean;
            }
        }
        if ($this->withCrc) {
            $result['AZ'][] = substr($response, -4);
        } else {
            $result['AZ'] = [];
        }

        return $result;
    }

    /**
     * Generate and format checksums for SIP2 messages
     *
     * @param  string  $string  the string to checksum
     * @return string properly formatted checksum of given string
     *
     * @internal
     */
    private function _crc(string $string): string
    {
        /* Calculate CRC */
        $sum = 0;

        $len = strlen($string);
        for ($n = 0; $n < $len; $n++) {
            $sum += ord(substr($string, $n, 1));
        }

        $crc = ($sum & 0xFFFF) * -1;

        /* 2008.03.15 - Fixed a bug that allowed the checksum to be larger then 4 digits */
        return substr(sprintf('%4X', $crc), -4, 4);
    }

    /**
     * Manage the internal sequence number and return the next in the list
     *
     * @return int internal sequence number
     *
     * @internal
     */
    private function _getseqnum(): float|int
    {
        /* Get a sequence number for the AY field */
        /* valid numbers range 0-9 */
        $this->seq++;
        if ($this->seq > 9) {
            $this->seq = 0;
        }

        return $this->seq;
    }

    /**
     * Handle the printing of debug messages
     *
     * @param  string  $message  the message text
     *
     * @internal
     */
    private function _debugmsg(string $message): void
    {
        /* custom debug function,  why repeat the check for the debug flag in code... */
        if ($this->debug) {
            trigger_error($message, E_USER_NOTICE);
        }
    }

    /**
     * Verify the integrity of SIP2 messages containing checksums
     *
     * @param  string  $message  the messsage to check
     * @return bool
     *
     * @internal
     */
    private function _check_crc(string $message)
    {
        /* check for enabled crc */
        if ($this->withCrc !== true) {
            return true;
        }

        /* test the recieved message's CRC by generating our own CRC from the message */
        $test = preg_split('/(.{4})$/', trim($message), 2, PREG_SPLIT_DELIM_CAPTURE);
        /* check validity */
        /* default return */
        return isset($test[0]) && isset($test[1]) && strcmp($this->_crc($test[0]), $test[1]) === 0;
    }

    /**
     * Reset the internal message buffers and start a new message
     *
     * @param  int  $code  The message code to start the string
     *
     * @internal
     */
    private function _newMessage(string $code): void
    {
        /* resets the msgBuild variable to the value of $code, and clears the flag for fixed messages */
        $this->noFixed = false;
        $this->msgBuild = $code;
    }

    /**
     * Add a fixed length option field to a request message
     *
     * @param  string  $value  the option value
     * @param  int  $len  the length of the option field
     *
     * @internal
     */
    private function _addFixedOption($value, int $len): bool
    {
        /* adds afixed length option to the msgBuild IF no variable options have been added. */
        if ($this->noFixed) {
            return false;
        }
        $this->msgBuild .= sprintf("%{$len}s", substr($value, 0, $len));
        return true;
    }

    /**
     * Add a variable length option field to a request message
     *
     * @param  string  $field  field code for this message
     * @param  string  $value  the option vaule
     * @param  bool  $optional  optional field designation (default false)
     *
     * @internal
     */
    private function _addVarOption(string $field, $value, bool $optional = false): bool
    {
        /* adds a varaiable length option to the message, and also prevents adding addtional fixed fields */
        if ($optional && $value == '') {
            /* skipped */
            $this->_debugmsg("SIP2: Skipping optional field {$field}");
        } else {
            $this->noFixed = true; /* no more fixed for this message */
            $this->msgBuild .= $field.substr($value, 0, 255).$this->fldTerminator;
        }

        return true;
    }

    /**
     * Return the contents of the internal msgBuild variable after appending
     * sequence and crc fields if requested and appending terminators
     *
     * @param  bool  $withSeq  optional value to enforce addition of sequence numbers
     * @param  bool  $withCrc  optional value to enforce addition of CRC checks
     * @return string formatted sip2 message text complete with termination
     *
     * @internal
     */
    private function _returnMessage($withSeq = null, $withCrc = null): string
    {
        /* use object defaults if not passed */
        $withSeq = empty($withSeq) ? $this->withSeq : $withSeq;
        $withCrc = empty($withCrc) ? $this->withCrc : $withCrc;

        /* Finalizes the message and returns it.  Message will remain in msgBuild until newMessage is called */
        if ($withSeq) {
            $this->msgBuild .= 'AY'.$this->_getseqnum();
        }
        if ($withCrc) {
            $this->msgBuild .= 'AZ';
            $this->msgBuild .= $this->_crc($this->msgBuild);
        }
        $this->msgBuild .= $this->msgTerminator;

        return $this->msgBuild;
    }
}
