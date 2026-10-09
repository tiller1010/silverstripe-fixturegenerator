<?php

use SilverStripe\ORM\DataObject;

class TestManyManyDataObject extends DataObject
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
            'Items' => 'TestManyManyRelatedDataObject'
        );
    }

    public function Items()
    {
        return new \SilverStripe\ORM\ArrayList(
            array(
                new \TestManyManyRelatedDataObject(
                    array(
                        'ID' => 2,
                        'ClassName' => 'TestManyManyRelatedDataObject',
                        'Test' => 'First'
                    )
                ),
                new \TestManyManyRelatedDataObject(
                    array(
                        'ID' => 3,
                        'ClassName' => 'TestManyManyRelatedDataObject',
                        'Test' => 'Second'
                    )
                )
            )
        );
    }
}
