<?php

use SilverStripe\Assets\Image;

class TestImage extends Image
{
    private static $db = array(
        'Test' => 'Varchar',
        'TestURL' => 'Varchar'
    );

    public function getTitle()
    {
        return $this->Test;
    }

    public function getURL($grant = true)
    {
        return $this->TestURL;
    }

    public function exists()
    {
        return true;
    }

    public function hasOne()
    {
        return array();
    }

    public function hasMany($classOnly = true)
    {
        return array();
    }

    public function manyMany()
    {
        return array();
    }

    public function hasExtension($extension)
    {
        return false;
    }
}
