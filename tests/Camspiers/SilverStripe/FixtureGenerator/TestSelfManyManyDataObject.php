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
        if ($this->ID == 1) {
            $relatedID = 2;
            $relatedTitle = 'Related';
        } else if ($this->ID == 2) {
            $relatedID = 1;
            $relatedTitle = 'Owner';
        } else {
            return new ArrayList();
        }

        return new ArrayList(
            array(
                new self(
                    array(
                        'ID' => $relatedID,
                        'ClassName' => self::class,
                        'Test' => $relatedTitle
                    )
                )
            )
        );
    }
}
