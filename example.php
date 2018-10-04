<?php
    
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    //require_once '../vendor/autoload.php'; 
    //use METnoFactory;
    
    require_once 'loader_METno.php';
    
    METnoFactory::setHourForDayForecast(14);
    METnoFactory::setTemperatureDecimals(0);
    METnoFactory::setWindSpeedDecimals(0);
    METnoFactory::setCacheDir("../../_METcache/");
    METnoFactory::setDisplayErrors(true);
    //METnoFactory::setLogCurl(true);
    
    METnoSymbol::setContentType("image/svg+xml");
    
    
    $user_agent = $_SERVER['SERVER_SOFTWARE']." Server at " . $_SERVER['HTTP_HOST']." Script ".$_SERVER['SCRIPT_NAME']. " Admin ".$_SERVER["SERVER_ADMIN"];
    
    METnoFactory::setUserAgent($user_agent);
    
    $chopok = METnoFactory::getForecastByLatLon(48.94329, 19.5906, 1992);
    $chatamrs = METnoFactory::getForecastByLatLon(48.926491, 19.650251, 1725);
    
    
    $forecast = $chopok;
    
    if ($forecast->isSuccess()) {
        
        echo "<h1>Today</h1>";
        echo $forecast->today();
        echo $forecast->today()->getSymbol()->getHTML();
        
        echo "<h1>Tomorrow</h1>";
        echo $forecast->tomorrow()."/".$forecast->tomorrow()->getNightForecast().$forecast->tomorrow()->getSymbol()->getHTML();
        
        echo "<h1>In 2 days</h1>";
        echo $forecast->in2Days()."/".$forecast->in2Days()->getNightForecast().$forecast->tomorrow()->getSymbol()->getHTML();;
    } else {
        
        echo $forecast->getErrorHTML();
        
    }
    
    // forecast in loop where you get desired days
    // example using custom symbol in own directory
    // same naming as the MET.no icons
    // you need to set the custom symbol class (or create own)
    
    METnoFactory::setSymbolClass("METnoCustomSymbol");
    METnoCustomSymbol::setFileFormat("svg");
    //$forecast = $forecastBrnoCustom->getForecastForXDays(5);
    
    $forecast5days = $forecast->getForecastForXDays(5);;
    
    $array = [];
    
    foreach ($forecast5days as $day) {
        $array[] = [
        	"iconPath" => "img/weather/".$day->getSymbol(),
        	"temp" => $day->getTemperature(),
        	"date" => $day->getDate(),
        	"time" => $day->getTime(),
        	"precipitation" => $day->getPrecipitation()->getValue(),
        	"pressure" => $day->getPressure()." ".$day->getPressureUnit()
        ];
    }  
    
    echo "<h1>Loop example</h1><pre>";
    
    print_r($array);
    
    echo "</pre>";
    
    echo "<h1>JSON example</h1><pre>";
    
    echo json_encode($forecast->today(), JSON_PRETTY_PRINT);
    
    echo "</pre>";
    
?>