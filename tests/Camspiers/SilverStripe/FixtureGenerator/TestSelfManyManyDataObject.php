<?php

use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;

class TestSelfManyManyDataObject extends DataObject
{
    private static $db = array(
        'Test' => 'Varchar'
    );

    public function getTitle()
    {
        return $this->Test;
    }

    public function manyMany()
    {
        return array(
            'Items' => self::class
        );
    }

    public function Items()
    {
        if ($this->ID != 1) {
            return new ArrayList();
        }

        return new ArrayList(
            array(
                new self(
                    array(
                        'ID' => 2,
                        'ClassName' => self::class,
                        'Test' => 'Related'
                    )
                )
            )
        );
    }
}
