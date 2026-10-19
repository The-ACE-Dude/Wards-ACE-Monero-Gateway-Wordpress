<?php
/**
 * Authenticated, fail-closed transport for the Wards ACE wallet RPC client.
 * Credentials belong in protected PHP configuration or the PHP worker's
 * environment, never WooCommerce options, HTML, logs, or this plugin's files.
 */
defined('ABSPATH') || exit;

class Monero_Wallet_Rpc
{
    protected $url = null, $is_debug = false;
    protected $curl_options = array();
    protected $host;
    protected $port;
    private $last_error = null;
    private const MAX_RESPONSE_BYTES = 8388608;

    public function __construct($pHost, $pPort)
    {
        // Resolve configuration only at request time: missing credentials must
        // disable payment operations, not take down WordPress during bootstrap.
        $this->host = $pHost;
        $this->port = $pPort;
    }

    private static function config($name, $fallback = null)
    {
        if (defined($name)) return constant($name);
        $value = getenv($name);
        return $value === false ? $fallback : $value;
    }

    private function endpoint()
    {
        $host = self::config('WARDSACE_MONERO_RPC_HOST', $this->host);
        $port = self::config('WARDSACE_MONERO_RPC_PORT', $this->port);
        if (!is_string($host) || $host === '' || preg_match('/[\x00-\x20\x7f]/', $host) ||
            !(is_int($port) || (is_string($port) && ctype_digit($port))) || $port < 1 || $port > 65535) {
            throw new RuntimeException('Wallet RPC endpoint configuration is invalid.');
        }
        if (strpos($host, '://') === false) $host = 'http://' . $host;
        $parts = parse_url($host);
        if (!is_array($parts) || empty($parts['host']) || !isset($parts['scheme']) ||
            !in_array($parts['scheme'], array('http', 'https'), true) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) ||
            isset($parts['query']) || isset($parts['fragment']) ||
            (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            throw new RuntimeException('Wallet RPC endpoint configuration is invalid.');
        }
        // Cleartext is allowed only to the local wallet or a local secure tunnel.
        if ($parts['scheme'] === 'http' &&
            !in_array(strtolower($parts['host']), array('127.0.0.1', '[::1]', 'localhost'), true)) {
            throw new RuntimeException('Remote wallet RPC connections require HTTPS.');
        }
        return $parts['scheme'] . '://' . $parts['host'] . ':' . intval($port) . '/json_rpc';
    }

    private function credentials()
    {
        $username = self::config('WARDSACE_MONERO_RPC_USERNAME');
        $password = self::config('WARDSACE_MONERO_RPC_PASSWORD');
        if (!is_string($username) || !is_string($password) ||
            $username === '' || $password === '' || strlen($username) > 255 || strlen($password) > 4096 ||
            preg_match('/[\x00-\x1f\x7f]/', $username . $password)) {
            throw new RuntimeException('Wallet RPC authentication is not configured.');
        }
        return array($username, $password);
    }

    public function getLastError()
    {
        return $this->last_error;
    }

    public function validate($pFailed, $pErrMsg)
    {
        if ($pFailed) throw new RuntimeException('Wallet RPC validation failed.');
    }

    public function setDebug($pIsDebug)
    {
        // Retained for compatibility. Never emit request/response bodies: they
        // can contain wallet keys, addresses, transaction details or passwords.
        $this->is_debug = !empty($pIsDebug);
        return $this;
    }

    public function setCurlOptions($pOptionsArray)
    {
        if (!is_array($pOptionsArray)) throw new InvalidArgumentException('Invalid RPC options.');
        foreach ($pOptionsArray as $option => $value) {
            if (!extension_loaded('curl') ||
                !in_array($option, array(CURLOPT_CONNECTTIMEOUT, CURLOPT_TIMEOUT), true) ||
                !is_int($value) || $value < 1 || $value > 120) {
                throw new InvalidArgumentException('Only bounded RPC timeout overrides are allowed.');
            }
        }
        $this->curl_options = $pOptionsArray + $this->curl_options;
        return $this;
    }

    public function _print($json) { /* Raw wallet output is intentionally disabled. */ }
    protected function debug($pAdd, $pShow = false) { /* No sensitive debug output. */ }

    public function _run($method, $params = null)
    {
        $this->last_error = null;
        try {
            return $this->request($method, $params);
        } catch (RuntimeException $error) {
            // Messages from this class are fixed/sanitized; never use the server's
            // error message, cURL error text, endpoint, credentials or raw body.
            $this->last_error = $error->getMessage();
        } catch (Throwable $error) {
            $this->last_error = 'Wallet RPC request failed.';
        }
        return null;
    }

    private function request($method, $params)
    {
        if (!is_string($method) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $method) ||
            ($params !== null && !is_array($params))) {
            throw new RuntimeException('Wallet RPC request parameters are invalid.');
        }
        // This integration only observes a pre-generated watch-only pool. Never
        // expose wallet/key generation, imports, spending, storage or key queries.
        $allowed = array('getheight', 'get_height', 'getaddress', 'get_address',
            'getbalance', 'get_balance', 'get_address_index', 'get_transfers',
            'incoming_transfers', 'get_payments', 'get_bulk_payments',
            'make_integrated_address', 'split_integrated_address', 'make_uri', 'parse_uri');
        if (!in_array($method, $allowed, true)) {
            throw new RuntimeException('Wallet RPC method is not permitted by this integration.');
        }
        $id = bin2hex(random_bytes(16));
        $request = json_encode(array('jsonrpc' => '2.0', 'method' => $method,
            'params' => $params === null ? (object) array() : $params, 'id' => $id));
        if ($request === false) throw new RuntimeException('Wallet RPC request could not be encoded.');
        $raw = $this->getResponse($request);
        if (!is_string($raw) || $raw === '' || strlen($raw) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('Wallet RPC response is empty or too large.');
        }
        $message = json_decode($raw, false, 128, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !($message instanceof stdClass)) {
            throw new RuntimeException('Wallet RPC returned invalid JSON.');
        }
        if (!isset($message->jsonrpc, $message->id) || $message->jsonrpc !== '2.0' || $message->id !== $id) {
            throw new RuntimeException('Wallet RPC response does not match this request.');
        }
        $hasResult = property_exists($message, 'result');
        $hasError = property_exists($message, 'error');
        if ($hasResult === $hasError) throw new RuntimeException('Wallet RPC response envelope is invalid.');
        if ($hasError) {
            if (!($message->error instanceof stdClass) || !isset($message->error->code, $message->error->message) ||
                !is_int($message->error->code) || !is_string($message->error->message)) {
                throw new RuntimeException('Wallet RPC error response is invalid.');
            }
            throw new RuntimeException('Wallet RPC rejected the request (code ' . $message->error->code . ').');
        }
        if (!($message->result instanceof stdClass)) throw new RuntimeException('Wallet RPC result is invalid.');
        $result = json_decode($raw, true, 128, JSON_BIGINT_AS_STRING)['result'];
        if (array_key_exists('status', $result) && $result['status'] !== 'OK') {
            throw new RuntimeException('Wallet RPC reported an unsuccessful status.');
        }
        $this->validateResult($method, $result);
        return $result;
    }

    private static function nonnegativeInteger($value)
    {
        return is_int($value) && $value >= 0;
    }

    private function validateResult($method, $result)
    {
        $valid = true;
        if ($method === 'getheight' || $method === 'get_height') {
            $valid = isset($result['height']) && self::nonnegativeInteger($result['height']);
        } else if ($method === 'get_address_index') {
            $valid = isset($result['index']) && is_array($result['index']) &&
                isset($result['index']['major'], $result['index']['minor']) &&
                self::nonnegativeInteger($result['index']['major']) && self::nonnegativeInteger($result['index']['minor']);
        } else if ($method === 'get_address') {
            $valid = isset($result['addresses']) && is_array($result['addresses']) &&
                array_keys($result['addresses']) === array_keys(array_values($result['addresses']));
            if ($valid) foreach ($result['addresses'] as $entry) {
                if (!is_array($entry) || !isset($entry['address'], $entry['address_index'], $entry['used']) ||
                    !is_string($entry['address']) || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{95}$/D', $entry['address']) ||
                    !self::nonnegativeInteger($entry['address_index']) || !is_bool($entry['used'])) {
                    $valid = false;
                    break;
                }
            }
        } else if ($method === 'create_address') {
            $valid = isset($result['address'], $result['address_index']) && is_string($result['address']) &&
                preg_match('/^[1-9A-HJ-NP-Za-km-z]{95}$/D', $result['address']) &&
                self::nonnegativeInteger($result['address_index']);
        } else if ($method === 'get_transfers') {
            foreach (array('in', 'out', 'pending', 'failed', 'pool') as $kind) {
                if (!array_key_exists($kind, $result)) continue;
                if (!is_array($result[$kind]) || array_keys($result[$kind]) !== array_keys(array_values($result[$kind]))) {
                    $valid = false;
                    break;
                }
                foreach ($result[$kind] as $transfer) {
                    if (!is_array($transfer) || !isset($transfer['txid'], $transfer['amount'], $transfer['height']) ||
                        !is_string($transfer['txid']) || !preg_match('/^[a-fA-F0-9]{64}$/D', $transfer['txid']) ||
                        !self::nonnegativeInteger($transfer['amount']) || !self::nonnegativeInteger($transfer['height'])) {
                        $valid = false;
                        break;
                    }
                }
            }
        }
        if (!$valid) throw new RuntimeException('Wallet RPC returned an invalid method result.');
    }

    protected function getResponse($request)
    {
        if (!extension_loaded('curl')) throw new RuntimeException('The PHP cURL extension is required for wallet RPC.');
        $this->url = $this->endpoint();
        list($username, $password) = $this->credentials();
        $ch = curl_init();
        if ($ch === false) throw new RuntimeException('Wallet RPC transport could not initialize.');
        $response = '';
        $tooLarge = false;
        $options = array(
            CURLOPT_URL => $this->url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json'),
            CURLOPT_ENCODING => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,
            CURLOPT_USERNAME => $username,
            CURLOPT_PASSWORD => $password,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            // libcurl also counts Digest's challenge retry against this limit.
            // FOLLOWLOCATION remains false, so HTTP redirects are never followed.
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            // Ignore ambient proxy configuration: this wallet endpoint must be
            // reached directly, not leaked to an unrelated worker HTTP proxy.
            CURLOPT_PROXY => '',
            CURLOPT_HEADERFUNCTION => function ($handle, $header) use (&$response) {
                // Digest challenge responses are separate from the final body.
                if (preg_match('/^HTTP\//i', $header)) $response = '';
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$response, &$tooLarge) {
                if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        );
        foreach ($this->curl_options as $option => $value) $options[$option] = $value;
        $caFile = self::config('WARDSACE_MONERO_RPC_CA_FILE');
        if ($caFile !== null) {
            if (!is_string($caFile) || !is_file($caFile) || !is_readable($caFile)) {
                curl_close($ch);
                throw new RuntimeException('Wallet RPC certificate authority file is unavailable.');
            }
            $options[CURLOPT_CAINFO] = $caFile;
        }
        try {
            if (!curl_setopt_array($ch, $options)) throw new RuntimeException('Wallet RPC transport options could not be set.');
            $completed = curl_exec($ch);
            if ($tooLarge) throw new RuntimeException('Wallet RPC response is too large.');
            if ($completed === false || curl_errno($ch) !== 0) throw new RuntimeException('Wallet RPC connection failed.');
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($status !== 200) throw new RuntimeException('Wallet RPC returned HTTP ' . intval($status) . '.');
            return $response;
        } finally {
            curl_close($ch);
        }
    }

    public function getJsonLastErrorMsg()
    {
        return json_last_error() === JSON_ERROR_NONE ? null : json_last_error_msg();
    }

    /*
     * The following functions can all be called to interact with the Monero RPC wallet
     * They will majority of them will return the result as an array
     * Example: $daemon->address(); where $daemon is an instance of this class, will return the wallet address as string within an array
     */

    public function address()
    {
        $address = $this->_run('getaddress');
        return $address;
    }

    public function getbalance()
    {
        $balance = $this->_run('getbalance');
        return $balance;
    }

    public function getheight()
    {
        $height = $this->_run('getheight');
        return is_array($height) ? $height['height'] : null;
    }

    public function incoming_transfer($type)
    {
        $incoming_parameters = array('transfer_type' => $type);
        $incoming_transfers = $this->_run('incoming_transfers', $incoming_parameters);
        return $incoming_transfers;
    }

    public function view_key()
    {
        $query_key = array('key_type' => 'view_key');
        $query_key_method = $this->_run('query_key', $query_key);
        return $query_key_method;
    }

    public function make_integrated_address($payment_id)
    {
        $integrate_address_parameters = array('payment_id' => $payment_id);
        $integrate_address_method = $this->_run('make_integrated_address', $integrate_address_parameters);
        return $integrate_address_method;
    }

    /* A payment id can be passed as a string
       A random payment id will be generated if one is not given */

    public function split_integrated_address($integrated_address)
    {
        if (!isset($integrated_address)) {
            if(is_admin()) echo "Error: Integrated_Address must not be null";
        } else {
            $split_params = array('integrated_address' => $integrated_address);
            $split_methods = $this->_run('split_integrated_address', $split_params);
            return $split_methods;
        }
    }

    public function make_uri($address, $amount, $recipient_name = null, $description = null)
    {
        // Convert to atomic units
        $new_amount = $amount * MONERO_GATEWAY_ATOMIC_UNITS_POW;

        $uri_params = array('address' => $address, 'amount' => $new_amount, 'payment_id' => '', 'recipient_name' => $recipient_name, 'tx_description' => $description);
        $uri = $this->_run('make_uri', $uri_params);
        return $uri;
    }

    public function parse_uri($uri)
    {
        $uri_parameters = array('uri' => $uri);
        $parsed_uri = $this->_run('parse_uri', $uri_parameters);
        return $parsed_uri;
    }

    public function transfer($amount, $address, $mixin = 12)
    {
        $new_amount = $amount * MONERO_GATEWAY_ATOMIC_UNITS_POW;
        $destinations = array('amount' => $new_amount, 'address' => $address);
        $transfer_parameters = array('destinations' => array($destinations), 'mixin' => $mixin, 'get_tx_key' => true, 'unlock_time' => 0, 'payment_id' => '');
        $transfer_method = $this->_run('transfer', $transfer_parameters);
        return $transfer_method;
    }

    public function get_payments($payment_id)
    {
        $get_payments_parameters = array('payment_id' => $payment_id);
        $get_payments = $this->_run('get_payments', $get_payments_parameters);
        if($get_payments === null) return null;
        if(isset($get_payments['payments']))
            return $get_payments['payments'];
        else
            return array();
    }

    public function get_pool_payments($payment_id)
    {
        $get_payments_parameters = array('pool' => true);
        $get_payments = $this->_run('get_transfers', $get_payments_parameters);

        if($get_payments === null) return null;
        if(!isset($get_payments['pool']))
            return array();

        $payments = array();
        foreach($get_payments['pool'] as $payment) {
            if($payment['double_spend_seen'])continue;
            if($payment['payment_id'] == $payment_id) {
                $payment['tx_hash'] = $payment['txid'];
                $payment['block_height'] = $payment['height'];
                $payments[] = $payment;
            }
        }

        return $payments;
    }

    public function get_all_payments($payment_id)
    {
        $confirmed_payments = $this->get_payments($payment_id);
        $pool_payments = $this->get_pool_payments($payment_id);
        if($confirmed_payments === null || $pool_payments === null) return null;
        return array_merge($pool_payments, $confirmed_payments);
    }

    public function get_bulk_payments($payment_id, $min_block_height)
    {
        $get_bulk_payments_parameters = array('payment_id' => $payment_id, 'min_block_height' => $min_block_height);
        $get_bulk_payments = $this->_run('get_bulk_payments', $get_bulk_payments_parameters);
        return $get_bulk_payments;
    }
    
    public function get_transfers($arr)
    {
        $get_parameters = $arr;
        $get_transfers = $this->_run('get_transfers', $get_parameters);
        return $get_transfers;
    }
    
    public function get_address($account_index = 0)
    {
        return $this->_run('get_address', array('account_index' => $account_index));
    }

    public function get_address_index($subaddress)
    {
        $params = array('address' => $subaddress);
        return $this->_run('get_address_index', $params);
    }

    public function store()
    {
        return $this->_run('store');
    }

    public function create_address($account_index = 0, $label = '')
    {
        $params = array('account_index' => $account_index, 'label' => $label);
        $create_address_method = $this->_run('create_address', $params);
        if($create_address_method === null) return null;
        // An unsaved subaddress must never be issued as a payable destination.
        if($this->store() === null) return null;
        return $create_address_method;
    }
}
