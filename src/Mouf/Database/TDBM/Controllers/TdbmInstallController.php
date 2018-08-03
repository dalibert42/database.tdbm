<?php

namespace Mouf\Database\TDBM\Controllers;

use Mouf\Composer\ClassNameMapper;
use Mouf\Actions\InstallUtils;
use Mouf\Console\ConsoleUtils;
use TheCodingMachine\TDBM\Commands\GenerateCommand;
use TheCodingMachine\TDBM\Configuration;
use Mouf\Database\TDBM\MoufConfiguration;
use TheCodingMachine\TDBM\Utils\Annotation\AnnotationParser;
use TheCodingMachine\TDBM\Utils\Annotation\Autoincrement;
use TheCodingMachine\TDBM\Utils\Annotation\Bean;
use TheCodingMachine\TDBM\Utils\Annotation\UUID;
use TheCodingMachine\TDBM\Utils\DefaultNamingStrategy;
use Mouf\Database\TDBM\Utils\MoufDiListener;
use Mouf\MoufManager;
use Mouf\Html\HtmlElement\HtmlBlock;
use Mouf\Mvc\Splash\Controllers\Controller;

/**
 * The controller used in the TDBM install process.
 *
 * @Component
 */
class TdbmInstallController extends Controller
{
    /**
     * @var HtmlBlock
     */
    public $content;

    public $selfedit;

    /**
     * The active MoufManager to be edited/viewed.
     *
     * @var MoufManager
     */
    public $moufManager;

    /**
     * The template used by the main page for mouf.
     *
     * @Property
     * @Compulsory
     *
     * @var TemplateInterface
     */
    public $template;

    /**
     * Displays the first install screen.
     *
     * @Action
     * @Logged
     *
     * @param string $selfedit If true, the name of the component must be a component from the Mouf framework itself (internal use only)
     */
    public function defaultAction($selfedit = 'false')
    {
        $this->selfedit = $selfedit;

        if ($selfedit == 'true') {
            $this->moufManager = MoufManager::getMoufManager();
        } else {
            $this->moufManager = MoufManager::getMoufManagerHiddenInstance();
        }

        $this->content->addFile(__DIR__.'/../../../../views/installStep1.php', $this);
        $this->template->toHtml();
    }

    /**
     * Skips the install process.
     *
     * @Action
     * @Logged
     *
     * @param string $selfedit If true, the name of the component must be a component from the Mouf framework itself (internal use only)
     */
    public function skip($selfedit = 'false')
    {
        InstallUtils::continueInstall($selfedit == 'true');
    }

    protected $daoNamespace;
    protected $beanNamespace;
    protected $autoloadDetected;
    //protected $storeInUtc;
    protected $useCustomComposer = false;
    protected $composerFile;

    /**
     * Displays the second install screen.
     *
     * @Action
     * @Logged
     *
     * @param string $selfedit If true, the name of the component must be a component from the Mouf framework itself (internal use only)
     */
    public function configure($selfedit = 'false')
    {
        $this->selfedit = $selfedit;

        if ($selfedit == 'true') {
            $this->moufManager = MoufManager::getMoufManager();
        } else {
            $this->moufManager = MoufManager::getMoufManagerHiddenInstance();
        }

        // Let's start by performing basic checks about the instances we assume to exist.
        if (!$this->moufManager->instanceExists('dbalConnection')) {
            $this->displayErrorMsg("The TDBM install process assumes your database connection instance is already created, and that the name of this instance is 'dbalConnection'. Could not find the 'dbalConnection' instance.");

            return;
        }

        if ($this->moufManager->has('tdbmConfiguration')) {
            $tdbmConfiguration = $this->moufManager->getInstanceDescriptor('tdbmConfiguration');

            $this->beanNamespace = $tdbmConfiguration->getConstructorArgumentProperty('beanNamespace')->getValue();
            $this->daoNamespace = $tdbmConfiguration->getConstructorArgumentProperty('daoNamespace')->getValue();
        } else {
            // Old TDBM 4.2 fallback
            $this->daoNamespace = $this->moufManager->getVariable('tdbmDefaultDaoNamespace_tdbmService');
            $this->beanNamespace = $this->moufManager->getVariable('tdbmDefaultBeanNamespace_tdbmService');
        }

        if ($this->daoNamespace == null && $this->beanNamespace == null) {
            $classNameMapper = ClassNameMapper::createFromComposerFile(__DIR__.'/../../../../../../../../composer.json');

            $autoloadNamespaces = $classNameMapper->getManagedNamespaces();
            if ($autoloadNamespaces) {
                $this->autoloadDetected = true;
                $rootNamespace = $autoloadNamespaces[0];
                $this->daoNamespace = $rootNamespace.'Dao';
                $this->beanNamespace = $rootNamespace.'Model';
            } else {
                $this->autoloadDetected = false;
                $this->daoNamespace = 'YourApplication\\Dao';
                $this->beanNamespace = 'YourApplication\\Model';
            }
        } else {
            $this->autoloadDetected = true;
        }
        $this->defaultPath = true;
        $this->storePath = '';

        $this->castDatesToDateTime = true;

        $this->content->addFile(__DIR__.'/../../../../views/installStep2.php', $this);
        $this->template->toHtml();
    }

    /**
     * This action generates the TDBM instance, then the DAOs and Beans.
     *
     * @Action
     *
     * @param string $daonamespace
     * @param string $beannamespace
     * @param string $selfedit
     *
     * @throws \Mouf\MoufException
     */
    public function generate($daonamespace, $beannamespace, /*$storeInUtc = 0,*/ $selfedit = 'false', $defaultPath = false, $storePath = '')
    {
        $this->selfedit = $selfedit;

        if ($selfedit == 'true') {
            $this->moufManager = MoufManager::getMoufManager();
        } else {
            $this->moufManager = MoufManager::getMoufManagerHiddenInstance();
        }

        $doctrineCache = $this->moufManager->getInstanceDescriptor('defaultDoctrineCache');

        $migratingFrom42 = false;
        if ($this->moufManager->has('tdbmService') && !$this->moufManager->has('tdbmConfiguration')) {
            $migratingFrom42 = true;
        }

        if ($this->moufManager->has('tdbmService') && $this->moufManager->getInstanceDescriptor('tdbmService')->getClassName() === 'Mouf\\Database\\TDBM\\TDBMService') {
            $this->migrateNamespaceTo50($this->moufManager);
        }

        $annotationParser = InstallUtils::getOrCreateInstance(AnnotationParser::class, AnnotationParser::class, $this->moufManager);
        $annotationParser->getConstructorArgumentProperty('annotations')->setValue([
            'UUID' => UUID::class,
            'Autoincrement' => Autoincrement::class,
            'Bean' => Bean::class
        ]);

        $namingStrategy = InstallUtils::getOrCreateInstance('namingStrategy', DefaultNamingStrategy::class, $this->moufManager);
        if ($migratingFrom42) {
            // Let's setup the naming strategy for compatibility
            $namingStrategy->getSetterProperty('setBeanPrefix')->setValue('');
            $namingStrategy->getSetterProperty('setBeanSuffix')->setValue('Bean');
            $namingStrategy->getSetterProperty('setBaseBeanPrefix')->setValue('');
            $namingStrategy->getSetterProperty('setBaseBeanSuffix')->setValue('BaseBean');
            $namingStrategy->getSetterProperty('setDaoPrefix')->setValue('');
            $namingStrategy->getSetterProperty('setDaoSuffix')->setValue('Dao');
            $namingStrategy->getSetterProperty('setBaseDaoPrefix')->setValue('');
            $namingStrategy->getSetterProperty('setBaseDaoSuffix')->setValue('BaseDao');
        }
        if ($namingStrategy->getClassName() === DefaultNamingStrategy::class) {
            $namingStrategy->getConstructorArgumentProperty('annotationParser')->setValue($this->moufManager->getInstanceDescriptor(AnnotationParser::class));
            $namingStrategy->getConstructorArgumentProperty('schemaManager')->setOrigin('php')->setValue('return $container->get(\'dbalConnection\')->getSchemaManager();');
        }


        if (!$this->moufManager->instanceExists('tdbmConfiguration')) {
            $moufListener = InstallUtils::getOrCreateInstance(MoufDiListener::class, MoufDiListener::class, $this->moufManager);

            $tdbmConfiguration = $this->moufManager->createInstance(MoufConfiguration::class)->setName('tdbmConfiguration');
            $tdbmConfiguration->getConstructorArgumentProperty('connection')->setValue($this->moufManager->getInstanceDescriptor('dbalConnection'));
            $tdbmConfiguration->getConstructorArgumentProperty('cache')->setValue($doctrineCache);
            $tdbmConfiguration->getConstructorArgumentProperty('namingStrategy')->setValue($namingStrategy);
            $tdbmConfiguration->getProperty('daoFactoryInstanceName')->setValue('daoFactory');
            $tdbmConfiguration->getConstructorArgumentProperty('generatorListeners')->setValue([$moufListener]);

            // Let's also delete the tdbmService if migrating versions <= 4.2
            if ($migratingFrom42) {
                $this->moufManager->removeComponent('tdbmService');
            }
        } else {
            $tdbmConfiguration = $this->moufManager->getInstanceDescriptor('tdbmConfiguration');
        }

        if (!$this->moufManager->instanceExists('tdbmService')) {
            $tdbmService = $this->moufManager->createInstance('TheCodingMachine\\TDBM\\TDBMService')->setName('tdbmService');
            $tdbmService->getConstructorArgumentProperty('configuration')->setValue($tdbmConfiguration);
        }

        // We declare our instance of the Symfony command as a Mouf instance
        $generateCommand = InstallUtils::getOrCreateInstance('generateCommand', GenerateCommand::class, $this->moufManager);
        $generateCommand->getConstructorArgumentProperty('configuration')->setValue($tdbmConfiguration);

        // We register that instance descriptor using "ConsoleUtils"
        $consoleUtils = new ConsoleUtils($this->moufManager);
        $consoleUtils->registerCommand($generateCommand);

        $this->moufManager->rewriteMouf();

        TdbmController::generateDaos($this->moufManager, 'tdbmService', $daonamespace, $beannamespace, 'daoFactory', $selfedit, /*$storeInUtc,*/ $defaultPath, $storePath);

        InstallUtils::continueInstall($selfedit == 'true');
    }

    protected $errorMsg;

    private function displayErrorMsg($msg)
    {
        $this->errorMsg = $msg;
        $this->content->addFile(__DIR__.'/../../../../views/installError.php', $this);
        $this->template->toHtml();
    }

    /**
     * Migrate classes from old 4.x namespace (Mouf\Database\TDBM) to new 5.x namespace (TheCodingMachine\TDBM)
     *
     * @param MoufManager $moufManager
     */
    private function migrateNamespaceTo50(MoufManager $moufManager)
    {
        $instanceList = $moufManager->getInstancesList();

        $migratedClasses = [
            'Mouf\\Database\\TDBM\\Configuration' => 'TheCodingMachine\\TDBM\\Configuration',
            'Mouf\\Database\\TDBM\\TDBMService' => 'TheCodingMachine\\TDBM\\TDBMService',
            'Mouf\\Database\\TDBM\\Commands\\GenerateCommand' => 'TheCodingMachine\\TDBM\\Commands\\GenerateCommand',
            'Mouf\\Database\\TDBM\\Utils\\DefaultNamingStrategy' => 'TheCodingMachine\\TDBM\\Utils\\DefaultNamingStrategy',
        ];

        foreach ($instanceList as $instanceName => $className) {
            if (isset($migratedClasses[$className])) {
                $moufManager->alterClass($instanceName, $migratedClasses[$className]);
            }
        }
    }
}
