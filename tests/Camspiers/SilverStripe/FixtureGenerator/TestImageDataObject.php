<?php

use SilverStripe\ORM\DataObject;

class TestImageDataObject extends DataObject
{
    private static $db = array(
        'Test' => 'Varchar',
        'ImageURL' => 'Varchar',
        'ImageFilename' => 'Varchar'
    );

    public function getTitle()
    {
        return $this->Test;
    }

    public function hasOne()
    {
        return array(
            'Image' => TestImage::class
        );
    }

    public function Image()
    {
        return new TestImage(
            array(
                'ID' => 2,
                'Name' => 'actual-image.jpg',
                'Test' => 'ActualImage',
                'TestURL' => $this->ImageURL,
                'TestFilename' => $this->ImageFilename
            )
        );
    }
}
