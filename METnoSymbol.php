<?php

/**
 * @author Martin Kluska @ iMakers, s.r.o. <martin.kluska@imakers.cz>
 * @copyright iMakers, s.r.o.
 * @copyright Martin Kluska
 * @web http://imakers.cz
 * 
 * 
 * @uses METnoForecast Description
 */

class METnoSymbol {
    /**
     * For detection of day progress (night)
     * @var METnoForecast 
     */
    protected $weather;
    protected $name         = "NONE";
    protected $number       = 1;
    protected $contentType  = "image/png";
    protected $imageUrl     = "http://api.met.no/weatherapi/weathericon/1.1/?symbol={code}&content_type={content_type}";
            
    public function __construct($number,$name,$contentType = "image/png") {
        $this->name     = $name;
        $this->number   = $number;
        
        if ($contentType == "image/png" || $contentType == "image/svg+xml") {
            $this->contentType = urlencode($contentType);    
        }
    }
    
    public function setWeather(METnoForecast $weather) {
        $this->weather  = $weather;
        return $this;
    }
    
    public function getNumber() {
        return $this->number;
    }
    
    public function getName() {
        return $this->name;
    }
    
    public function getUrl() {
        $url    = str_replace("{code}", $this->number, $this->imageUrl);
        $url    = str_replace("{content_type}", $this->contentType, $url);
        /**
         * Detects if its night and show the right symbol
         */
        if ($this->isNight()) {
            $url.="&is_night=1";
        }
        
        return $url;
    }
    
    protected function isNight() {
        return is_object($this->weather) && is_object($this->weather->getMODay()) && $this->weather->isNight();
    }
    
    public function getHTML($class = '') {
        return '<img class="'.$class.'" src="'.$this->getUrl().'" alt="'.$this->name.'"/>';
    }
    
    public function __toString() {
        return $this->getUrl();
    }
}
?>
