<?php

use SilverStripe\CMS\Model\SiteTree;

class TestSiteTreeDataObject extends SiteTree
{
    private static $db = array(
        'Test' => 'Varchar'
    );

    public function getTitle()
    {
        return $this->Test;
    }

    public function exists()
    {
        return true;
    }
}
