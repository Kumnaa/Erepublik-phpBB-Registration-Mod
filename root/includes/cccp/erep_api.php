<?php
/*
    Erepublik API client

    v1.1
    
    @author Ben Bowtell

    @date 15-June-2010

    (c) 2010 by http://www.cccp-group.org

    contact: ben@apetechnologies.net

﻿   This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

/* INSTRUCTIONS

To initialise:

    $erep = new erep_api(<consumer key>, <consumer secret>);

To set the callback url:

    $erep->set_callback(<callback url>);

To get access key for 'citizen authentcation' and 'debit citizen account':

    $erep->request_access(<scope>);

    // returns the oauth token as a string or on error it returns false
       <scope> examples:
            to authenticate citizen and get wellness 'citizen/info'

Once you have an oauth_token......

To auth citizen:

    $erep->get_citizen_data(<oauth_token>, <oauth_verifier>);

    // returns citizen xml as a simplexml object or on error it throws an exception

To debug:

    echo $erep->debug(string);

// these are no longer used and are just here incase they come back

To debit citizen account:

    $erep->debit_citizen(<oauth_token);

    // returns debit xml as a simplexml object on error it throws an exception
 
To credit citizen account:

    $erep->credit_citizen(<citizen id>, <amount>[, <currency>]);

    // returns credit xml as a simplexml object or on error it returns error simplexml object
    // <currency> defaults to Gold
*/

class erep_api {

    private $params;
    private $request_method;
    private $request_url;
    private $secret;
    private $normalised_string;
    private $signature;
    private $scope;
    private $consumer_secret;
    private $url_domain;
    private $subject;
    private $api_timeout = 20; // set the api timeout in seconds
    private $debug_string;
    private $callback;
    private $api_url;

    // constructor
    public function __construct($consumer_key, $consumer_secret)
    {
        $banned_user_agents = array(
            'Mediapartners-Google'
        );
        if (in_array($_SERVER['HTTP_USER_AGENT'], $banned_user_agents)) {
            throw new Exception("Banned user agent");
        }
        $this->debug_string = '';
        $this->consumer_secret = $consumer_secret;
        $this->url_domain = 'http://api.erepublik.com';
        $this->api_url = 'http://api.erepublik.com/oauth/authorize';
        $this->params = array(
            'oauth_consumer_key' => $consumer_key,
            'oauth_nonce' => '',
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => '',
            'oauth_token' => '',
            'oauth_version' => '1.0'
        );
        $this->request_method = 'GET';
        $this->gen_secret();
    }

    public function set_callback($url)
    {
        $this->callback = $url;
    }

    // debit the amount declared in the original scope from citizens account and return the xml
    public function debit_citizen($oauth)
    {
        // get access token
        $this->params['oauth_token'] = $oauth;
        $data = $this->get_access();
        // get returned xml from transaction
        $this->subject = 'citizen';
        $this->gen_request_url('debit_account');
        $this->params['oauth_token'] = $data[0];
        $this->gen_secret($data[1]);
        $data = $this->api_comm();
        $data = $this->handle_xml($data);
        return ($data);
    }

    // credit $amount of $currency (default gold) to $citizen - no authing required
    public function credit_citizen($citizen, $amount, $currency = 'Gold')
    {
        unset($this->params['oauth_token']);
        $this->subject = 'citizen';
        $this->gen_request_url('credit_account');
        $this->params['currency'] = $currency;
        $this->params['value'] = $amount;
        $this->params['citizen_id'] = $citizen;
        $data = $this->api_comm();
        $data = $this->handle_xml($data);
        return ($data);
    }

    // fetch the data declared in the scope from citizen
    public function get_citizen_data($oauth, $verifier = '')
    {
        $this->params['oauth_token'] = $oauth;
        if (strlen($verifier) > 0)
        {
            $this->params = array_merge($this->params, array('oauth_verifier' => $verifier));
        }
        // get access tokens
        $data = $this->get_access();
        // get returned xml from transaction
        $this->subject = 'citizen';
        $this->gen_request_url('info');
        $this->params['oauth_token'] = $data[0];
        $this->gen_secret($data[1]);
        $data = $this->api_comm();
        $data = $this->handle_xml($data);
        return ($data);
    }

    // get the request token - used to auth before debiting/gettting citizen info
    public function request_access($scope)
    {
        $this->subject = 'oauth';
        $this->gen_request_url('request_token');
        $this->scope = $scope;
        $this->debug_string .= '<b>Scope:</b> '. $scope .'<br /><br />';
        $token = $this->get_token();
        if ($token == false)
        {
            $return = false;
        }
        else
        {
            $return = $this->api_url .'?oauth_token='. $token[0];
        }
        return ($return);
    }

    // swap the request token for the access token
    private function get_access()
    {
        $this->subject = 'oauth';
        $this->gen_request_url('access_token');
        $data = $this->get_token();
        return ($data);
    }

    // fetch token and secret
    private function get_token()
    {
        $data = $this->api_comm();
        preg_match('/^oauth_token=([A-Za-z0-9]+)&oauth_token_secret=([A-Za-z0-9]+)$/', $data, $matches);
        if(count($matches) != 3)
        {
            preg_match('/^oauth_token=([A-Za-z0-9]+)&oauth_token_secret=([A-Za-z0-9]+)&oauth_callback_confirmed=true$/', $data, $matches);
        }
        if (count($matches) > 0)
        {
            $auth = $matches[1];
            $secret = $matches[2];
            $return = array($auth, $secret);
        }
        else
        {
            $return = $this->handle_xml($data);
        }
        return($return);
    }

    // general function to communcate with the api and return the output
    private function api_comm()
    {
        $this->params['oauth_nonce'] = md5(rand(1,100));
        $this->params['oauth_timestamp'] = time();
        if (strlen($this->callback) > 0)
        {
            $this->params = array_merge($this->params, array('oauth_callback' => $this->callback));
        }
        if (strlen($this->scope) > 0)
        {
            $this->params = array_merge($this->params, array('scope' => $this->scope));
        }
        $this->signature = $this->gen_signature();
        $parameters = $this->normalised_string .'&oauth_signature='.urlencode($this->signature);
        if ($this->request_method != "POST")
        {
            $this->request_url .= '?'. $parameters;
        }
        $this->debug_string .= '<b>Request sent:</b> '. htmlentities($this->request_url);
        if ($this->request_method == "POST")
        {
            $this->debug_string .= ' - '. $parameters .' - '. (count($this->params) + 1);
        }
        $this->debug_string .=  '<br /><br />';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->request_url);
        if ($this->request_method == "POST")
        {
            curl_setopt($ch, CURLOPT_POST, count($this->params) + 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $parameters);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->api_timeout);
        $data = curl_exec($ch);
        if ($data === false)
        {
            throw new Exception(curl_error($ch));
        }
        curl_close($ch);
        $this->debug_string .= '<b>Data from api:</b> '. htmlentities($data) .'<br /><br />';
        return ($data);
    }

    // generate signature
    private function gen_signature()
    {
        $raw_sig = hash_hmac('sha1', $this->gen_base_string(), $this->secret, true);
        $b64_sig = base64_encode($raw_sig);
        $this->debug_string .= '<b>Signature:</b> '. $b64_sig .'<br /><br />';
        return ($b64_sig);
    }

    // generate base string
    private function gen_base_string ()
    {
        ksort($this->params);
        foreach ($this->params as $k=>$v)
        {
            if (($k == 'oauth_token' && strlen($v) > 0) || $k != 'oauth_token')
            {
                $enc_params[] = urlencode($k).'='.urlencode($v);
            }
        }
        $this->normalised_string = implode('&', $enc_params);
        $this->debug_string .= '<b>Normalised String:</b> '. $this->normalised_string .'<br /><br />';
        $return = $this->request_method.'&'.urlencode($this->request_url).'&'.urlencode($this->normalised_string);
        $this->debug_string .= '<b>Base String:</b> '. $return .'<br /><br />';
        return ($return);
    }

    // generate secret
    private function gen_secret($token_secret = '')
    {
        $this->secret = urlencode($this->consumer_secret) ."&". urlencode($token_secret);
    }

    // generate the request url
    private function gen_request_url($action)
    {
        $this->request_url = $this->url_domain.'/'. $this->subject .'/'. $action;
    }

    public function debug()
    {
        return $this->debug_string;
    }

    private function handle_xml($data)
    {
        $xml = @simplexml_load_string($data);
        if ($xml != false)
        {
            if (isset($xml->head) && isset($xml->body))
            {
                throw new Exception("An html error was encountered: ". nl2br(htmlentities($xml->body)));
            }
            elseif(isset($xml->message))
            {
                throw new Exception("An oauth error was encountered: ". $xml->message);
            }
            else
            {
                $data = $xml;
            }
        }
        else
        {
            throw new Exception("Invalid xml returned: ". $data);
        }
        return $data;
    }
}
?>