<?php

use SilverStripe\Assets\Image;

class TestImage extends Image
{
    private static $db = array(
        'Test' => 'Varchar',
        'TestURL' => 'Varchar',
        'TestFilename' => 'Varchar'
    );

    public function getTitle()
    {
        return $this->Test;
    }

    public function getURL($grant = true)
    {
        return $this->TestURL;
    }

    public function getAbsoluteURL()
    {
        return $this->TestURL;
    }

    public function getFilename()
    {
        return $this->TestFilename;
    }

    public function exists()
    {
        return false;
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
