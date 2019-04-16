<?php

if (extension_loaded('xdebug')) {
    die('This script must be run with XDebug disabled.');
}

require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Process\PhpProcess;

$script = <<<'EOF'
<?php

require_once __DIR__.'/vendor/autoload.php';

class TmpObj
{
    public function getFoo()
    {
        return 'foo';
    }
}

class NestedTmpObj
{
    private $nested;

    public function __construct($nested)
    {
        $this->nested = $nested;
    }

    public function getFoo()
    {
        return $this->nested;
    }
}

$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(__DIR__.'/templates'), array(
    'cache' => __DIR__.'/cache',
    'debug' => false,
    'auto_reload' => false,
    'autoescape' => false,
    'strict_variables' => false,
));

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
        'nested' => array('bar' => array('baz' => array('foobar' => 'foobar'))),
        'bar' => array('foo' => 'foo'),
        'obj' => new TmpObj(),
        'nested_obj' => new NestedTmpObj(new NestedTmpObj(new NestedTmpObj(new TmpObj()))),
        'items' => array('foo1', 'foo2', 'foo3', 'foo4', 'foo5', 'foo6', 'foo7', 'foo8', 'foo9', 'foo10'),
    );
}

// remove the cache
system('rm -rf '.__DIR__.'/cache/');

$name = getenv('TWIG_TEMPLATE');

// without the cache
$b = microtime(true);
$template = $twig->load($name);
printf('%7.1f ', (microtime(true) - $b) * 1000);

$min = PHP_INT_MAX;
for ($j = 0; $j < 5; $j++) {
    // with the cache
    $b = microtime(true);
    for ($i = 0; $i < 500; $i++) {
        ob_start();
        $template->display($vars);
        ob_get_clean();
    }
    // time for 500 calls
    $c = microtime(true) - $b;
    if ($c < $min) {
        $min = $c;
    }
}

printf('%6.1f', $min * 1000);

EOF
;

$test = isset($argv[1]) ? $argv[1] : null;

$versions = array('1.x', '2.x', '3.x');
$items = array(
    array('empty.twig', false),
    array('empty.twig', true),
    array('simple_attribute.twig', false),
    array('simple_array_access.twig', false),
    array('nested_array_access.twig', false),
    array('simple_method_access.twig', false),
    array('simple_method_access_optimized.twig', false),
    array('nested_method_access.twig', false),
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
    printf('%15s | ', $version);
}
print "\n";
print str_repeat('-', 32 + 18 * count($versions));
print "\n";

$stats = [];

foreach ($items as $item) {
    list($template, $bigContext) = $item;
    if (null !== $test && $test.'.twig' !== $template) {
        continue;
    }
    printf('%-30s | ', str_replace('.twig', '', $template).($bigContext ? '/B' : ''));

    foreach ($versions as $version) {
        system('cd vendor/twig/twig && git checkout '.$version.' > /dev/null 2> /dev/null', $r);
        if ($r == 1) {
            print('xxx');
            exit(1);
        }

        $process = new PhpProcess($script, __DIR__, array(
            'HOME' => $_SERVER['HOME'],
            'TWIG_TEMPLATE' => $template,
            'TWIG_BIG_CONTEXT' => $bigContext,
        ));
        $process->run();

        if ($process->isSuccessful()) {
            $ret = array_values(array_filter(explode(' ', trim($process->getOutput()))));
            if (!isset($stats[$version]['compile'])) {
                $stats[$version]['compile'] = 0;
                $stats[$version]['render'] = 0;
            }
            $stats[$version]['compile'] += (float) $ret[0];
            $stats[$version]['render'] += (float) $ret[1];
        }

        printf('%15s | ', $process->isSuccessful() ? $process->getOutput() : 'ERROR'.$process->getOutput());
    }

    print "\n";
}

printf('%-30s | ', '');
foreach ($versions as $version) {
    printf('%14.0d%% | ', ($stats[$version]['render'] / $stats[$versions[0]]['render']) * 100);
}
