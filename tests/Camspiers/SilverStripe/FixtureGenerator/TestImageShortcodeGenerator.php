<?php

use Camspiers\SilverStripe\FixtureGenerator\DumperInterface;
use Camspiers\SilverStripe\FixtureGenerator\Generator;

class TestImageShortcodeGenerator extends Generator
{
    private $image;

    public function __construct(DumperInterface $dumper, TestImage $image)
    {
        parent::__construct($dumper);
        $this->image = $image;
    }

    protected function getImageByID($id)
    {
        return (int)$this->image->ID === (int)$id ? $this->image : null;
    }
}
