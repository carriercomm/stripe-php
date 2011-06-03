<?php

class Stripe_ApiRequestor {
  public $apiKey;

  public function __construct($apiKey=null) {
    $this->apiKey = $apiKey;
  }

  public static function apiUrl($url='') {
    $apiBase = Stripe::$apiBase;
    return "$apiBase$url";
  }

  public static function utf8($value) {
    if (is_string($value))
      return utf8_encode($value);
    else
      return $value;
  }

  private static function objectsToIds($d) {
    if ($d instanceof Stripe_ApiRequestor) {
      return $d->id;
    } else if (is_array($d)) {
      $res = array();
      foreach ($res as $k => $v)
	$res[$k] = self::objectsToIds($v);
      return $res;
    } else {
      return $d;
    }
  }

  public static function encode($d) {
    return http_build_query($d, null, '&');
  }

  public function request($meth, $url, $params=null) {
    if (!$params)
      $params = array();
    list($rbody, $rcode, $myApiKey) = $this->requestRaw($meth, $url, $params);
    $resp = $this->interpretResponse($rbody, $rcode);
    return array($resp, $myApiKey);
  }

  public function handleApiError($rbody, $rcode, $resp) {
    if (!is_array($resp) || !isset($resp['error']))
      throw new Stripe_Error_Api("Invalid response object from API: $rbody (HTTP response code was $rcode)");
    $error = $resp['error'];
    switch ($rcode) {
    case 400:
    case 404:
      throw new Stripe_Error_InvalidRequest(isset($error['message']) ? $error['message'] : null,
					    isset($error['param']) ? $error['param'] : null);
    case 401:
      throw new Stripe_Error_Authentication(isset($error['message']) ? $error['message'] : null);
    case 402:
      throw new Stripe_Error_Card(isset($error['message']) ? $error['message'] : null,
				  isset($error['param']) ? $error['param'] : null,
				  isset($error['code']) ? $error['code'] : null);
    default:
      throw new Stripe_Error_Api(isset($error['message']) ? $error['message'] : null);
    }
  }

  private function requestRaw($meth, $url, $params) {
    $myApiKey = $this->apiKey;
    if (!$myApiKey)
      $myApiKey = Stripe::$apiKey;
    if (!$myApiKey)
      throw new Stripe_Error_Authentication('No API key provided.  (HINT: set your API key using "Stripe::$apiKey = <API-KEY>".  You can generate API keys from the Stripe web interface.  See https://stripe.com/api for details, or email support@stripe.com if you have any questions.');

    $absUrl = $this->apiUrl($url);
    $params = Stripe_Util::arrayClone($params);
    $this->objectsToIds($params);
    $langVersion = phpversion();
    $uname = php_uname();
    $ua = array('bindings_version' => Stripe::VERSION,
		'lang' => 'php',
		'lang_version' => $langVersion,
		'publisher' => 'stripe',
		'uname' => $uname);
    $headers = array('X-Stripe-Client-User-Agent: ' . json_encode($ua),
		     'User-Agent: Stripe/v1 RubyBindings/' . Stripe::VERSION);
    list($rbody, $rcode) = $this->curlRequest($meth, $absUrl, $headers, $params, $myApiKey);
    return array($rbody, $rcode, $myApiKey);
  }

  private function interpretResponse($rbody, $rcode) {
    try {
      $resp = json_decode($rbody, true);
    } catch (Exception $e) {
      throw new Stripe_Error_Api("Invalid response body from API: $rbody (HTTP response code was $rcode)");
    }

    if ($rcode < 200 || $rcode >= 300) {
      $this->handleApiError($rbody, $rcode, $resp);
    }
    return $resp;
  }

  private function curlRequest($meth, $absUrl, $headers, $params, $myApiKey) {
    $curl = curl_init();
    $meth = strtolower($meth);
    $opts = array();
    if ($meth == 'get') {
      $opts[CURLOPT_HTTPGET] = 1;
      if (count($params) > 0) {
	$encoded = self::encode($params);
	$absUrl = "$absUrl?$encoded";
      }
    } else if ($meth == 'post') {
      $opts[CURLOPT_POST] = 1;
      $opts[CURLOPT_POSTFIELDS] = self::encode($params);
    } else if ($meth == 'delete')  {
      $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
    } else {
      throw new Stripe_Error_Api("Unrecognized method $meth");
    }

    $absUrl = self::utf8($absUrl);
    $opts[CURLOPT_URL] = $absUrl;
    $opts[CURLOPT_RETURNTRANSFER] = true;
    $opts[CURLOPT_CONNECTTIMEOUT] = 30;
    $opts[CURLOPT_TIMEOUT] = 80;
    $opts[CURLOPT_RETURNTRANSFER] = true;
    $opts[CURLOPT_HTTPHEADER] = $headers;
    $opts[CURLOPT_USERPWD] = $myApiKey . ':';

    curl_setopt_array($curl, $opts);
    $rbody = curl_exec($curl);

    if (curl_errno($curl) == 60) { // CURLE_SSL_CACERT
      curl_setopt($curl, CURLOPT_CAINFO,
                  dirname(__FILE__) . '/data/ca-certificates.crt');
      $rbody = curl_exec($curl);
    }

    if ($rbody === false) {
      $errno = curl_errno($curl);
      $message = curl_error($curl);
      curl_close($curl);
      $this->handleCurlError($errno, $message);
    }

    $rcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return array($rbody, $rcode);
  }

  public function handleCurlError($errno, $message) {
    $apiBase = Stripe::$apiBase;
    switch ($errno) {
    case CURLE_COULDNT_CONNECT:
    case CURLE_COULDNT_RESOLVE_HOST:
    case CURLE_OPERATION_TIMEOUTED:
      $msg = "Could not connect to Stripe ($apiBase).  Please check your internet connection and try again.  If this problem persists, you should check Stripe's service status at https://twitter.com/stripe, or let us know at support@stripe.com.";
    case CURLE_SSL_CACERT:
      $msg = "Could not verify Stripe's SSL certificate.  Please make sure that your network is not intercepting certificates.  (Try going to $apiBase in your browser.)  If this problem persists, let us know at support@stripe.com.";
    default:
      $msg = "Unexpected error communicating with Stripe.  If this problem persists, let us know at support@stripe.com.";
    }

    $msg .= "\n\n(Network error: $message)";
    throw new Stripe_Error_ApiConnection($msg);
  }
}

?>