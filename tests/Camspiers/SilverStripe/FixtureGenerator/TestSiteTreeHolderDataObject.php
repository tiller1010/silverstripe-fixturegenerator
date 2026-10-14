<?php

use SilverStripe\ORM\DataObject;

class TestSiteTreeHolderDataObject extends DataObject
{
    private static $db = array(
        'Test' => 'Varchar'
    );

    public function getTitle()
    {
        return $this->Test;
    }

    public function hasOne()
    {
        return array(
            'Page' => TestSiteTreeDataObject::class
        );
    }

    public function Page()
    {
        return new TestSiteTreeDataObject(
            array(
                'ID' => 2,
                'Test' => 'RelatedPage'
            )
        );
    }
}
