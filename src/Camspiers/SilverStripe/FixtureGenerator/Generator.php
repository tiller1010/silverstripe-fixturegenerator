<?php

namespace Camspiers\SilverStripe\FixtureGenerator;

use Camspiers\SilverStripe\FixtureGenerator\Dumpers\Yaml;
use IteratorAggregate;
use SilverStripe\Assets\Image;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\LinkField\Models\SiteTreeLink;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBEnum;
use SilverStripe\ORM\Hierarchy\Hierarchy;

/**
 * Class Generator
 * @package Camspiers\SilverStripe\FixtureGenerator
 */
class Generator
{
    const FALLBACK_IMAGE_URL = 'https://loremflickr.com/500/500/cat';

    const SKIPPED_RELATION_CLASSES = array(
        'Bigfork\SilverStripeOAuth\Client\Model\Passport',
        'SilverStripe\SessionManager\Models\LoginSession',
        'SilverStripe\Security\Member',
        'SilverStripe\Security\Group'
    );

    const SKIPPED_RELATION_NAMES = array(
        'FileTracking'
    );

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

    private string $baseUrl = '';

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
     * Generate YAML fixture content for a DataObject.
     *
     * @param DataObject $dataObject
     * @param array|null $relations
     * @param int $mode
     * @return string
     */
    public static function generateYaml(
        DataObject $dataObject,
        array $relations = null,
        $mode = self::RELATION_MODE_INCLUDE
    ): string {
        return (new self(new Yaml(), $relations, $mode))->process(
            new ArrayList(array($dataObject))
        );
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

        $defaults = Config::inst()->get($className, 'defaults');
        foreach ($this->getMap($dataObject) as $propertyName => $propertyValue) {
            $propertyValue = $this->resolveImageShortcodes($propertyValue, $map);
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

        if ($dataObject instanceof Image) {
            $map[$className][$title]['URL'] = $this->getImageURL($dataObject);
        }

        // Loop over the has one of this object
        if ($hasOnes = $dataObject->hasOne()) {
            foreach ($hasOnes as $relName => $relClass) {
                if ($this->isAllowedRelation("$className.$relName")) {
                    // Get the dataobject from the relation
                    $hasOne = $dataObject->$relName();

                    if (!$hasOne) {
                        continue;
                    }

                    $relClassName = $hasOne->ClassName;

                    if ($this->isSkippedRelatedObject($hasOne)) {
                        $this->reportSkippedRelation($className, $relName, $relClassName);
                        continue;
                    }

                    if (!$this->relatedObjectExists($hasOne)) {
                        continue;
                    }

                    if ($hasOne instanceof Image) {
                        $imageTitle = $this->getDataObjectTitle($hasOne);
                        if (!$this->hasDataObject($hasOne, $map)) {
                            $this->addImageToMap($hasOne, $map);
                        }
                        $map[$className][$title][$relName] = "=>$relClassName.$imageTitle";
                        continue;
                    }

                    // Only process it if it exists
                    if (!$this->hasDataObject($hasOne, $map)) {
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

                            if ($this->isSkippedRelatedObject($hasMany)) {
                                $this->reportSkippedRelation($className, $relName, $relClassName);
                                continue;
                            }

                            // Only process it if it exists
                            if ($this->relatedObjectExists($hasMany) && !$this->hasDataObject($hasMany, $map)) {
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

                    if ($this->isSkippedRelatedObject($child)) {
                        $this->reportSkippedRelation($className, 'Children', $relClassName);
                        continue;
                    }

                    // Only process it if it exists
                    if ($this->relatedObjectExists($child) && !$this->hasDataObject($child, $map)) {
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
                    if ($this->isSkippedRelatedObject($manyMany)) {
                        $this->reportSkippedRelation($className, $relName, $manyMany->ClassName);
                        continue;
                    }

                    if (!$this->relatedObjectExists($manyMany)) {
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

        return $map;
    }

    /**
     * Images can have a valid database record even when their physical asset is missing.
     *
     * @param DataObject $dataObject
     * @return bool
     */
    private function relatedObjectExists(DataObject $dataObject)
    {
        if ($dataObject instanceof Image) {
            return $dataObject->isInDB();
        }

        return $dataObject->exists();
    }

    /**
     * @param DataObject $dataObject
     * @return bool
     */
    private function isSkippedRelatedObject(DataObject $dataObject)
    {
        if ($dataObject instanceof SiteTree || $dataObject instanceof SiteTreeLink) {
            return true;
        }

        foreach (self::SKIPPED_RELATION_CLASSES as $className) {
            if (is_a($dataObject, $className)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $className
     * @param string $relationName
     * @param string $relatedClassName
     * @return void
     */
    private function reportSkippedRelation($className, $relationName, $relatedClassName)
    {
        echo "Skipping relation $className.$relationName to excluded class $relatedClassName.<br>";
    }

    /**
     * @param Image $image
     * @param array $map
     * @return void
     */
    private function addImageToMap(Image $image, array &$map)
    {
        $className = $image->ClassName;
        $title = $this->getDataObjectTitle($image);
        $name = $image->Name;

        if (!$name) {
            $name = 'Seeder_Image.jpg';
        }

        $map[$className][$title]['Name'] = $name;
        $map[$className][$title]['URL'] = $this->getImageURL($image);
    }

    /**
     * Replace image shortcodes in HTML fields and include their files in the fixture map.
     *
     * @param mixed $value
     * @param array $map
     * @return mixed
     */
    private function resolveImageShortcodes($value, array &$map)
    {
        if (!is_string($value) || stripos($value, '[image') === false) {
            return $value;
        }

        return preg_replace_callback(
            '/\[image\b((?:[^"\'\]]+|"[^"]*"|\'[^\']*\')*)\]/i',
            function (array $matches) use (&$map) {
                $attributes = $this->parseShortcodeAttributes($matches[1]);
                if (!isset($attributes['id']) || !ctype_digit((string)$attributes['id'])) {
                    return $matches[0];
                }

                $image = $this->getImageByID((int)$attributes['id']);
                if (!$image instanceof Image || !$this->relatedObjectExists($image)) {
                    return $matches[0];
                }

                if (!$this->hasDataObject($image, $map)) {
                    $this->addImageToMap($image, $map);
                }

                unset($attributes['id']);

                return $this->buildImageTag($attributes);
            },
            $value
        );
    }

    /**
     * @param string $source
     * @return array
     */
    private function parseShortcodeAttributes($source)
    {
        $attributes = array();
        preg_match_all(
            '/([a-z_:][a-z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))/i',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $rawValue = preg_replace('/^[^=]+=\s*/', '', $match[0]);
            $quote = substr($rawValue, 0, 1);
            $value = $quote === '"' || $quote === "'"
                ? substr($rawValue, 1, -1)
                : $rawValue;
            $attributes[strtolower($match[1])] = $value;
        }

        return $attributes;
    }

    /**
     * @param array $attributes
     * @return string
     */
    private function buildImageTag(array $attributes)
    {
        $html = '<img';
        foreach ($attributes as $name => $value) {
            $html .= sprintf(
                ' %s="%s"',
                $name,
                htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false)
            );
        }

        return $html . '>';
    }

    /**
     * Kept protected so applications can customise image lookup when required.
     *
     * @param int $id
     * @return Image|null
     */
    protected function getImageByID($id)
    {
        return Image::get()->byID($id);
    }

    /**
     * @param Image $image
     * @return string
     */
    private function getImageURL(Image $image)
    {
        $url = null;
        if ($image->hasMethod('getAbsoluteURL')) {
            $url = $image->getAbsoluteURL();
        }

        if (!$url && $image->Filename) {
            // Include the mapped image URL even if the physical file does not exist.
            if (!$this->baseUrl) {
                $this->baseUrl = Environment::getEnv('SS_BASE_URL') ?: Director::absoluteBaseURL();
            }
            $url = Controller::join_links($this->baseUrl, 'assets', $image->Filename);
        }

        if (is_string($url)) {
            $url = trim($url);
        }

        return $url ?: self::FALLBACK_IMAGE_URL;
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
        $title = str_replace(',', '_comma_', $title);

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

        return $this->removeForwardReferences($map);
    }

    /**
     * Remove fixture references whose targets have not been defined yet.
     *
     * Circular relations cannot be represented in both directions in a fixture file. After sorting,
     * this keeps the relation on the later record and removes its invalid counterpart on the earlier one.
     *
     * @param array $map
     * @return array
     */
    private function removeForwardReferences(array $map)
    {
        $classPositions = array_flip(array_keys($map));
        $recordPositions = array();
        foreach ($map as $className => $records) {
            $recordPositions[$className] = array_flip(array_keys($records));
        }

        foreach ($map as $className => $records) {
            foreach ($records as $recordName => $properties) {
                foreach ($properties as $propertyName => $propertyValue) {
                    if (!is_string($propertyValue)) {
                        continue;
                    }

                    $keptValues = array();
                    $hasReference = false;
                    foreach (explode(',', $propertyValue) as $item) {
                        $item = trim($item);
                        $references = $this->getFixtureReferences($item);
                        if (!$references) {
                            $keptValues[] = $item;
                            continue;
                        }

                        $hasReference = true;
                        list($relatedClass, $relatedRecord) = $references[0];
                        if (!isset($classPositions[$relatedClass])
                            || !isset($recordPositions[$relatedClass][$relatedRecord])
                            || $classPositions[$relatedClass] < $classPositions[$className]
                            || ($relatedClass === $className
                                && $recordPositions[$relatedClass][$relatedRecord]
                                    < $recordPositions[$className][$recordName])
                        ) {
                            $keptValues[] = $item;
                        }
                    }

                    if (!$hasReference) {
                        continue;
                    }
                    if ($keptValues) {
                        $map[$className][$recordName][$propertyName] = implode(', ', $keptValues);
                    } else {
                        unset($map[$className][$recordName][$propertyName]);
                    }
                }
            }
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
        $relationName = substr($relation, strrpos($relation, '.') + 1);
        if (in_array($relationName, self::SKIPPED_RELATION_NAMES, true)) {
            return false;
        }

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
