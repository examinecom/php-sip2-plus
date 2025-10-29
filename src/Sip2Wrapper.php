<?php

/**
 * PHP SIP2 Plus - High-level wrapper for SIP2 operations.
 *
 * Sip2Wrapper provides a convenient, object-oriented facade over the core
 * Sip2 class for common workflows: connecting, logging in, self-checking the
 * ACS status, and managing patron sessions and lookups.
 *
 * Credits
 * - Wrapper originally by Nathan Johnson <nathan@nathanjohnson.info>
 * - Underlying SIP2 implementation by John Wohlers <john@wohlershome.net>
 *
 * @link      https://github.com/examinecom/php-sip2-plus
 * @link      https://github.com/cap60552/php-sip2
 *
 * @license   GPL-3.0  See the LICENSE file distributed with this source code.
 */

namespace Examine\Sip2;

use Examine\Sip2\Transport\TlsStreamTransport;
use Exception;

/**
 * Sip2Wrapper
 *
 * This is a wrapper class for the Sip2.php from google code
 *
 * Usage:
 *```php
 *     // require the class
 *     require_once 'Sip2Wrapper.php';
 *
 *     // create the object
 *     $sip2 = new Sip2Wrapper(
 *       array(
 *         'hostname' => $hostname,
 *         'port' => 6001,
 *         'withCrc' => false,
 *         'location' => $location,
 *         'institutionId' => $institutionId
 *       )
 *     );
 *
 *     // login and perform self test
 *     $sip2->login($user, $pass);
 *
 *     // start a patron session and fetch patron status
 *     if ($sip2->startPatronSession($patron, $patronpwd)) {
 *       var_dump($sip2->patronScreenMessages);
 *     }
 *```
 */
class Sip2Wrapper
{
    /**
     * protected variables, accessible read-only via magic getter method
     * For instance, to get a copy of $_sip2, you can call $obj->sip2
     */

    /**
     * sip2 object
     *
     * @var object
     */
    protected \Examine\Sip2\Sip2 $_sip2;

    /**
     * connected state toggle
     *
     * @var bool
     */
    protected $_connected = false;

    /**
     * self check state toggle
     *
     * @var bool
     */
    protected $_selfChecked = false;

    /**
     * patron session state toggle
     *
     * @var bool
     */
    protected $_inPatronSession = false;

    /**
     * patron status
     *
     * @var array
     */
    protected $_patronStatus;

    /**
     * patron information
     *
     * @var array
     */
    protected $_patronInfo;

    /**
     * acs status
     *
     * @var array
     */
    protected $_acsStatus;

    /**
     * @param  string  $name  the member variable name
     *
     * @throws Exception if mathing getter fucntion doesn't exist
     */
    public function __get(string $name): mixed
    {
        /* look for a getter function named getName */
        $functionName = 'get'.ucfirst($name);
        if (method_exists($this, $functionName)) {
            return call_user_func([$this, $functionName]);
        }
        throw new Exception('Undefined parameter '.$name);
    }

    /**
     * getter function for $this->_sip2
     *
     * @return sip2
     */
    public function getSip2()
    {
        return $this->_sip2;
    }

    /**
     * @return array the patron status
     *
     * @throws Exception if patron session hasn't began
     */
    public function getPatronStatus()
    {
        if (! $this->_inPatronSession) {
            throw new Exception('Must start patron session before calling getPatronStatus');
        }
        if ($this->_patronStatus === null) {
            $this->fetchPatronStatus();
        }

        return $this->_patronStatus;
    }

    /**
     * parses patron status to determine if login was successful.
     *
     * @return bool returns true if valid, false otherwise
     */
    public function getPatronIsValid(): bool
    {
        $patronStatus = $this->getPatronStatus();
        if (strcmp((string) $patronStatus['variable']['BL'][0], 'Y') !== 0 || strcmp((string) $patronStatus['variable']['CQ'][0], 'Y') !== 0) {
            return false;
        }

        return true;
    }

    /**
     * Returns the total fines from patron status call
     *
     * @return number the float value of the fines
     */
    public function getPatronFinesTotal(): float
    {
        $status = $this->getPatronStatus();
        if (isset($status['variable']['BV'][0])) {
            return (float) $status['variable']['BV'][0];
        }

        return 0.00;
    }

    /**
     * returns the Screen Messages field of the patron status, which can include
     * for example blocked or barred
     *
     * @return array the screen messages
     */
    public function getPatronScreenMessages(): array
    {
        $status = $this->getPatronStatus();
        if (isset($status['variable']['AF']) && is_array($status['variable']['AF'])) {
            return $status['variable']['AF'];
        }
        return [];
    }

    /**
     * gets the patron info hold items field
     *
     * @return array Hold Items
     */
    public function getPatronHoldItems()
    {
        $info = $this->fetchPatronInfo('hold');

        return $info['variable']['AS'] ?? [];
    }

    /**
     * Get the patron info overdue items field
     *
     * @return array overdue items
     */
    public function getPatronOverdueItems()
    {
        $info = $this->fetchPatronInfo('overdue');

        return $info['variable']['AT'] ?? [];
    }

    /**
     * get the charged items field
     *
     * @return array charged items
     */
    public function getPatronChargedItems()
    {
        $info = $this->fetchPatronInfo('charged');

        return $info['variable']['AU'] ?? [];
    }

    /**
     * return patron fine detail from patron info
     *
     * @return array fines
     */
    public function getPatronFineItems()
    {
        $info = $this->fetchPatronInfo('fine');

        return $info['variable']['AV'] ?? [];
    }

    /**
     * return patron recall items from patron info
     *
     * @return array patron items
     */
    public function getPatronRecallItems()
    {
        $info = $this->fetchPatronInfo('recall');

        return $info['variable']['BU'] ?? [];
    }

    /**
     * return patron unavailable items from patron info
     *
     * @return array unavailable items
     */
    public function getPatronUnavailableItems()
    {
        $info = $this->fetchPatronInfo('unavail');

        return $info['variable']['CD'] ?? [];
    }

    /**
     * worker function to call out to sip2 server and grab patron information.
     *
     * @param  string  $type  One of 'none', 'hold', 'overdue', 'charged', 'fine', 'recall', or 'unavail'
     * @return array the parsed response from the server
     *
     * @throws Exception if startPatronSession has not been called with success prior to calling this
     */
    public function fetchPatronInfo($type = 'none')
    {
        if (! $this->_inPatronSession) {
            throw new Exception('Must start patron session before calling fetchPatronInfo');
        }
        if (is_array($this->_patronInfo) && isset($this->_patronInfo[$type])) {
            return $this->_patronInfo[$type];
        }
        $msg = $this->_sip2->msgPatronInformation($type);
        $info_response = $this->_sip2->parsePatronInfoResponse($this->_sip2->get_message($msg));
        if ($this->_patronInfo === null) {
            $this->_patronInfo = [];
        }
        $this->_patronInfo[$type] = $info_response;

        return $info_response;
    }

    /**
     * getter for acsStatus
     *
     * @return Ambigous <NULL, multitype:string multitype:multitype:  >
     */
    public function getAcsStatus()
    {
        return $this->_acsStatus;
    }

    /**
     * constructor
     *
     * @param  $sip2Params  array of key value pairs that will set the corresponding member variables
     *                     in the underlying sip2 class
     * @param  bool  $autoConnect  whether or not to automatically connect to the server.  defaults
     *                             to true
     */
    public function __construct(array $sip2Params = [], $autoConnect = true)
    {
        // Build transport based on TLS flags
        $useTls = (bool) ($sip2Params['useTls'] ?? $sip2Params['tls'] ?? false);
        $tlsOptions = is_array($sip2Params['tlsOptions'] ?? null) ? $sip2Params['tlsOptions'] : [];
        $transport = $useTls ? new Sip2(new TlsStreamTransport($tlsOptions)) : new Sip2;

        // Initialize Sip2 instance, then apply parameters
        $sip2 = $transport;
        foreach ($sip2Params as $key => $val) {
            switch ($key) {
                case 'institutionId':
                    $key = 'AO';
                    break;
                case 'location':
                    $key = 'scLocation';
                    break;
                case 'useTls':
                case 'tls':
                case 'tlsOptions':
                    // handled above
                    continue 2;
            }
            if (property_exists($sip2, $key)) {
                $sip2->$key = $val;
            }
        }
        $this->_sip2 = $sip2;
        if ($autoConnect) {
            $this->connect();
        }
    }

    /**
     * Connect to the server
     *
     * @return bool returns true if connection succeeds
     *
     * @throws Exception if connection fails
     */
    public function connect(): bool
    {
        $returnVal = $this->_sip2->connect();
        if ($returnVal === true) {
            $this->_connected = true;
        } else {
            throw new Exception('Connection failed');
        }

        return true;
    }

    /**
     * authenticate with admin credentials to the backend server
     *
     * @param  string  $bindUser  The admin user
     * @param  string  $bindPass  The admin password
     * @param  string  $autoSelfCheck  Whether to call SCStatus after login.  Defaults to true
     *                                 you probably want this.
     * @return Sip2Wrapper - returns $this if login successful
     *
     * @throws Exception if login failed
     */
    public function login($bindUser, $bindPass, $autoSelfCheck = true): static
    {
        $msg = $this->_sip2->msgLogin($bindUser, $bindPass);
        $login = $this->_sip2->parseLoginResponse($this->_sip2->get_message($msg));
        if ((int) $login['fixed']['Ok'] !== 1) {
            throw new Exception('Login failed');
        }
        /* perform self check */
        if ($autoSelfCheck) {
            $this->selfCheck();
        }

        return $this;
    }

    /**
     * Checks the ACS Status to ensure that the ACS is online
     *
     * @return Sip2Wrapper returns $this if successful
     *
     * @throws Exception if ACS is not online
     */
    public function selfCheck(): static
    {

        /* execute self test */
        $msg = $this->_sip2->msgSCStatus();
        $status = $this->_sip2->parseACSStatusResponse($this->_sip2->get_message($msg));
        $this->_acsStatus = $status;
        /* check status */
        if (strcmp((string) $status['fixed']['Online'], 'Y') !== 0) {
            throw new Exception('ACS Offline');
        }

        return $this;
    }

    /**
     * This method is required before any get/fetch methods that have Patron in the name.  Upon
     * successful login, it sets the inPatronSession property to true, otherwise false.
     *
     * @param  string  $patronId  Patron login ID
     * @param  string  $patronPass  Patron password
     * @return bool returns true on successful login, false otherwise
     */
    public function startPatronSession($patronId, $patronPass)
    {
        if ($this->_inPatronSession) {
            $this->endPatronSession();
        }
        $this->_sip2->patron = $patronId;
        $this->_sip2->patronpwd = $patronPass;

        // set to true before call to getPatronIsValid since it will throw an exception otherwise
        $this->_inPatronSession = true;
        $this->_inPatronSession = $this->getPatronIsValid();

        return $this->_inPatronSession;
    }

    /**
     * method to grab the patron status from the server and store it in _patronStatus
     *
     * @return Sip2Wrapper returns $this
     */
    public function fetchPatronStatus(): static
    {
        $msg = $this->_sip2->msgPatronStatusRequest();
        $patron = $this->_sip2->parsePatronStatusResponse($this->_sip2->get_message($msg));
        $this->_patronStatus = $patron;

        return $this;
    }

    /**
     * method to send a patron session to the server
     *
     * @return Sip2Wrapper returns $this
     *
     * @throws Exception if patron session is not properly ended
     */
    public function endPatronSession(): static
    {
        $msg = $this->_sip2->msgEndPatronSession();
        $end = $this->_sip2->parseEndSessionResponse($this->_sip2->get_message($msg));
        if (strcmp((string) $end['fixed']['EndSession'], 'Y') !== 0) {
            throw new Exception('Error ending patron session');
        }
        $this->_inPatronSession = false;
        $this->_patronStatus = null;
        $this->_patronInfo = null;

        return $this;
    }

    /**
     * disconnect from the server
     *
     * @return Sip2Wrapper returns $this
     */
    public function disconnect(): static
    {
        $this->_sip2->disconnect();
        $this->_connected = false;
        $this->_inPatronSession = false;
        $this->_patronInfo = null;
        $this->_acsStatus = null;

        return $this;
    }

    public function isConnected(): bool
    {
        return $this->_connected;
    }
}
