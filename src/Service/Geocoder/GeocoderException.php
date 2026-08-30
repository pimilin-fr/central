<?php
namespace App\Service\Geocoder;

use Exception;

class GeocoderException extends Exception{
    
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null) {
        return parent::__construct(trim("[GEO-Error] ".$message), $code, $previous);
    }
}
