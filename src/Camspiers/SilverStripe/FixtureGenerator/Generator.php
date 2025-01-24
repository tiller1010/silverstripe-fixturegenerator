<?php

namespace Camspiers\SilverStripe\FixtureGenerator;

use IteratorAggregate;
use SilverStripe\Assets\Image;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBEnum;
use SilverStripe\ORM\Hierarchy\Hierarchy;

/**
 * Class Generator
 * @package Camspiers\SilverStripe\FixtureGenerator
 */
class Generator
{
    /**
     * This mode includes relations specified
     */
    const RELATION_MODE_INCLUDE = 0;
    /**
     * This mode excludes relations specified
     */
    const RELATION_MODE_EXCLUDE = 1;
    /**
     * This mode excludes generation of related objects (however relationships may still be generated)
     */
    const RELATED_OBJECT_EXCLUDE = 2;
    /**
     * @var DumperInterface
     */
    private $dumper;
    /**
     * @var array
     */
    private $relations;
    /**
     * @var int
     */
    private $mode;

    /**
     * @var string
     */
    private $pageClass;

    /**
     * @param DumperInterface $dumper    The objec to dump the output with
     * @param array           $relations An array of shell wildcard patterns
     * @param int             $mode      The mode the patterns should take, include vs. exclude
     */
    public function __construct(
        DumperInterface $dumper = null,
        array $relations = null,
        $mode = self::RELATION_MODE_INCLUDE
    ) {
        $this->dumper = $dumper;
        $this->relations = $relations;
        $this->mode = $mode;
    }

    /**
     * @param IteratorAggregate $set
     * @return mixed
     */
    public function process(IteratorAggregate $set)
    {
        $map = array();
        foreach ($set as $dataObject) {
            if (!$this->hasDataObject($dataObject, $map)) {
                $map = $this->generateFromDataObject($dataObject, $map);
            }
        }

        return $this->dumper->dump(array_reverse($map, true));
    }

    /**
     * @param DataObject $dataObject
     * @param array      $map
     * @return array
     */
    private function generateFromDataObject(DataObject $dataObject, array &$map = array())
    {
        $className = $dataObject->ClassName;
        $id = $dataObject->ID;
        $title = $this->getDataObjectTitle($dataObject);
        // If we haven't encountered a object of ClassName, add ClassName to data
        if (!isset($map[$className])) {
            $map[$className] = array();
        }

        // If the first record processed is a SiteTree subclass
        if (!$this->pageClass) {
            if (is_subclass_of($className, SiteTree::class)) {
                $this->pageClass = $className;
            } else {
                $this->pageClass = 'Page';
                $map[$this->pageClass]['TestSeederPage']['Title'] = 'Test Seeder Page';
            }
        }


        $defaults = Config::inst()->get($className, 'defaults');
        foreach ($this->getMap($dataObject) as $propertyName => $propertyValue) {
            // if value equals the default value, skip it
            if (isset($defaults[$propertyName]) && $defaults[$propertyName] == $propertyValue) {
                continue;
            }
            if ($dataObject->dbObject($propertyName) instanceof DBEnum) {
                if ($propertyValue == $dataObject->dbObject($propertyName)->getDefault()) {
                    continue;
                }
            }
            $map[$className][$title][$propertyName] = $propertyValue;
        }

        // Loop over the has one of this object
        if ($hasOnes = $dataObject->hasOne()) {
            foreach ($hasOnes as $relName => $relClass) {
                if ($this->isAllowedRelation("$className.$relName")) {
                    // Get the dataobject from the relation
                    $hasOne = $dataObject->$relName();

                    $relClassName = $hasOne->ClassName;

                    // Only process it if it exists
                    if ($hasOne->exists() && !$this->hasDataObject($hasOne, $map)) {

                        // If classname is an image, use a generic one
                        if ($relClassName == Image::class) {
                            if (empty($map[Image::class])) {
                                $map[Image::class]['TestSeederImage']['Name'] = 'Seeder_Image.jpg';
                                $map[Image::class]['TestSeederImage']['URL'] = 'https://loremflickr.com/500/500/cat';
                            }
                            $map[$className][$title][$relName] = "=>SilverStripe\Assets\Image.TestSeederImage";
                            continue;
                        } else if (is_subclass_of($relClassName, SiteTree::class)) {
                            $map[$className][$title][$relName] = '=>' . $this->pageClass . '.TestSeederPage';
                            continue;
                        }

                        if (($this->mode & self::RELATED_OBJECT_EXCLUDE) === 0) {
                            // Recursively generate a map for this object
                            $this->generateFromDataObject($hasOne, $map);
                        }
                        // Add the relation to the current dataobjects map
                        $map[$className][$title][$relName] = "=>$relClassName." . $this->getDataObjectTitle($hasOne);
                    }
                }
            }
        }

        // Loop over the has many relations
        if ($hasManys = $dataObject->hasMany()) {
            foreach ($hasManys as $relName => $relClass) {
                // Get the dataobjects from the relation
                if ($this->isAllowedRelation("$className.$relName")) {
                    $items = $dataObject->$relName();
                    // If any exist
                    if ($items instanceof IteratorAggregate && count($items) > 0) {
                        // Loops of each dataobject
                        foreach ($items as $hasMany) {
                            $relClassName = $hasMany->ClassName;

                            // Only process it if it exists
                            if ($hasMany->exists() && !$this->hasDataObject($hasMany, $map)) {
                                if (($this->mode & self::RELATED_OBJECT_EXCLUDE) === 0) {
                                    // Recursively generate a map for this object
                                    $this->generateFromDataObject($hasMany, $map);
                                }
                                // Add the relation to the original objects map
                                if (!isset($map[$className][$title][$relName])) {
                                    $map[$className][$title] = array_merge(
                                        $map[$className][$title],
                                        array(
                                            $relName => "=>$relClassName." . $this->getDataObjectTitle($hasMany)
                                        )
                                    );
                                } else {
                                    $map[$className][$title][$relName] .= ", =>$relClassName." . $this->getDataObjectTitle($hasMany);
                                }
                            }
                        }
                    }
                }
            }
        }

        // Loop over the children from Hierarch
        if ($dataObject->hasExtension(Hierarchy::class)) {
            if ($children = $dataObject->Children()) {
                // Loops of each dataobject
                foreach ($children as $child) {
                    $relClassName = $child->ClassName;

                    // Only process it if it exists
                    if ($child->exists() && !$this->hasDataObject($child, $map)) {
                        if (($this->mode & self::RELATED_OBJECT_EXCLUDE) === 0) {
                            // Recursively generate a map for this object
                            $this->generateFromDataObject($child, $map);
                        }
                        // Add the relation to the child objects map
                        $map[$relClassName][$this->getDataObjectTitle($child)]['Parent'] = "=>$className." . $title;

                        // Move Parent to the end of the array (yaml is dumped in reverse)
                        if (isset($map[$className])) {
                          $value = $map[$className];
                          unset($map[$className]);
                          $map[$className] = $value;
                        }
                    }
                }
            }
        }

        // Loop over the many many relations
        // if ($manyManys = $dataObject->many_many()) {
        //     foreach ($manyManys as $relName => $relClass) {
        //         // Get the dataobjects from the relation
        //         if ($this->isAllowedRelation("$className.$relName")) {
        //             $items = $dataObject->$relName();
        //             // If any exist
        //             if ($items instanceof IteratorAggregate && count($items) > 0) {
        //                 // Loops of each dataobject
        //                 foreach ($items as $manyMany) {
        //                     // Only process it if it exists
        //
        //                     $relClassName = $manyMany->ClassName;
        //
        //                     if ($manyMany->exists() && !$this->hasDataObject($manyMany, $map)) {
        //                         if (($this->mode & self::RELATED_OBJECT_EXCLUDE) === 0) {
        //                             // Recursively generate a map for this object
        //                             $this->generateFromDataObject($manyMany, $map);
        //                         }
        //                         // Add the relation to the original objects map
        //                         if (!isset($map[$className][$id][$relName])) {
        //                             $map[$className][$id] = array_merge(
        //                                 $map[$className][$id],
        //                                 array(
        //                                     $relName => "=>$relClassName." . $manyMany->ID
        //                                 )
        //                             );
        //                         } else {
        //                             $map[$className][$id][$relName] .= ", =>$relClassName." . $manyMany->ID;
        //                         }
        //                     }
        //                 }
        //             }
        //         }
        //     }
        // }

        // Move Image to the end of the array (yaml is dumped in reverse)
        if (isset($map[Image::class])) {
          $value = $map[Image::class];
          unset($map[Image::class]);
          $map[Image::class] = $value;
        }

        // Move pageClass to the end of the array (yaml is dumped in reverse)
        if (isset($map[$this->pageClass])) {
          $value = $map[$this->pageClass];
          unset($map[$this->pageClass]);
          $map[$this->pageClass] = $value;
        }

        return $map;
    }

    /**
     * @param DataObject $dataObject
     * @return string
     */
    private function getDataObjectTitle(DataObject $dataObject)
    {
        if ($dataObject->hasMethod('getTitle')) {
            $title = $dataObject->getTitle();
        } else if ($dataObject->hasMethod('getName')) {
            $title = $dataObject->getName();
        } else {
            $title = ClassInfo::shortName($dataObject);
        }

        return str_replace(' ', '', $title . '_' . $dataObject->ID);
    }

    /**
     * @param DataObject $dataObject
     * @param array      $map
     * @return bool
     */
    private function hasDataObject(DataObject $dataObject, array $map = array())
    {
        return isset($map[$dataObject->ClassName][$this->getDataObjectTitle($dataObject)]);
    }

    /**
     * @param DataObject $dataObject
     * @return array
     */
    private function getMap(DataObject $dataObject)
    {
        $map = $dataObject->toMap();
        unset($map['Created']);
        unset($map['LastEdited']);
        unset($map['RecordClassName']);
        unset($map['ClassName']);
        unset($map['ID']);
        unset($map['Version']);
        foreach ($map as $key => $value) {
            if (substr($key, -2) == 'ID') {
                unset($map[$key]);
            }
        }

        return $map;
    }

    /**
     * @param $relation
     * @return bool
     */
    private function isAllowedRelation($relation)
    {
        if (is_null($this->relations)) {
            return true;
        } else {
            foreach ($this->relations as $pattern) {
                if (fnmatch($pattern, $relation)) {
                    return !$this->mode;
                }
            }

            return (boolean)$this->mode;
        }
    }
}
