<?php

if (extension_loaded('xdebug')) {
    die('This script must be run with XDebug disabled.');
}

require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Process\PhpProcess;

$script = <<<'EOF'
<?php

require_once __DIR__.'/vendor/twig/twig/lib/Twig/Autoloader.php';
Twig_Autoloader::register();

class TmpObj
{
    public function getFoo()
    {
        return 'foo';
    }
}

$twig = new Twig_Environment(new Twig_Loader_Filesystem(__DIR__.'/templates'), array(
    'cache' => __DIR__.'/cache',
    'debug' => false,
    'auto_reload' => false,
    'autoescape' => false,
    'strict_variables' => false,
));
$twig->removeExtension('escaper');

if (getenv('TWIG_BIG_CONTEXT')) {
    $vars = array();
    for ($i = 1; $i < 10000; $i++) {
        $vars['foo'.$i] = $i;
    }
    for ($i = 1; $i < 10000; $i++) {
        $vars['bar']['foo'.$i] = $i;
    }
} else {
    $vars = array(
        'foo' => 'foo',
        'bar' => array('foo' => 'foo'),
        'obj' => new TmpObj(),
        'items' => array('foo1', 'foo2', 'foo3', 'foo4', 'foo5', 'foo6', 'foo7', 'foo8', 'foo9', 'foo10'),
    );
}

// remove the cache
system('rm -rf '.__DIR__.'/cache/');

// without the cache
$b = microtime(true);
$template = $twig->loadTemplate(getenv('TWIG_TEMPLATE'));
print sprintf('%7.1f ', (microtime(true) - $b) * 1000);

// with the cache
$b = microtime(true);
for ($i = 0; $i < 50; $i++) {
    ob_start();
    $template->display($vars);
    ob_get_clean();
}
print sprintf('%4.1f', (microtime(true) - $b) * 1000 / 50);

EOF
;

// 'v0.9.0', 'v1.1.2', 'v1.2.0', 'v1.3.0', 'v1.4.0', 'v1.5.1', 'v1.6.5', 'v1.11.1'
$versions = array('v0.9.0', 'v1.0.0', 'v1.12.3', 'v1.16.2', 'origin/master');
$items = array(
    array('empty.twig', false),
    array('empty.twig', true),
    array('simple_attribute.twig', false),
    array('simple_array_access.twig', false),
    array('simple_method_access.twig', false),
    array('simple_attribute_big_context.twig', true),
    array('simple_variable.twig', false),
    array('simple_variable_big_context.twig', true),
    array('simple_foreach.twig', false),
    array('simple_foreach.twig', true),
    array('empty_extends.twig', false),
    array('empty_extends.twig', true),
    array('empty_include.twig', false),
    array('empty_include.twig', true),
    array('standard.twig', false),
    array('escaping.twig', false),
);

printf('%-30s | ', '');
foreach ($versions as $version) {
    printf('%13s | ', $version);
}
print "\n";
print str_repeat('-', 32 + 16 * count($versions));
print "\n";

foreach ($items as $item) {
    list($template, $bigContext) = $item;
    printf('%-30s | ', str_replace('.twig', '', $template).($bigContext ? '/B' : ''));

    foreach ($versions as $version) {
        system('cd vendor/twig/twig && git reset --hard '.$version.' >/dev/null 2>/dev/null');

        $process = new PhpProcess($script, __DIR__, array(
            'TWIG_TEMPLATE'    => $template,
            'TWIG_BIG_CONTEXT' => $bigContext,
        ));
        $process->run();

        printf('%13s | ', $process->isSuccessful() ? $process->getOutput() : 'ERROR');
    }

    print "\n";
}
