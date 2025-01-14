<?php

namespace Camspiers\SilverStripe\FixtureGenerator\Tasks;

use Camspiers\SilverStripe\FixtureGenerator\Dumpers\Yaml;
use Camspiers\SilverStripe\FixtureGenerator\Generator;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Dev\BuildTask;

class GenerateSeederFileTask extends BuildTask
{
  protected $title = 'Generate Seeder File';
  protected $description = '';
  protected $enabled = true;

  public function run($request)
  {
    if (!Environment::isCli()) {
        $debugCSS = ModuleResourceLoader::singleton()
            ->resolveURL('silverstripe/framework:client/styles/debug.css');
        echo '<link rel="stylesheet" type="text/css" href="' . $debugCSS . '" />';
        echo '<div class="build">';
        echo '<ul>';
    }

    $Class = $request->getVar('Class');
    $ID = $request->getVar('ID');
    $Object = $Class::get()->byID($ID);

    (new Generator(
      new Yaml(
        __DIR__ . '/../seeds/MyFixture.yml'
      )
    ))->process($Object);

    if (!Environment::isCli()) {
        echo '</ul>';
        echo '</div>';
    }

  }

}

