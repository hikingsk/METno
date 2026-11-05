<?php

/**
 * @author Martin Kluska @ iMakers, s.r.o. <martin.kluska@imakers.cz>
 * @copyright iMakers, s.r.o.
 * @copyright Martin Kluska
 * @web http://imakers.cz
 *
 *
 *
 * @todo info about location, should be added to METnoDay too
 * @todo time by location
 * @todo detection of night by sunset
 *
 * @uses METnoFactory
 * @uses METnoDay
 * @uses METnoSymbol
 * @uses METnoPrecipitation
 */
class METno extends METnoFactory {

    protected $apiRequest       = "https://api.met.no/weatherapi/locationforecast/2.0/classic?";
    protected $apiParameters    = "";

    /**
     * If error has occurred, the exception object is saved here
     * @var Exception
     */
    protected $error            = false;
    protected $errorHTML        = "";

    protected $forecastByDay    = array();

    /**
     * Truncated coordinates (strings, max 4 decimals) of the request
     * @var string
     */
    protected $lat              = "0";
    protected $lon              = "0";

    /**
     * True when MET.no failed and an older cached forecast was served instead
     * @var boolean
     */
    protected $stale            = false;

    /**
     * Constructs METno class with specified $lat $lon location and option seelevel
     *
     * @param <decimal>         $lat
     * @param <decimal>         $lon
     * @param <int|boolean>     $seeLevel - meters
     */
    public function __construct($lat, $lon, $seeLevel = false) {

        // MET.no TOS: max 4 decimals (more → 403), which also keeps the cache hit rate up
        $this->lat                  = self::truncateCoordinate($lat);
        $this->lon                  = self::truncateCoordinate($lon);
        $this->apiParameters       .= "lat=".$this->lat."&lon=".$this->lon;

        if ($seeLevel !== false && $seeLevel !== true && $seeLevel !== null && $seeLevel !== "") {
            $this->apiParameters   .= "&altitude=".(int) round((float) $seeLevel);
        }
    }

    /**
     * Was the forecast served from an expired cache entry because MET.no failed?
     * @return boolean
     */
    public function isStale() {
        return $this->stale;
    }

    /**
     * Sends the GET request to MET.no
     *
     * @param string      $url
     * @param string|null $ifModifiedSince value of the previous Last-Modified header
     * @return array {code:int, body:string, headers:array<lowercase name, value>, error:string}
     */
    protected function httpGet($url, $ifModifiedSince = null) {

        $userAgent = self::$userAgent;
        if ($userAgent == "" && isset($_SERVER['SERVER_SIGNATURE'])) {
            $userAgent = trim(strip_tags($_SERVER['SERVER_SIGNATURE']));
        }

        $headers = array();
        $curl    = curl_init();

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, self::$connectTimeout);
        curl_setopt($curl, CURLOPT_TIMEOUT, self::$timeout);
        // "" = advertise every encoding cURL supports (gzip, deflate) and decode transparently
        curl_setopt($curl, CURLOPT_ENCODING, "");
        if ($ifModifiedSince !== null && $ifModifiedSince !== "") {
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("If-Modified-Since: ".$ifModifiedSince));
        }
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$headers) {
            if (stripos($line, "HTTP/") === 0) {
                $headers = array(); // a redirect starts a new header block
            } else if (strpos($line, ":") !== false) {
                list($name, $value) = explode(":", $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        });

        $verbose = null;
        if (self::$logCurl){
            curl_setopt($curl, CURLOPT_VERBOSE, true);
            $verbose = fopen(self::$cacheDir."curl.log", 'a+');
            curl_setopt($curl, CURLOPT_STDERR, $verbose);
        }

        $body     = curl_exec($curl);
        $code     = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error    = curl_error($curl);
        curl_close($curl);
        if (is_resource($verbose)) {
            fclose($verbose);
        }

        return array(
            "code"    => $code,
            "body"    => is_string($body) ? $body : "",
            "headers" => $headers,
            "error"   => $error,
        );
    }

    /**
     * @param string $xmlContent
     * @return bool
     */
    private function isValidXml($xmlContent)
    {
        if (trim($xmlContent) === '') {
            return false;
        }

        $previous = libxml_use_internal_errors(true);

        $doc = new DOMDocument();
        $doc->loadXML($xmlContent);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return empty($errors);

    }

    /**
     * Genereates HTML Error and displays it if die on error is active
     * If die on error is not active, the html and Exception is saved for
     * internal use
     * @param <Exception> $e
     * @return boolean
     */
    public function error(Exception $e) {
        $this->errorHTML = "<h2>METno - An error has occurred</h2>";
        $this->errorHTML .= "<table>";
        $this->errorHTML .= "<tr><td style='width: 100px;padding-right: 10px;text-align:right;'>File </td><td> " . $e->getFile() . ":<strong>" . $e->getLine() . "</strong></td></tr>";
        $this->errorHTML .= "<tr><td style='padding-right: 10px;text-align:right;'>Code </td><td> " . $e->getCode() . "</td></tr>";
        $this->errorHTML .= "<tr><td style='padding-right: 10px;text-align:right;'>Message </td><td> " . $e->getMessage() . "</td></tr>";
        $this->errorHTML .= "</table><h3>Stack trace</h3>";

        foreach ($e->getTrace() as $trace) {
            $this->errorHTML        .="<p>";
            if (isset($trace["class"]) && $trace['class'] != '') {
                $this->errorHTML    .= $trace['class'];
                $this->errorHTML    .= '->';
            }

            $this->errorHTML        .= $trace['function'];
            $this->errorHTML        .= '(';
            if (!empty($trace["args"])) {
                $first  = true;

                foreach($trace["args"] as $argument) {
                    if (is_string($argument)) {
                        if ($first) {
                            $first  = false;
                        } else {
                            $this->errorHTML.=",";
                        }
                        $this->errorHTML.= $argument;
                    }
                }
            }
            $this->errorHTML        .= ');<br />';
        }
        $this->errorHTML .= "</table>";

        if (self::$dieOnError) {
            header("Content-type: text/html; charset=utf-8");
            die($this->errorHTML);
        } else {
            $this->error = $e;

            if (self::$displayErrors) {
                header("Content-type: text/html; charset=utf-8");
                echo $this->errorHTML;
            }
        }

        return false;
    }

    /**
     * Detects if there was an error during parsing xml
     * @return type
     */
    public function isError() {
        return is_object($this->error);
    }

    public function isSuccess() {
        return !is_object($this->error);
    }

    /**
     * Return today forecast wich can be printed/echo to get current temperature
     * @return METnoForecast
     */
    public function today() {
        return $this->getForecastForDate(date("Y-m-d"));
    }

    /**
     * Return tomorrows forecast wich can be printed/echo to get current temperature
     * @return METnoForecast
     */
    public function tomorrow() {
        return $this->getForecastForDate(date("Y-m-d", strtotime("+1 DAY")));
    }

    /**
     * Return forecast in 2 days wich can be printed/echo to get current temperature
     * @return METnoForecast
     */
    public function in2Days() {
        return $this->getForecastForDate(date("Y-m-d",  strtotime("+2 DAY")));
    }

    /**
     * Return forecast in 3 days wich can be printed/echo to get current temperature
     * @return METnoForecast
     */
    public function in3Days() {
        return $this->getForecastForDate(date("Y-m-d",  strtotime("+3 DAY")));
    }

    /**
     * Return forecast in 4 days wich can be printed/echo to get current temperature
     * @return METnoForecast
     */
    public function in4Days() {
        return $this->getForecastForDate(date("Y-m-d",  strtotime("+4 DAY")));
    }

    /**
     * Return forecast in 5 days wich can be printed/echo to get current temperature
     * @return METnoForecast
     */
    public function in5Days() {
        return $this->getForecastForDate(date("Y-m-d",  strtotime("+5 DAY")));
    }

    public function getForecastForXDays($count) {

        $current    = 0;
        $forecast   = array();

        foreach ($this->forecastByDay as $date => $forecastForDay) {
            $forecast[$date]     = $forecastForDay;
            $current++;
            if ($current == $count) {
                break;
            }
        }

        return $forecast;
    }

    /**
     * < GEThers >
     */

    /**
     * Returns Exception object if an error has occurred
     * @return <Exception>|boolean
     */
    public function getError() {
        return $this->error;
    }

    /**
     * Returns and generated HTML with error details
     * @return string
     */
    public function getErrorHTML() {
        return $this->errorHTML;
    }

    /**
     * Returns error message from <Exception> object
     * @return string
     */
    public function getErrorMesssage() {
        if ($this->isError()) {
            return $this->error->getMessage();
        } else {
            return "No error has occurred";
        }
    }

    /**
     *
     * @return boolean
     * @throws Exception
     */
    public function getForecast() {
        if (!empty($this->forecastByDay)) {
            return $this->forecastByDay;
        }

        $xml    = $this->getForecastXML();

        if (!is_bool($xml)) {
            try {

                /**
                 * Container with time entries by day
                 */
                $forecastByDay = array();
                $previousDay = null;
                $lastForeCast = null;

                foreach ($xml->product->time as $forecast) {

                    $forecastAttributes                     = $forecast->attributes();
                    if (isset($forecast->location)) {

                        if (isset($forecastAttributes["datatype"]) && isset($forecastAttributes["from"]) && $forecastAttributes["to"]) {
                            $fromValue                              = self::getAttributeValue($forecastAttributes, "from");
                            $toValue                                = self::getAttributeValue($forecastAttributes, "to");

                            if (!is_bool($fromValue) && !is_bool($toValue)) {

                                $fromDate                           = self::getDate($fromValue);
                                $fromHour                           = self::getHour($fromValue);
                                $toHour                             = self::getHour($toValue);


                                if (!is_bool($fromDate) && !is_bool($toHour) && !is_bool($fromHour)) {

                                    // check the previus day data if have all
                                    if (!is_null($previousDay) && $previousDay != $fromDate) {
                                        $hasData = false;

                                         foreach ($forecastByDay[$previousDay] as $hour) {
                                             if (is_object($hour["detail"])) {
                                                 $hasData = true;
                                             }
                                         }

                                         if (!$hasData) {
                                             // add to last hour the forecast location
                                             $hour = key($forecastByDay[$previousDay]);
                                             $forecastByDay[$previousDay][$hour]["detail"] = $lastForeCast->location;
                                         }
                                    }

                                    /**
                                     * Prepare containers by date and then for hour
                                     */
                                    if (!isset($forecastByDay[$fromDate])) {
                                        $forecastByDay[$fromDate]   = array();
                                    }

                                    if (!isset($forecastByDay[$fromDate][$toHour])) {
                                        $forecastByDay[$fromDate][$toHour] = array(
                                            "detail"    => 0,
                                            "symbols"   => array()
                                        );
                                    }

                                    if (!isset($forecastByDay[$fromDate][$fromHour])) {
                                        $forecastByDay[$fromDate][$fromHour] = array(
                                            "detail"    => 0,
                                            "symbols"   => array()
                                        );
                                    }

                                    /**
                                     * Detect if the element is weather info or
                                     * symbol info and insert it into the correct containe
                                     */
                                    if ($fromHour == $toHour) {
                                        $forecastByDay[$fromDate][$toHour]["detail"]        = $forecast->location;
                                    } else {
                                        $difference     = 0;

                                        if ($toHour >= $fromHour) {
                                            $difference = $toHour - $fromHour;
                                        } else {
                                            $difference = (24 - $fromHour) + $toHour;
                                        }

                                        if (!isset($forecast->location->precipitation, $forecast->location->symbol)) {
                                            continue;
                                            //throw new Exception("The XML is invalid - precipitation or symbol not defined", METno::XML_INVALID);
                                        }

                                        $symbolAttributes           = $forecast->location->symbol->attributes();
                                        $precipitationAttributes    = $forecast->location->precipitation->attributes();

                                        $forecastByDay[$fromDate][$fromHour]["symbols"][$toHour]     = array(
                                            "from"          => $fromHour,       // from hour
                                            "to"            => $toHour,         // to hour
                                            "difference"    => $difference,     // difference in hours betwen from to to
                                            "symbol"        => new self::$classSymbol(self::getAttributeValue($symbolAttributes, "number",0),
                                                                                     self::getAttributeValue($symbolAttributes, "id")),
                                            "precipitation" => new self::$classPrecipitation(
                                                self::getAttributeValue($precipitationAttributes, "value", 1),
                                                self::getAttributeValue($precipitationAttributes, "minvalue", 1),
                                                self::getAttributeValue($precipitationAttributes, "maxvalue", 1)
                                            )
                                        );

                                    }

                                    $previousDay = $fromDate;
                                    $lastForeCast = $forecast;

                                } else throw new Exception("The XML is invalid - the date detection failed", METno::XML_INVALID);
                            } else throw new Exception("The XML is invalid - the from and to attribute is invalid", METno::XML_INVALID);
                        } else throw new Exception("The XML is invalid - time node attributes", METno::XML_INVALID);
                    } else throw new Exception("The XML is invalid - location node is missing", METno::XML_INVALID);
                }

                $previousDay = null;
                $forecast = null;

                if (!empty($forecastByDay)) {
                    // sort by date, the xml can return today date before yesterday
                    ksort($forecastByDay);

                    // Create days
                    foreach ($forecastByDay as $date => $forecast) {
                        $this->forecastByDay[$date] = new METnoDay($date, $forecast,$this);
                    }
                    return $this->forecastByDay;
                } else throw new Exception("The weather information is empty", METno::DATA_EMPTY);

                return $this->forecastByDay;
            } catch (Exception $exc) {
                return $this->error($exc);
            }
        }
        return false;
    }

    /**
     * Gets forecast XML for the location — from the cache while it is fresh, otherwise from
     * MET.no — and checks the base structure.
     *
     * Caching follows the MET.no Terms of Service:
     * - an entry is fresh until max(Expires header, now + minimum caching time for the
     *   region) plus a random delay, so refetches never bunch up (e.g. at XX:00);
     * - an expired entry is revalidated with If-Modified-Since (304 only extends it);
     * - a failed request (network error, non-200, truncated XML, 429) is not repeated for
     *   errorBackoff seconds (or Retry-After, if longer) — an older forecast, up to maxStale
     *   old, is served meanwhile (isStale() is then true).
     *
     * @return SimpleXMLElement|boolean - false on error (see isError())
     */
    protected function getForecastXML() {

        try {
            $this->stale = false;

            $now        = $this->now();
            $file       = $this->getCacheFileName();
            $entry      = $this->readCacheEntry($file);
            $xml        = ($entry !== null && $entry["xml"] !== "") ? $this->parseForecastXML($entry["xml"]) : false;

            if ($xml !== false && $now < $entry["fresh_until"]) {
                return $xml;
            }

            $canServeStale = $xml !== false && self::$maxStale > 0
                && $now - $entry["fetched_at"] <= self::$maxStale;

            // MET.no failed recently — do not ask again before the backoff ends
            if ($entry !== null && $now < $entry["retry_after"]) {
                if ($canServeStale) {
                    $this->stale = true;
                    return $xml;
                }
                throw new Exception("MET.no request for ".$this->apiParameters." failed recently, next attempt after "
                    .gmdate("Y-m-d H:i:s", $entry["retry_after"])." UTC", METno::DOWNLOAD_FAILED);
            }

            $response   = $this->httpGet(
                $this->apiRequest.$this->apiParameters,
                $xml !== false ? $entry["last_modified"] : null
            );

            if ($xml !== false && $response["code"] === 304) {
                $entry["fetched_at"]    = $now;
                $entry["fresh_until"]   = $this->computeFreshUntil($response["headers"], $now);
                $entry["retry_after"]   = 0;
                if (isset($response["headers"]["last-modified"])) {
                    $entry["last_modified"] = $response["headers"]["last-modified"];
                }
                $this->writeCacheEntry($file, $entry);
                return $xml;
            }

            if ($response["code"] === 200) {
                $newXml = $this->parseForecastXML($response["body"]);
                if ($newXml !== false) {
                    $this->writeCacheEntry($file, array(
                        "xml"           => $response["body"],
                        "fetched_at"    => $now,
                        "fresh_until"   => $this->computeFreshUntil($response["headers"], $now),
                        "retry_after"   => 0,
                        "last_modified" => isset($response["headers"]["last-modified"]) ? $response["headers"]["last-modified"] : null,
                    ));
                    $this->maybeCollectGarbage();
                    return $newXml;
                }
            }

            // Failure: remember it (also when there is no data yet), so the next requests
            // back off instead of hammering a struggling or rate-limiting MET.no.
            $backoff = self::$errorBackoff;
            if (isset($response["headers"]["retry-after"])) {
                $retryAfter = $response["headers"]["retry-after"];
                $retryAfter = is_numeric($retryAfter) ? (int) $retryAfter : (int) strtotime($retryAfter) - $now;
                $backoff    = max($backoff, $retryAfter);
            }
            if ($entry === null || $xml === false) {
                $entry = array("xml" => "", "fetched_at" => 0, "fresh_until" => 0, "last_modified" => null);
            }
            $entry["retry_after"] = $now + $backoff;
            $this->writeCacheEntry($file, $entry);

            if ($canServeStale) {
                $this->stale = true;
                return $xml;
            }

            $reason = $response["error"] !== "" ? $response["error"] : "HTTP Code: ".$response["code"];
            throw new Exception("Error with downloading file from ".$this->apiRequest.$this->apiParameters
                ." (".$reason.")", METno::DOWNLOAD_FAILED);

        } catch (Exception $e) {
            return $this->error($e);
        }
    }

    /**
     * Parses forecast XML, returns false when it is not well-formed or lacks forecast data
     * (a truncated 200 response must never be accepted, let alone cached)
     * @param string $content
     * @return SimpleXMLElement|boolean
     */
    protected function parseForecastXML($content) {
        if (!is_string($content) || !$this->isValidXml($content)) {
            return false;
        }
        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($content);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml !== false && isset($xml->product) && is_object($xml->product) && isset($xml->product->time)) {
            return $xml;
        }
        return false;
    }

    /**
     * Until when (unix time) a fresh response may be served without asking MET.no
     * @param array $headers lowercase response headers
     * @param int   $now
     * @return int
     */
    protected function computeFreshUntil(array $headers, $now) {
        $freshUntil = $now + self::getMinCacheTime($this->lat, $this->lon);
        if (isset($headers["expires"])) {
            $expires = strtotime($headers["expires"]);
            if ($expires !== false && $expires > $freshUntil) {
                $freshUntil = $expires;
            }
        }
        return $freshUntil + $this->jitter();
    }

    /**
     * Current unix time (overridable for tests)
     * @return int
     */
    protected function now() {
        return time();
    }

    /**
     * Random delay added to a cache lifetime (overridable for tests)
     * @return int
     */
    protected function jitter() {
        return self::$cacheJitter > 0 ? mt_rand(0, self::$cacheJitter) : 0;
    }

    /**
     * @return string
     */
    protected function getCacheFileName() {
        return self::$cacheDir."metno-".str_replace(array("=", "&"), array("", "_"), $this->apiParameters).".cache";
    }

    /**
     * Reads a cache entry. File format: one line of JSON metadata, then the raw XML
     * (kept out of the JSON, so the body is stored byte for byte).
     * @param string $file
     * @return array|null
     */
    protected function readCacheEntry($file) {
        if (!is_file($file)) {
            return null;
        }
        $content = @file_get_contents($file);
        if (!is_string($content)) {
            return null;
        }
        $newline = strpos($content, "\n");
        if ($newline === false) {
            return null;
        }
        $meta = json_decode(substr($content, 0, $newline), true);
        if (!is_array($meta)) {
            return null;
        }
        return array(
            "xml"           => (string) substr($content, $newline + 1),
            "fetched_at"    => isset($meta["fetched_at"]) ? (int) $meta["fetched_at"] : 0,
            "fresh_until"   => isset($meta["fresh_until"]) ? (int) $meta["fresh_until"] : 0,
            "retry_after"   => isset($meta["retry_after"]) ? (int) $meta["retry_after"] : 0,
            "last_modified" => isset($meta["last_modified"]) && is_string($meta["last_modified"]) ? $meta["last_modified"] : null,
        );
    }

    /**
     * Writes a cache entry atomically (temp file + rename), so a concurrent reader never
     * sees half a file. A failed write is ignored — the forecast itself is still valid.
     * @param string $file
     * @param array  $entry
     * @return boolean
     */
    protected function writeCacheEntry($file, array $entry) {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $meta = json_encode(array(
            "fetched_at"    => (int) $entry["fetched_at"],
            "fresh_until"   => (int) $entry["fresh_until"],
            "retry_after"   => (int) $entry["retry_after"],
            "last_modified" => $entry["last_modified"],
        ));
        $tmp = @tempnam($dir, "tmp-metno-");
        if ($tmp === false) {
            return false;
        }
        if (@file_put_contents($tmp, $meta."\n".$entry["xml"]) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        @chmod($file, 0664);
        return true;
    }

    /**
     * Occasionally (1 in 50 successful fetches) removes cache files nobody asked for in a
     * while, leftover temp files and the per-day folders of library versions < 1.0.17.
     */
    protected function maybeCollectGarbage() {
        if (mt_rand(1, 50) === 1) {
            $this->collectGarbage();
        }
    }

    /**
     * @see maybeCollectGarbage()
     */
    protected function collectGarbage() {
        $now = $this->now();
        $dir = self::$cacheDir;

        foreach ((array) glob($dir."metno-*.cache") as $file) {
            // mtime = last write (fetch, revalidation or failure): unused for maxStale + 1 day
            if (is_file($file) && @filemtime($file) < $now - self::$maxStale - 86400) {
                @unlink($file);
            }
        }
        foreach ((array) glob($dir."tmp-metno-*") as $file) {
            if (is_file($file) && @filemtime($file) < $now - 3600) {
                @unlink($file);
            }
        }
        foreach ((array) glob($dir."[0-9]*", GLOB_ONLYDIR) as $legacyDir) {
            if (preg_match("~^[0-9]{8}$~", basename($legacyDir))) {
                $this->rmdirRecursively($legacyDir);
            }
        }
    }

    /**
     * Removes all the contents of dir
     * @param $dir
     * @return bool
     */
    public function rmdirRecursively($dir) {
        $files = array_diff((array) @scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->rmdirRecursively("$dir/$file") : @unlink("$dir/$file");
        }
        return @rmdir($dir);
    }

    /**
     *
     * @param type $date date in Y-m-d format
     * @return METnoForecast
     */
    public function getForecastForDate($date) {
        if (empty($this->forecastByDay)) {
            $this->getForecast();
        }

        if (isset($this->forecastByDay[$date])) {
            return $this->forecastByDay[$date];
        }

        return $this->error(new Exception("Forecast for date $date doesn't exist", METno::DATA_EMPTY));
    }
}
