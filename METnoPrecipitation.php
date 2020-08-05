<?php

/**
 * @author Martin Kluska @ iMakers, s.r.o. <martin.kluska@imakers.cz>
 * @copyright iMakers, s.r.o.
 * @copyright Martin Kluska
 * @web http://imakers.cz
 * 
 */

class METnoPrecipitation
{
    /** @var float */
    protected $value = 0.0;

    /** @var float */
    protected $min = 0.0;

    /** @var float */
    protected $max = 0.0;

    public function __construct($value, $min, $max)
    {
        $this->value = $value;
        $this->min = $min;
        $this->max = $max;
    }

    public function __toString()
    {
        return "$this->value";
    }

    public function getValue()
    {
        return $this->value;
    }

    public function getMIN()
    {
        return $this->min;
    }

    public function getMAX()
    {
        return $this->max;
    }

}
