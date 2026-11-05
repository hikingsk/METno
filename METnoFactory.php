<?php

/**
 * @author Martin Kluska @ iMakers, s.r.o. <martin.kluska@imakers.cz>
 * @copyright iMakers, s.r.o.
 * @copyright Martin Kluska
 * @web http://imakers.cz
 * 
 */
interface METnoInterface {
    const DOWNLOAD_FAILED   = 100;
    const XML_INVALID       = 101;
    const DATA_EMPTY        = 102;
    const FORECAST_INVALID  = 103;
}

class METnoFactory implements METnoInterface {
    /**
     * class name for extending function for symbols. Sub class must extend
     * <METnoSymbol>
     * @var METnoSymbol
     */
    static protected $classSymbol               = "METnoSymbol";
    
    /**
     * class name for precipitation wich must subclass <METnoPrecipitation>
     * @var METnoPrecipitation 
     */
    static protected $classPrecipitation        = "METnoPrecipitation";
    
    /**
     * Defines hour wich will be used when selecting day forecast
     * - used only if <$dayForecastHighest> is false
     * - range of 0 - 23
     * @var int 
     */
    static protected $dayForecastByHour         = 14;
    
    /**
     * Defines hour wich will be used when selecting night forecast
     * - used only if <$nightForecastLowest> is false
     * - range of 0 - 23
     * @var int 
     */
    static protected $nightForecastByHour       = 23;
    
    /**
     * Defines hour wich defines when the night starts
     * - used only if <$nightForecastLowest> is true
     * - range of 0 - 23
     * @todo sunset time by location
     * @var int 
     */
    static protected $nightHourStart            = 20;
    
    /**
     * Weather forecast for all day is selected from the hightest
     * temperature
     * @var boolean 
     */
    static protected $dayForecastHighest        = true;
    
    /**
     * Weather forecast for night is selected from the lowest temperature 
     * @var boolean 
     */
    static protected $nightForecastLowest       = true;

    /**
     * Folder for caching by location and hour
     * @var string 
     */
    static protected $cacheDir                  = "_METcache/";
    
    /**
     * Count of decimals for rounding of wind speed
     * @var int 
     */
    static protected $decimalWindSpeed          = 2;
    /**
     * Count of decimals for rounding of percente values
     * @var int 
     */
    static protected $decimalPercente           = 0;
    
    /**
     * Count of decimals for rounding of temperature
     * @var int 
     */
    static protected $decimalTemperature        = 0;

    /**
     * Display error and stop php
     * @var boolean 
     */
    static protected $dieOnError                = false;
    
    /**
     * Display error and continue
     * @var boolean 
     */
    static protected $displayErrors             = false;

    /**
     * Log CURL requests
     * @var boolean 
     */
    static protected $logCurl                   = false;

    /**
     * Folder for caching by location and hour
     * @var string 
     */
    static protected $userAgent                 = "";

    /**
     * cURL connect timeout for the MET.no request, in seconds (0 = no limit)
     * @var int
     */
    static protected $connectTimeout            = 10;

    /**
     * cURL total timeout for the MET.no request, in seconds (0 = no limit).
     * Without one, a MET.no that accepts the connection and then stalls blocks
     * the caller until PHP's max_execution_time.
     * @var int
     */
    static protected $timeout                   = 30;

    /**
     * MET.no Terms of Service / Locationforecast FAQ (Nov 2026): the response cache headers
     * are an absolute minimum, plus a minimum caching time per region — 30 min in the Nordic
     * region (hourly model updates), 3 h for the rest of the world (6-hourly updates).
     * These are floors: setMinCacheTimes() can only raise them.
     */
    const MIN_CACHE_NORDIC                      = 1800;
    const MIN_CACHE_WORLD                       = 10800;

    /**
     * Minimum caching time in seconds for locations in the Nordic region
     * @var int
     */
    static protected $minCacheNordic            = 1800;

    /**
     * Minimum caching time in seconds for the rest of the world
     * @var int
     */
    static protected $minCacheWorld             = 10800;

    /**
     * Upper bound of the random delay (seconds) added to every cache lifetime, so refetches
     * spread out instead of all landing at the same moment (the TOS forbids XX:00 bursts).
     * @var int
     */
    static protected $cacheJitter               = 600;

    /**
     * Seconds to wait before asking MET.no again after a failed request (5xx, 429, network
     * error, truncated XML). A longer Retry-After from MET.no wins.
     * @var int
     */
    static protected $errorBackoff              = 600;

    /**
     * How old (seconds since MET.no last confirmed the data) a cached forecast may get and
     * still be served when MET.no is failing. Older than this → the request fails.
     * @var int
     */
    static protected $maxStale                  = 86400;

    /**
     * Display error and stop php
     * @param type $set
     */
    static public function setDieOnError($set = true) {
        self::$dieOnError = $set;
    }
    
    /**
     * Display error and continue
     * @param type $set
     */
    static public function setDisplayErrors($set = true) {
        self::$displayErrors = $set;
    }
    
    /**
     * Log Curl requests
     * @param type $set
     */
    static public function setLogCurl($set = true) {
        self::$logCurl = $set;
    }
    
    /**
     * Sets the hour for selecting day forecast (disables selecting by highest temperature)
     * @param int $hour - range of 0 - 23
     * @return boolean
     */
    static public function setHourForDayForecast($hour) {
        $hour   = intval($hour);
        
        if ($hour >= 0 && $hour <= 23) {
            self::$dayForecastHighest   = false;
            self::$dayForecastByHour    = $hour;
            return true;
        }
        
        return false;
    }
    
    /**
     * Sets the hour for selecting night forecast (disables selecting by lowest temperature)
     * @param int $hour - range of 0 - 23
     * @return boolean
     */
    static public function setHourForNightForecast($hour) {
        $hour   = intval($hour);
        
        if ($hour >= 0 && $hour <= 23) {
            self::$nightForecastLowest      = false;
            self::$nightForecastByHour      = $hour;
            return true;
        }
        
        return false;
    }
    
    /**
     * Defines when the night starts
     * @param int $hour - range of 0 - 23
     * @return boolean
     */
    static public function setHourWhenNightStarts($hour) {
        $hour   = intval($hour);
        
        if ($hour >= 0 && $hour <= 23) {
            self::$nightHourStart           = $hour;
            return true;
        }
        return false;
    }
    /**
     * Sets if the day forecast should be choosed by highest temperature or defined
     * hour <METnoFactory::setHourForDayForecast()>
     * @param type $set
     * @return boolean
     */    
    static public function setDetectDayForecastByTemperature($set = true) {
        self::$dayForecastHighest = $set;
        return true;
    }
    /**
     * Sets if the night forecast should be choosed by lowest temperature or defined
     * hour <METnoFactory::setHourForNightForecast()>
     * @param type $set
     * @return boolean
     */  
    static public function setDetectNightForecastByTemperature($set = true) {
        self::$nightForecastLowest = $set;
        return true;
    }
    
    /**
     * Sets a class name for symbol. Must be subclass of <METnoSymbol>
     * 
     * - the class must exists or it tries to load it in the same directory
     * 
     * @param type $class_name
     * @return boolean
     */
    static public function setSymbolClass($class_name) {     
        
        self::loadClass($class_name); // try to load class if not present
        
        if (class_exists($class_name)) {
            self::$classSymbol          = $class_name;
            return true;
        }
        return false;
    }
    
    /**
     * Sets a class name for precipitation. Must be subclass of <METnoPrecipitation>
     * 
     * - the class must exists or it tries to load it in the same directory
     * 
     * @param type $class_name
     * @return boolean
     */
    static public function setPrecipitationClass($class_name) {        
        if (!class_exists($class_name) && file_exists($class_name.".php")) {
            require_once $class_name.".php";
        }
        
        if (class_exists($class_name)) {
            self::$classPrecipitation  = $class_name;
            return true;
        }
        return false;
    }
    
    /**
     * Sets cache directory
     * 
     * - directory must exist and must be writable by webserver process
     * 
     * @param string $dir - directory with trailing slash
     * @return boolean
     */
    static public function setCacheDir($dir) {
        if (is_dir($dir)) {
            self::$cacheDir = $dir;
            return true;
        } else {
            return false;
        }
    }
    
    /**
     * Sets the cURL timeouts of the MET.no request
     * @param int $connectTimeout seconds to connect (0 = no limit)
     * @param int $timeout seconds for the whole request (0 = no limit)
     * @return boolean
     */
    static public function setTimeouts($connectTimeout, $timeout) {
        if (!is_numeric($connectTimeout) || !is_numeric($timeout) || $connectTimeout < 0 || $timeout < 0) {
            return false;
        }
        self::$connectTimeout = (int) $connectTimeout;
        self::$timeout        = (int) $timeout;
        return true;
    }

    /**
     * Raises the minimum caching times (seconds). Values below the MET.no minimums
     * (MIN_CACHE_NORDIC / MIN_CACHE_WORLD) are rejected.
     * @param int $nordic
     * @param int $world
     * @return boolean
     */
    static public function setMinCacheTimes($nordic, $world) {
        if (!is_numeric($nordic) || !is_numeric($world)
            || $nordic < self::MIN_CACHE_NORDIC || $world < self::MIN_CACHE_WORLD) {
            return false;
        }
        self::$minCacheNordic = (int) $nordic;
        self::$minCacheWorld  = (int) $world;
        return true;
    }

    /**
     * Sets the upper bound of the random delay added to every cache lifetime (seconds)
     * @param int $seconds
     * @return boolean
     */
    static public function setCacheJitter($seconds) {
        if (!is_numeric($seconds) || $seconds < 0) {
            return false;
        }
        self::$cacheJitter = (int) $seconds;
        return true;
    }

    /**
     * Sets how long to wait after a failed MET.no request before trying again (seconds, ≥ 60)
     * @param int $seconds
     * @return boolean
     */
    static public function setErrorBackoff($seconds) {
        if (!is_numeric($seconds) || $seconds < 60) {
            return false;
        }
        self::$errorBackoff = (int) $seconds;
        return true;
    }

    /**
     * Sets how old a cached forecast may get and still be served while MET.no fails
     * (seconds, 0 = never serve stale data)
     * @param int $seconds
     * @return boolean
     */
    static public function setMaxStale($seconds) {
        if (!is_numeric($seconds) || $seconds < 0) {
            return false;
        }
        self::$maxStale = (int) $seconds;
        return true;
    }

    /**
     * Truncates (not rounds) a coordinate to 4 decimals, as MET.no requires — more decimals
     * get 403 Forbidden and defeat caching. Returned as a string without trailing zeros,
     * so it can be used both in the request URL and in the cache file name.
     * @param float|string $value
     * @return string
     */
    static public function truncateCoordinate($value) {
        // %.10F first, so a float like 48.94329 (stored as 48.943289999…) is cut at the
        // decimal digits a human wrote, not at its binary representation.
        $str = sprintf("%.10F", (float) $value);
        $dot = strpos($str, ".");
        $str = rtrim(rtrim(substr($str, 0, $dot + 5), "0"), ".");
        return ($str === "-0" || $str === "") ? "0" : $str;
    }

    /**
     * Is the location inside the Nordic region (hourly-updated forecast, shorter minimum
     * caching time)? A tight box around Norway, Sweden, Finland and Denmark (it also catches
     * the Baltic states, which the hourly MET Nordic model covers too) — Iceland, Svalbard
     * and the Faroes fall outside: a Nordic point treated as non-Nordic is only cached longer
     * than needed, whereas the opposite would break the TOS.
     * @param float $lat
     * @param float $lon
     * @return boolean
     */
    static public function isNordic($lat, $lon) {
        $lat = (float) $lat;
        $lon = (float) $lon;
        return $lat >= 54.5 && $lat <= 71.5 && $lon >= 4.0 && $lon <= 32.0;
    }

    /**
     * Minimum caching time in seconds for the location
     * @param float $lat
     * @param float $lon
     * @return int
     */
    static public function getMinCacheTime($lat, $lon) {
        return self::isNordic($lat, $lon) ? self::$minCacheNordic : self::$minCacheWorld;
    }

    /**
     * Sets UserAgent for cURL requests
     *
     * - as of MET.no terms of service, UserAgent must identify your app (domain or app name)
     *   and contain contact information (e-mail or website)
     *
     * @param string $userAgent - at least 8 characters
     * @return boolean
     */
    static public function setUserAgent($userAgent) {
        if (strlen($userAgent) > 8) {
            self::$userAgent = $userAgent;
            return true;
        } else {
            return false;
        }
    }
    
    static protected function loadClass($class_name) {
        if (!class_exists($class_name)) {
            if (file_exists(__DIR__."/".$class_name.".php")) {
                require_once __DIR__."/".$class_name.".php";
            } else if (file_exists($class_name.".php")) {
                require_once $class_name.".php";
            }
        }
    }
    
    /**
     * Returns the number of decimals for windSpeed
     * @return boolean
     */
    static public function setWindSpeedDecimals($set) {
        self::$decimalWindSpeed  = $set;
        return true;
    }
    /**
     * Returns the number of decimals for percente values
     * @return boolean
     */
    static public function setPercenteDecimals($set) {
        self::$decimalPercente   = $set;
        return true;
    }
    
    /**
     * Returns the number of decimals for temperature
     * @return boolean
     */
    static public function setTemperatureDecimals($set) {
        self::$decimalTemperature    = $set;
        return true;
    }

    /**
     * Gets forecast for location defined by Lat and Lon
     * @param type $lat
     * @param type $lon
     * @param type $seeLevel
     * @return \METno
     */
    static public function getForecastByLatLon($lat, $lon, $seeLevel = false) {
        $yr = new METno($lat, $lon, $seeLevel);
        $yr->getForecast();
        return $yr;
    }
    
    /**
     * Gets forecast for the location defined by the adress using google geocoding
     * @todo
     * @param type $locationName
     * @return \METno
     */
    static public function getForecastByLocation($locationName) {
        $lat    = 0;
        $lon    = 0;
        $yr = new METno($lat, $lon);
        $yr->getForecast();
        return $yr;
    }

    /**
     * Returns only date 2012-08-27 from 2012-08-27T18:00:00Z
     * @param type $date Date in format 2012-08-27T18:00:00Z
     * @return boolean|string
     */
    static public function getDate($date) {
        
        if (preg_match("~([\d]{4})-([\d]{2})-([\d]{2})~", $date, $match)) {
            return $match[0];
        }
        return false;
    }
    /**
     * Returns only time 18:00 from 2012-08-27T18:00:00Z
     * @param type $date Date in format 2012-08-27T18:00:00Z
     * @return boolean|string
     */
    static public function getTime($date) {
        if (preg_match("~[\d]{4}-[\d]{2}-[\d]{2}T([\d]{2}):([\d]{2})~", $date, $match) && isset($match[1]) && isset($match[2])) {            
            return $match[1].":".$match[2];
        }
        return false;
    }
    /**
     * Returns only hour 18 from 2012-08-27T18:00:00Z
     * @param type $date Date in format 2012-08-27T18:00:00Z
     * @return boolean|int
     */
    static public function getHour($date) {
        if (preg_match("~[\d]{4}-[\d]{2}-[\d]{2}T([\d]{2}):[\d]{2}~", $date, $match) && isset($match[1])) {            
            return intval($match[1]);
        }
        return false;
    }
    /**
     * Checks in attributes array if there is an attribute key and returns string
     * or float with defined decimals
     * @param SimpleXMLElement $attributes
     * @param string $attributeKey
     * @param int $floatValAndRoundByDecimals if defined, the value is floated and
     * rounded with defined decimals
     * @return int|boolean|string
     */
    static public function getAttributeValue(SimpleXMLElement $attributes,$attributeKey,$floatValAndRoundByDecimals = -1) {
        if (isset($attributes[$attributeKey])) {
            $value      = $attributes[$attributeKey]->__toString();
            if ($floatValAndRoundByDecimals != -1) {
                $value  = round(floatval($value),$floatValAndRoundByDecimals);
            }
            return $value;
        } else if ($floatValAndRoundByDecimals != -1) {
            return 0;
        }
        return false;
    }
    
    /**
     * Returns the number of decimals for windSpeed
     * @return int
     */
    static public function getWindSpeedDecimals() {
        return self::$decimalWindSpeed;
    }
    /**
     * Returns the number of decimals for percente values
     * @return int
     */
    static public function getPercenteDecimals() {
        return self::$decimalPercente;
    }
    
    /**
     * Returns the number of decimals for temperature
     * @return int
     */
    static public function getTemperatureDecimals() {
        return self::$decimalTemperature;
    }
    
    /**
     * Get an entry of forecast by offset hour, first look in forecast Array with
     * hour - offset, if not set, find hour + offset, if not found, increse offset +1
     * and start again. Max loops are 10, then boolean returned
     * @param type $hour
     * @param type $forecastArrayByHour
     * @param type $offset
     * @return boolean|SimpleXMLElement
     */
    static public function getNearestForecastForHour($hour,$forecastArrayByHour,$offset = 1) {
        $prevHour   = $hour - $offset;
        $nextHour   = $hour + $offset;
        
        if (isset($forecastArrayByHour[$nextHour])) {
            return $forecastArrayByHour[$nextHour];
        }
        
        if (isset($forecastArrayByHour[$prevHour])) {
            return $forecastArrayByHour[$prevHour];
        }
        
        if ($offset == 24) {
            return false;
        }
        
        return self::getNearestForecastForHour($hour, $forecastArrayByHour, $offset+1);
    }
    
    /**
     * Get hour for day forecast
     * @return int
     */
    static public function getHourForDayForecast() {
        return self::$dayForecastByHour;
    }
    
    /**
     * Returns hour for night forecast
     * @return int
     */
    static public function getHourForNightForecast() {        
        return self::$nightForecastByHour;
    }
    
    /**
     * Gets the hour when night starts
     * @return int
     */
    static public function getHourWhenNightStarts() {
        return self::$nightHourStart;
    }
    
    /**
     * Should the day info from the highest temperature?
     * @return int
     */
    static public function isDayForecastByHighestTemp() {
        return self::$dayForecastHighest;
    }
    /**
     * Should the night info from the lowest temperature?
     * Night detection is defined by the hour wich the night starts
     * <METnoFactory::getHourWhenNightStarts()>
     * @return int
     */
    static public function isNightForecastByLowestTemp() {
        return self::$nightForecastLowest;
    }
    
    
    
}
?>
