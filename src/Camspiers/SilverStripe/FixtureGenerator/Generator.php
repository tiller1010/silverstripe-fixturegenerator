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

        $map = array_reverse($map, true);

        return $this->dumper->dump($this->orderMapByReferences($map));
    }

    /**
     * @param DataObject $dataObject
     * @param array      $map
     * @return array
     */
    private function generateFromDataObject(DataObject $dataObject, array &$map = array())
    {
        $className = $dataObject->ClassName;
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

                    if (!$hasOne || !$hasOne->exists()) continue;

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
                            $map[$className][$title][$relName] = '=>' . $this->pageClass . $this->getDataObjectTitle($hasOne);
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
                                $hasOneFieldName = DataObject::getSchema()->getRemoteJoinField($className, $relName);
                                $map[$relClassName][$this->getDataObjectTitle($hasMany)][$hasOneFieldName] = "=>$className." . $title;

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
            }
        }

        // Loop over the children from Hierarchy
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
        if ($manyManys = $dataObject->manyMany()) {
            foreach ($manyManys as $relName => $relClass) {
                if (!$this->isAllowedRelation("$className.$relName")) {
                    continue;
                }

                $items = $dataObject->$relName();
                if (!$items instanceof IteratorAggregate || count($items) === 0) {
                    continue;
                }

                foreach ($items as $manyMany) {
                    if (!$manyMany->exists()) {
                        continue;
                    }

                    $relClassName = $manyMany->ClassName;
                    $relTitle = $this->getDataObjectTitle($manyMany);

                    if (!$this->hasDataObject($manyMany, $map)
                        && ($this->mode & self::RELATED_OBJECT_EXCLUDE) === 0
                    ) {
                        $this->generateFromDataObject($manyMany, $map);
                    }

                    $reference = "=>$relClassName.$relTitle";
                    if (!isset($map[$className][$title][$relName])) {
                        $map[$className][$title][$relName] = $reference;
                    } else {
                        $map[$className][$title][$relName] .= ", $reference";
                    }
                }
            }
        }

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
        $title = '';

        if ($dataObject->hasMethod('getTitle')) {
            $title = $dataObject->getTitle();
        }
        
        if (!$title && $dataObject->hasMethod('getName')) {
            $title = $dataObject->getName();
        }
        
        if (!$title) {
            $title = ClassInfo::shortName($dataObject);
        }

        $title = str_replace(' ', '', $title . '_' . $dataObject->ID);
        $title = str_replace('.', '_dot_', $title);

        return $title;
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
     * Order classes and records so fixture references always point backwards in the YAML file.
     *
     * @param array $map
     * @return array
     */
    private function orderMapByReferences(array $map)
    {
        $classDependencies = array();
        $recordDependencies = array();

        foreach ($map as $className => $records) {
            foreach ($records as $recordName => $properties) {
                foreach ($properties as $propertyValue) {
                    foreach ($this->getFixtureReferences($propertyValue) as $reference) {
                        list($relatedClass, $relatedRecord) = $reference;
                        if (!isset($map[$relatedClass])) {
                            continue;
                        }

                        if ($relatedClass === $className) {
                            if (isset($map[$relatedClass][$relatedRecord])) {
                                $recordDependencies[$className][$recordName][$relatedRecord] = true;
                            }
                        } else {
                            $classDependencies[$className][$relatedClass] = true;
                        }
                    }
                }
            }
        }

        $map = $this->sortByDependencies($map, $classDependencies);
        foreach ($map as $className => $records) {
            $dependencies = isset($recordDependencies[$className])
                ? $recordDependencies[$className]
                : array();
            $map[$className] = $this->sortByDependencies($records, $dependencies);
        }

        return $map;
    }

    /**
     * @param mixed $value
     * @return array
     */
    private function getFixtureReferences($value)
    {
        if (!is_string($value)) {
            return array();
        }

        $references = array();
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if (strpos($item, '=>') !== 0) {
                continue;
            }

            $reference = substr($item, 2);
            $separator = strpos($reference, '.');
            if ($separator === false) {
                continue;
            }

            $references[] = array(
                substr($reference, 0, $separator),
                substr($reference, $separator + 1)
            );
        }

        return $references;
    }

    /**
     * Stable topological sort. Back-edges are ignored when references are cyclic.
     *
     * @param array $items
     * @param array $dependencies
     * @return array
     */
    private function sortByDependencies(array $items, array $dependencies)
    {
        $result = array();
        $states = array();
        $visit = function ($key) use (&$visit, &$result, &$states, $items, $dependencies) {
            if (isset($states[$key])) {
                return;
            }

            $states[$key] = 'visiting';
            if (isset($dependencies[$key])) {
                foreach ($dependencies[$key] as $dependency => $unused) {
                    if (isset($items[$dependency]) && !isset($states[$dependency])) {
                        $visit($dependency);
                    }
                }
            }
            $states[$key] = 'visited';
            $result[$key] = $items[$key];
        };

        foreach ($items as $key => $unused) {
            $visit($key);
        }

        return $result;
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
