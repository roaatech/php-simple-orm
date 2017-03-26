<?php

namespace ItvisionSy\SimpleORM;

/**
 * Class to allow raw SQL strings in the SQL query
 * 
 * @package ItvisionSy\SimpleORM
 * @author  Muhannad Shelleh <muhannad.shelleh@live.com>
 */
class RawSQL {

    protected $value;

    public static function make($value) {
        return new static($value);
    }

    public function __construct($value) {
        $this->value = $value;
    }

    public function __toString() {
        return (string) $this->value;
    }

}
