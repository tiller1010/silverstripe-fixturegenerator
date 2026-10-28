<?php

namespace Camspiers\SilverStripe\FixtureGenerator;

use Camspiers\SilverStripe\FixtureGenerator\Dumpers\DataArray;
use SilverStripe\ORM\DataObject;

class GeneratorTest extends \PHPUnit_Framework_TestCase
{
    protected $dumperStub;
    protected $generator;
    public function setUp()
    {
        $this->dumperStub = $this->getMock(__NAMESPACE__ . '\\DumperInterface');
        $this->dumperStub->expects($this->any())
            ->method('dump')
            ->will($this->returnArgument(0));

        $this->generator = new Generator($this->dumperStub);
    }
    public function testProcessBasic()
    {
        $this->assertEquals(
            array(
                'DataObject' => array(
                    1 => array(
                        'Test' => 'Test'
                    )
                )
            ),
            $this->generator->process(
                new \DataObjectSet(
                    array(
                        new DataObject(
                            array(
                                'ClassName' => DataObject::class,
                                'ID'        => 1,
                                'Test'      => 'Test'
                            )
                        )
                    )
                )
            )
        );
    }
    public function testGenerateYamlReturnsFixtureContentForDataObject()
    {
        $yaml = Generator::generateYaml(
            new DataObject(
                array(
                    'ClassName' => DataObject::class,
                    'ID' => 1,
                    'Test' => 'Test'
                )
            )
        );

        $this->assertInternalType('string', $yaml);
        $this->assertEquals(
            array(
                'DataObject' => array(
                    1 => array(
                        'Test' => 'Test'
                    )
                )
            ),
            \Symfony\Component\Yaml\Yaml::parse($yaml)
        );
    }
    public function testProcessHasOne()
    {
        $this->assertEquals(
            array(
                'TestHasOneDataObject' => array(
                    1 => array(
                        'Test'   => 'Test',
                        'Parent' => '=>TestHasManyDataObject.1'
                    )
                ),
                'TestHasManyDataObject' => array(
                    1 => array(
                        'Test' => 'Test'
                    )
                )
            ),
            $this->generator->process(
                new \DataObjectSet(
                    array(
                        new \TestHasOneDataObject(
                            array(
                                'ClassName' => 'TestHasOneDataObject',
                                'ID'        => 1,
                                'Test'      => 'Test'
                            )
                        )
                    )
                )
            )
        );
    }
    public function testProcessHasMany()
    {
        $this->assertEquals(
            array(
                'TestHasManyDataObject' => array(
                    1 => array(
                        'Test'  => 'Test',
                        'Items' => '=>TestHasOneDataObject.1'
                    )
                ),
                'TestHasOneDataObject' => array(
                    1 => array(
                        'Test' => 'Test'
                    )
                )
            ),
            $this->generator->process(
                new \DataObjectSet(
                    array(
                        new \TestHasManyDataObject(
                            array(
                                'ClassName' => 'TestHasManyDataObject',
                                'ID'        => 1,
                                'Test'      => 'Test'
                            )
                        )
                    )
                )
            )
        );
    }
    public function testProcessManyMany()
    {
        $result = $this->generator->process(
            new \SilverStripe\ORM\ArrayList(
                array(
                    new \TestManyManyDataObject(
                        array(
                            'ID' => 1,
                            'ClassName' => 'TestManyManyDataObject',
                            'Test' => 'Owner'
                        )
                    )
                )
            )
        );

        $this->assertSame(
            '=>TestManyManyRelatedDataObject.First_2, '
                . '=>TestManyManyRelatedDataObject.Second_3',
            $result['TestManyManyDataObject']['Owner_1']['Items']
        );
        $this->assertArrayHasKey(
            'First_2',
            $result['TestManyManyRelatedDataObject']
        );
        $this->assertArrayHasKey(
            'Second_3',
            $result['TestManyManyRelatedDataObject']
        );
    }
    public function testProcessManyManyReferencesAlreadyProcessedObjects()
    {
        $result = $this->generator->process(
            new \SilverStripe\ORM\ArrayList(
                array(
                    new \TestManyManyRelatedDataObject(
                        array(
                            'ID' => 2,
                            'ClassName' => 'TestManyManyRelatedDataObject',
                            'Test' => 'First'
                        )
                    ),
                    new \TestManyManyDataObject(
                        array(
                            'ID' => 1,
                            'ClassName' => 'TestManyManyDataObject',
                            'Test' => 'Owner'
                        )
                    )
                )
            )
        );

        $this->assertSame(
            '=>TestManyManyRelatedDataObject.First_2, '
                . '=>TestManyManyRelatedDataObject.Second_3',
            $result['TestManyManyDataObject']['Owner_1']['Items']
        );
        $this->assertLessThan(
            array_search('TestManyManyDataObject', array_keys($result)),
            array_search('TestManyManyRelatedDataObject', array_keys($result))
        );
    }
    public function testProcessManyManyOrdersRelatedRecordsOfTheSameClassFirst()
    {
        $result = $this->generator->process(
            new \SilverStripe\ORM\ArrayList(
                array(
                    new \TestSelfManyManyDataObject(
                        array(
                            'ID' => 1,
                            'ClassName' => 'TestSelfManyManyDataObject',
                            'Test' => 'Owner'
                        )
                    )
                )
            )
        );

        $this->assertSame(
            array('Related_2', 'Owner_1'),
            array_keys($result['TestSelfManyManyDataObject'])
        );
        $this->assertSame(
            '=>TestSelfManyManyDataObject.Related_2',
            $result['TestSelfManyManyDataObject']['Owner_1']['Items']
        );
        $this->assertArrayNotHasKey(
            'Items',
            $result['TestSelfManyManyDataObject']['Related_2']
        );
    }
    public function testProcessImageUsesItsURL()
    {
        $result = $this->generator->process(
            new \SilverStripe\ORM\ArrayList(
                array(
                    new \TestImageDataObject(
                        array(
                            'ID' => 1,
                            'Test' => 'Owner',
                            'ImageURL' => 'https://example.com/assets/actual-image.jpg',
                            'ImageFilename' => 'actual-image.jpg'
                        )
                    )
                )
            )
        );

        $this->assertSame(
            'https://example.com/assets/actual-image.jpg',
            $result['TestImage']['ActualImage_2']['URL']
        );
        $this->assertSame(
            '=>TestImage.ActualImage_2',
            $result['TestImageDataObject']['Owner_1']['Image']
        );
        $this->assertArrayNotHasKey('Page', $result);
    }
    public function testProcessMissingImageUsesItsMappedURL()
    {
        $result = $this->generator->process(
            new \SilverStripe\ORM\ArrayList(
                array(
                    new \TestImageDataObject(
                        array(
                            'ID' => 1,
                            'Test' => 'Owner',
                            'ImageURL' => '',
                            'ImageFilename' => 'missing-image.jpg'
                        )
                    )
                )
            )
        );

        $url = $result['TestImage']['ActualImage_2']['URL'];
        $this->assertNotSame(Generator::FALLBACK_IMAGE_URL, $url);
        $this->assertSame(
            '/assets/missing-image.jpg',
            substr($url, -strlen('/assets/missing-image.jpg'))
        );
    }
    public function testProcessImageFallsBackWithoutURLOrFilename()
    {
        $result = $this->generator->process(
            new \SilverStripe\ORM\ArrayList(
                array(
                    new \TestImageDataObject(
                        array(
                            'ID' => 1,
                            'Test' => 'Owner',
                            'ImageURL' => '',
                            'ImageFilename' => ''
                        )
                    )
                )
            )
        );

        $this->assertSame(
            Generator::FALLBACK_IMAGE_URL,
            $result['TestImage']['ActualImage_2']['URL']
        );
    }
    public function testProcessImageAsRootObjectUsesItsURL()
    {
        $result = $this->generator->process(
            new \SilverStripe\ORM\ArrayList(
                array(
                    new \TestImage(
                        array(
                            'ID' => 2,
                            'Name' => 'root-image.jpg',
                            'Test' => 'RootImage',
                            'TestURL' => 'https://example.com/assets/root-image.jpg',
                            'TestFilename' => 'root-image.jpg'
                        )
                    )
                )
            )
        );

        $this->assertSame(
            'https://example.com/assets/root-image.jpg',
            $result['TestImage']['RootImage_2']['URL']
        );
    }
    public function testProcessImageShortcodeCreatesImageFixtureAndNormalImageTag()
    {
        $generator = new \TestImageShortcodeGenerator(
            $this->dumperStub,
            new \TestImage(
                array(
                    'ID' => 218,
                    'Name' => 'Picture1.png',
                    'Test' => 'Picture1',
                    'TestURL' => 'https://example.com/assets/Picture1.png',
                    'TestFilename' => 'Picture1.png'
                )
            )
        );
        $content = '<p>Before</p>[image src="/assets/Picture1.png" id="218" '
            . 'width="800" height="590" class="leftAlone ss-htmleditorfield-file image" '
            . 'title="Picture1"]<p>After</p>';

        $result = $generator->process(
            new \SilverStripe\ORM\ArrayList(
                array(
                    new DataObject(
                        array(
                            'ClassName' => DataObject::class,
                            'ID' => 1,
                            'Content' => $content
                        )
                    )
                )
            )
        );

        $this->assertSame(
            '<p>Before</p><img src="/assets/Picture1.png" width="800" height="590" '
                . 'class="leftAlone ss-htmleditorfield-file image" title="Picture1"><p>After</p>',
            $result['DataObject'][1]['Content']
        );
        $this->assertSame(
            array(
                'Name' => 'Picture1.png',
                'URL' => 'https://example.com/assets/Picture1.png'
            ),
            $result['TestImage']['Picture1_218']
        );
    }
    public function testProcessUnresolvedImageShortcodeLeavesItUntouched()
    {
        $generator = new \TestImageShortcodeGenerator(
            $this->dumperStub,
            new \TestImage(
                array(
                    'ID' => 218,
                    'Name' => 'Picture1.png',
                    'Test' => 'Picture1'
                )
            )
        );
        $content = '[image src="/assets/missing.png" id="999"]';

        $result = $generator->process(
            new \SilverStripe\ORM\ArrayList(
                array(
                    new DataObject(
                        array(
                            'ClassName' => DataObject::class,
                            'ID' => 1,
                            'Content' => $content
                        )
                    )
                )
            )
        );

        $this->assertSame($content, $result['DataObject'][1]['Content']);
        $this->assertArrayNotHasKey('TestImage', $result);
    }
    public function testProcessSkipsRelatedPagesAndTheirFixtures()
    {
        $result = $this->generator->process(
            new \SilverStripe\ORM\ArrayList(
                array(
                    new \TestSiteTreeHolderDataObject(
                        array(
                            'ID' => 1,
                            'Test' => 'Owner'
                        )
                    )
                )
            )
        );

        $this->assertArrayNotHasKey('Page', $result['TestSiteTreeHolderDataObject']['Owner_1']);
        $this->assertArrayNotHasKey('TestSiteTreeDataObject', $result);
        $this->assertArrayNotHasKey('Page', $result);
    }
    public function testSkipsSessionAndSecurityRelationClasses()
    {
        $method = new \ReflectionMethod($this->generator, 'isSkippedRelatedObject');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->generator, new \SilverStripe\Security\Member()));
        $this->assertTrue($method->invoke($this->generator, new \SilverStripe\Security\Group()));
        $this->assertContains(
            'Bigfork\SilverStripeOAuth\Client\Model\Passport',
            Generator::SKIPPED_RELATION_CLASSES
        );
        $this->assertContains(
            'SilverStripe\SessionManager\Models\LoginSession',
            Generator::SKIPPED_RELATION_CLASSES
        );
    }
    public function testSkipsFileTrackingRelations()
    {
        $method = new \ReflectionMethod($this->generator, 'isAllowedRelation');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($this->generator, 'TestDataObject.FileTracking'));
        $this->assertTrue($method->invoke($this->generator, 'TestDataObject.Images'));
        $this->assertContains('FileTracking', Generator::SKIPPED_RELATION_NAMES);

        $includingGenerator = new Generator(
            $this->dumperStub,
            array('TestDataObject.FileTracking')
        );
        $this->assertFalse($method->invoke($includingGenerator, 'TestDataObject.FileTracking'));
    }
    public function testProcessPatternInclude()
    {
        $g = new Generator(
            $this->dumperStub,
            array(
                'TestHasManyDataObject.Items'
            )
        );
        $this->assertEquals(
            array(
                'TestHasManyDataObject' => array(
                    1 => array(
                        'Test'  => 'Test',
                        'Items' => '=>TestHasOneDataObject.1'
                    )
                ),
                'TestHasOneDataObject' => array(
                    1 => array(
                        'Test' => 'Test'
                    )
                )
            ),
            $g->process(
                new \DataObjectSet(
                    array(
                        new \TestHasManyDataObject(
                            array(
                                'ClassName' => 'TestHasManyDataObject',
                                'ID'        => 1,
                                'Test'      => 'Test'
                            )
                        )
                    )
                )
            )
        );
        $g = new Generator(
            $this->dumperStub,
            array(
                'TestHasManyDataObject.*'
            )
        );
        $this->assertEquals(
            array(
                'TestHasManyDataObject' => array(
                    1 => array(
                        'Test'  => 'Test',
                        'Items' => '=>TestHasOneDataObject.1'
                    )
                ),
                'TestHasOneDataObject' => array(
                    1 => array(
                        'Test' => 'Test'
                    )
                )
            ),
            $g->process(
                new \DataObjectSet(
                    array(
                        new \TestHasManyDataObject(
                            array(
                                'ClassName' => 'TestHasManyDataObject',
                                'ID'        => 1,
                                'Test'      => 'Test'
                            )
                        )
                    )
                )
            )
        );
        $g = new Generator(
            $this->dumperStub,
            array(
                'TestHasManyDataObject.Test'
            )
        );
        $this->assertEquals(
            array(
                'TestHasManyDataObject' => array(
                    1 => array(
                        'Test'  => 'Test'
                    )
                )
            ),
            $g->process(
                new \DataObjectSet(
                    array(
                        new \TestHasManyDataObject(
                            array(
                                'ClassName' => 'TestHasManyDataObject',
                                'ID'        => 1,
                                'Test'      => 'Test'
                            )
                        )
                    )
                )
            )
        );
        $g = new Generator(
            $this->dumperStub,
            array(
                'TestHasManyDataObject.Item?'
            )
        );
        $this->assertEquals(
            array(
                'TestHasManyDataObject' => array(
                    1 => array(
                        'Test'  => 'Test',
                        'Items' => '=>TestHasOneDataObject.1'
                    )
                ),
                'TestHasOneDataObject' => array(
                    1 => array(
                        'Test' => 'Test'
                    )
                )
            ),
            $g->process(
                new \DataObjectSet(
                    array(
                        new \TestHasManyDataObject(
                            array(
                                'ClassName' => 'TestHasManyDataObject',
                                'ID'        => 1,
                                'Test'      => 'Test'
                            )
                        )
                    )
                )
            )
        );
    }
    public function testProcessPatternExclude()
    {
        $g = new Generator(
            $this->dumperStub,
            array(
                '*'
            ),
            Generator::RELATION_MODE_EXCLUDE
        );
        $this->assertEquals(
            array(
                'TestHasManyDataObject' => array(
                    1 => array(
                        'Test'  => 'Test'
                    )
                )
            ),
            $g->process(
                new \DataObjectSet(
                    array(
                        new \TestHasManyDataObject(
                            array(
                                'ClassName' => 'TestHasManyDataObject',
                                'ID'        => 1,
                                'Test'      => 'Test'
                            )
                        )
                    )
                )
            )
        );
        $g = new Generator(
            $this->dumperStub,
            array(
                'TestHasManyDataObject.*'
            ),
            Generator::RELATION_MODE_EXCLUDE
        );
        $this->assertEquals(
            array(
                'TestHasManyDataObject' => array(
                    1 => array(
                        'Test'  => 'Test'
                    )
                )
            ),
            $g->process(
                new \DataObjectSet(
                    array(
                        new \TestHasManyDataObject(
                            array(
                                'ClassName' => 'TestHasManyDataObject',
                                'ID'        => 1,
                                'Test'      => 'Test'
                            )
                        )
                    )
                )
            )
        );
    }
}
