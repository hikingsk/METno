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

class METnoSymbol implements JsonSerializable {

    protected $weather;
    protected $name         = "NONE";
    protected $number       = 1;
    static protected $contentType  = "image/png";
    protected $imageUrl     = "https://api.met.no/weatherapi/weathericon/1.1/?symbol={code}&content_type={content_type}";
    private $icons = [
	    'has_night_version' => ['01', '02', '03', '05', '06', '07', '08', '20', '21', '24', '25', '26', '27', '28', '29', '40', '41', '42', '43', '44', '45'] 
    ];
            
    public function __construct($number,$name) {
        $this->name     = $name;
        $this->number   = $number;
    }
    
    static public function setContentType($contentType) {
        if ($contentType == "image/png" || $contentType == "image/svg+xml") {
            self::$contentType = urlencode($contentType);
        }
    }
    
    static public function getContentType() {
        return self::$contentType;
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
        $url    = str_replace("{content_type}", $this->getContentType(), $url);
        /**
         * Detects if its night and show the right symbol
         */
        if ($this->isNight()) {
            $url.="&is_night=1";
        }
        
        return $url;
    }
    
    public function getFileName(){
	    
	    $number = sprintf('%02d', $this->getNumber());
	    
	    if(in_array($number, $this->icons['has_night_version'])){
		    
		    if($this->isNight()){
			    return "{$number}n.png";
		    } else {
			    return "{$number}d.png";
		    }   
	    }
	    
		return "{$number}.png";
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
    
    /**
     * Exports the metnosymbol into serializable array
     * @return array
     */
    
    function jsonSerialize() {
        return array(
            "id" => $this->getNumber(),
            "name" => $this->getName(),
            "is" => array(
                "night" => $this->isNight()
            ),
            "url" => $this->getUrl()
        );
    }
}
?>
