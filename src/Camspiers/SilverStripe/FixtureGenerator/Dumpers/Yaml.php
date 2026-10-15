<?php

namespace Camspiers\SilverStripe\FixtureGenerator\Dumpers;

use Camspiers\SilverStripe\FixtureGenerator\DumperInterface;
use Symfony\Component\Yaml\Yaml as YamDumper;

class Yaml implements DumperInterface
{
    /**
     * @var string
     */
    private $filename;
    /**
     * @param string|null $filename
     */
    public function __construct($filename = null)
    {
        $this->filename = $filename;
    }
    /**
     * @{@inheritdoc}
     */
    public function dump(array $data)
    {
        $yaml = YamDumper::dump($data, 999);

        if ($this->filename === null) {
            return $yaml;
        }

        return file_put_contents($this->filename, $yaml);
    }
}
