<?php
    
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    //require_once '../vendor/autoload.php'; 
    //use METnoFactory;
    
    require_once 'loader_METno.php';
    
    METnoFactory::setHourForDayForecast(14);
    METnoFactory::setTemperatureDecimals(0);
    METnoFactory::setCacheDir("../../_METcache/");
    METnoFactory::setSymbolsContentType("image/svg+xml");
    METnoFactory::setDisplayErrors(true);
    //METnoFactory::setLogCurl(true);
    
    $user_agent = $_SERVER['SERVER_SOFTWARE']." Server at " . $_SERVER['HTTP_HOST']." Script ".$_SERVER['SCRIPT_NAME']. " Admin ".$_SERVER["SERVER_ADMIN"];
    
    METnoFactory::setUserAgent($user_agent);
    
    //Chopok
    //$forecast = METnoFactory::getForecastByLatLon(48.94329, 19.5906, 1992);
    
    //Chata  M.R.S.
    $forecast = METnoFactory::getForecastByLatLon(48.926495, 19.650255, 1725);
    
    if ($forecast->isSuccess()) {
        
        echo "<h1>Today</h1>";
        echo $forecast->today()->getTemperature();
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
    
    //METnoFactory::setSymbolClass("METnoCustomSymbol");
    //$forecast = $forecastBrnoCustom->getForecastForXDays(5);
    
    $forecast5days = METnoFactory::getForecastByLatLon(49.199205, 16.598866)->getForecastForXDays(5);;
    
    foreach ($forecast5days as $day) {
    	$iconPath = "img/weather/".$day->getSymbol();
    	$temp = $day->getTemperature();
    	$date = $day->getDate();
    }  
?>