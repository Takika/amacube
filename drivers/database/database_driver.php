<?php

class database_driver extends amacube_driver
{
    private $rc;
    private $amacube;
    private $config;

    protected $db;

    public $initialized = false;

    public function __construct($amacube)
    {
        $this->amacube = $amacube;
        $this->rc      = rcmail::get_instance();
    }

    public function get_value($key, $value)
    {
    }

    public function save()
    {
        return true;
    }

}
?>
