<?php

use SilverStripe\ORM\DataObject;

class TestManyManyRelatedDataObject extends DataObject
{
    private static $db = array(
        'Test' => 'Varchar'
    );

    public function getTitle()
    {
        return $this->Test;
    }
}
