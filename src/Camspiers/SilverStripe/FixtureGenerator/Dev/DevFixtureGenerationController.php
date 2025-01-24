<?php

namespace Camspiers\SilverStripe\FixtureGenerator\Dev;

use Camspiers\SilverStripe\FixtureGenerator\Dumpers\Yaml;
use Camspiers\SilverStripe\FixtureGenerator\Generator;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\DebugView;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

class DevFixtureGenerationController extends Controller
{
  private static $url_segment = 'fixture_generation';

  private static $url_handlers = [
    '' => 'generate_fixture',
  ];

  private static $allowed_actions = [
    'generate_fixture',
  ];

  private static $init_permissions = [
    'ADMIN',
    'ALL_DEV_ADMIN',
    'CAN_DEV_BUILD',
  ];

  protected function init(): void
  {
    parent::init();

    if (!$this->canInit()) {
      Security::permissionFailure($this);
    }
  }

  public function generate_fixture(HTTPRequest $request)
  {
    if (Director::is_cli()) {
      $this->generate();
    } else {
      $renderer = DebugView::create();
      echo $renderer->renderHeader();
      echo $renderer->renderInfo("Fixture Generation", Director::absoluteBaseURL());
      echo "<div class=\"generate_fixture\">";

      $Class = $request->getVar('Class');
      $ID = $request->getVar('ID');

      // Create a link for all DataObject classes
      if (!$Class) {
        echo '<ul>';
        $classes = ClassInfo::subclassesFor(DataObject::class, false);
        foreach ($classes as $class) {
          $class = ltrim($class, '\\');
          $link = Controller::join_links(
            $this->Link('generate_fixture'),
            "?Class=$class"
          );
          echo "<li><a href=\"$link\">Generate fixture for $class</a></li>";
        }
        echo '</ul>';

      // Create links for IDs
      } else if (!$ID) {
        echo '<ul>';
        $objects = $Class::get();
        foreach ($objects as $object) {
          $link = str_replace(self::$url_segment . '/', '', $this->Link("generate_fixture?Class=$Class&ID=$object->ID"));
          echo "<li><a href=\"$link\">Generate fixture for $Class $object->ID</a></li>";
        }
        echo '</ul>';
      } else {
        $fileName = str_replace(' ', '', ClassInfo::shortName($Class) . '_' . date('Y-m-d-H-i-s') . '.yml');
        $this->generate($request, $fileName);
        echo "<p>Fixture generated for $Class $ID at app/seeds/$fileName</p>";
      }

      echo "</div>";
      echo $renderer->renderFooter();
    }
  }

  public function canInit(): bool
  {
    return (
      Director::isDev()
      // We need to ensure that DevelopmentAdminTest can simulate permission failures when running
      // "dev/tasks" from CLI.
      || (Director::is_cli() && DevelopmentAdmin::config()->get('allow_all_cli'))
      || Permission::check(static::config()->get('init_permissions'))
    );
  }

  private function generate(HTTPRequest $request, $fileName)
  {
    $Class = $request->getVar('Class');
    $ID = $request->getVar('ID');
    $Object = $Class::get()->byID($ID);

    (new Generator(
      new Yaml(
        Director::baseFolder() . '/app/seeds/' . $fileName
      )
    ))->process($Object);
  }

}

