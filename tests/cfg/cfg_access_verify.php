<?php
declare(strict_types=1);

// Verifies every behavior statement (B1-B22) and every rule idiom (R-checks)
// of docs/reference/cfg-access.md against the real citomni/kernel source.
//
// Usage: php cfg_access_verify.php <path to citomni/kernel/src>
// Exit code 0 when every check passes.

$src = \rtrim($argv[1] ?? '', '/\\');
if ($src === '' || !\is_file($src . '/Cfg.php')) {
	\fwrite(\STDERR, "Usage: php cfg_access_verify.php <path to citomni/kernel/src>\n");
	exit(2);
}
\spl_autoload_register(static function (string $class) use ($src): void {
	$prefix = 'CitOmni\\Kernel\\';
	if (\str_starts_with($class, $prefix)) {
		$file = $src . '/' . \str_replace('\\', '/', \substr($class, \strlen($prefix))) . '.php';
		if (\is_file($file)) {
			require $file;
		}
	}
});

use CitOmni\Kernel\App;
use CitOmni\Kernel\Cfg;

enum ProbeLevel: string {
	case Info = 'info';
}

$fail = 0;
$pass = 0;
$notices = [];
$throwOnDeprecated = false;
\set_error_handler(static function (int $no, string $msg) use (&$notices, &$throwOnDeprecated): bool {
	if ($throwOnDeprecated && $no === \E_DEPRECATED) {
		throw new \ErrorException($msg, 0, $no);
	}
	$notices[] = $msg;
	return true;
});

function check(string $id, string $claim, bool $ok, string $detail = ''): void {
	global $fail, $pass;
	$ok ? $pass++ : $fail++;
	echo ($ok ? 'PASS ' : 'FAIL ') . \str_pad($id, 4) . ' ' . $claim . ($detail !== '' ? '  [' . $detail . ']' : '') . \PHP_EOL;
}

function caught(callable $fn): ?\Throwable {
	try {
		$fn();
	} catch (\Throwable $e) {
		return $e;
	}
	return null;
}

function freshCfg(): Cfg {
	return new Cfg([
		'pkg' => [
			'map'      => ['a' => 1, 'b' => 2],
			'list'     => ['x', 'y'],
			'intmap'   => [10 => 'ten', 20 => 'twenty'],
			'unordered'=> [1 => 'b', 0 => 'a'],
			'empty'    => [],
			'nullval'  => null,
			'falseval' => false,
			'zero'     => 0,
			'str'      => 'text',
			'emptystr' => '',
			'enum'     => ProbeLevel::Info,
			'float'    => 1.5,
			'deep'     => ['inner' => ['leaf' => 'v']],
			'profiles' => ['main' => ['host' => 'h']],
		],
	]);
}

$cfg = freshCfg();

// -- B1 App::$cfg ---------------------------------------------------------------
$prop = new \ReflectionProperty(App::class, 'cfg');
check('B1', 'App::$cfg is public, readonly and typed Cfg', $prop->isPublic() && $prop->isReadOnly() && (string)$prop->getType() === Cfg::class);

// -- B2-B7 Node types -------------------------------------------------------------
check('B2', 'a map becomes a Cfg node', $cfg->pkg->map instanceof Cfg && $cfg['pkg']['map'] instanceof Cfg);
check('B3', 'a list stays a plain array', $cfg->pkg->list === ['x', 'y'] && $cfg['pkg']['list'] === ['x', 'y']);
check('B4', 'int keys that are not 0..n-1 in order make a Cfg node', $cfg->pkg->intmap instanceof Cfg && $cfg->pkg->unordered instanceof Cfg);
check('B4', 'such a node is read by key with both syntaxes', $cfg->pkg->intmap->{'10'} === 'ten' && $cfg['pkg']['intmap'][20] === 'twenty');
check('B5', 'an empty array becomes an empty Cfg node', $cfg->pkg->empty instanceof Cfg && \count($cfg->pkg->empty) === 0 && $cfg['pkg']['empty'] instanceof Cfg);
check('B6', 'non-array values are returned unchanged', $cfg->pkg->enum === ProbeLevel::Info && $cfg->pkg->emptystr === '' && $cfg->pkg->str === 'text' && $cfg->pkg->zero === 0 && $cfg->pkg->float === 1.5 && $cfg->pkg->falseval === false && $cfg->pkg->nullval === null);
check('B7', 'repeated reads return the same node, across both syntaxes', $cfg->pkg === $cfg->pkg && $cfg->pkg === $cfg['pkg'] && $cfg->pkg->map === $cfg['pkg']['map'] && $cfg->pkg->empty === $cfg['pkg']['empty']);

// -- B8 Missing key, direct read --------------------------------------------------
$e1 = caught(fn() => $cfg->missing);
$e2 = caught(fn() => $cfg->pkg->missing->leaf);
$e3 = caught(fn() => $cfg->pkg->deep->missing->x->y);
$e4 = caught(fn() => $cfg['pkg']['missing']['leaf']);
check('B8', 'direct read of a missing key throws OutOfBoundsException', $e1 instanceof \OutOfBoundsException, (string)$e1?->getMessage());
check('B8', 'the message names the first missing key (depth 2)', $e2 instanceof \OutOfBoundsException && $e2->getMessage() === "Unknown cfg key: 'missing'");
check('B8', 'the message names the first missing key (depth 3)', $e3 instanceof \OutOfBoundsException && $e3->getMessage() === "Unknown cfg key: 'missing'");
check('B8', 'array syntax throws the same', $e4 instanceof \OutOfBoundsException && $e4->getMessage() === "Unknown cfg key: 'missing'");

// -- B9 Reading through a value that is not a Cfg node ----------------------------
$notices = [];
$r1 = null; $x1 = caught(function () use ($cfg, &$r1) { $r1 = $cfg->pkg->str->x; });
check('B9', 'property read on a scalar: PHP warning and null, no exception', $x1 === null && $r1 === null && \str_contains($notices[0] ?? '', 'Attempt to read property'), $notices[0] ?? 'no notice');
$notices = [];
$r2 = null; $x2 = caught(function () use ($cfg, &$r2) { $r2 = $cfg->pkg->nullval->x; });
check('B9', 'property read on null: PHP warning and null, no exception', $x2 === null && $r2 === null && \str_contains($notices[0] ?? '', 'Attempt to read property'), $notices[0] ?? 'no notice');
$notices = [];
$r3 = 'unset'; $x3 = caught(function () use ($cfg, &$r3) { $r3 = $cfg->pkg->list[5]; });
check('B9', 'missing list index: PHP warning and null, no exception', $x3 === null && $r3 === null && \str_contains($notices[0] ?? '', 'Undefined array key'), $notices[0] ?? 'no notice');

// -- B10-B12 Existence guards ------------------------------------------------------
$notices = [];
$g = static function (callable $fn): array {
	global $notices;
	$notices = [];
	$v = null;
	$e = caught(function () use ($fn, &$v) { $v = $fn(); });
	return [$v, $e, $notices];
};
[$v, $e, $n] = $g(fn() => $cfg->missing->a->b ?? 'D');
check('B10', '?? on a missing path (missing at top): default, no exception, no notice', $v === 'D' && $e === null && $n === []);
[$v, $e, $n] = $g(fn() => $cfg->pkg->deep->missing->x ?? 'D');
check('B10', '?? on a missing path (missing deep): default, no exception, no notice', $v === 'D' && $e === null && $n === []);
[$v, $e, $n] = $g(fn() => $cfg['pkg']['missing']['x'] ?? 'D');
check('B10', '?? with array syntax: default, no exception, no notice', $v === 'D' && $e === null && $n === []);
check('B10', '?? falls back when the value is null', ($cfg->pkg->nullval ?? 'D') === 'D' && ($cfg['pkg']['nullval'] ?? 'D') === 'D');
check('B10', '?? does not fall back on false, 0, empty string or empty Cfg', ($cfg->pkg->emptystr ?? 'D') === '' && ($cfg->pkg->falseval ?? 'D') === false && ($cfg->pkg->zero ?? 'D') === 0 && ($cfg->pkg->empty ?? 'D') instanceof Cfg);
[$v, $e, $n] = $g(fn() => $cfg->pkg->str->x ?? 'D');
check('B10', '?? through a scalar: default, no notice', $v === 'D' && $e === null && $n === []);
[$v, $e, $n] = $g(fn() => $cfg['pkg']['str']['x'] ?? 'D');
check('B10', '?? through a string with array syntax: default, no notice', $v === 'D' && $e === null && $n === []);
[$v, $e, $n] = $g(fn() => $cfg['pkg']['zero']['x'] ?? 'D');
check('B10', '?? through an int with array syntax: default, no notice', $v === 'D' && $e === null && $n === []);
[$v, $e, $n] = $g(fn() => $cfg->pkg->nullval->x ?? 'D');
check('B10', '?? through a null value: default, no notice', $v === 'D' && $e === null && $n === []);
[$v, $e, $n] = $g(fn() => $cfg->pkg->list[5] ?? 'D');
check('B10', '?? on a missing list index: default, no notice', $v === 'D' && $e === null && $n === []);
[$v, $e, $n] = $g(fn() => isset($cfg->pkg->deep->missing->x));
check('B11', 'isset() on a missing path: false, no exception', $v === false && $e === null && $n === []);
check('B11', 'isset() is true for a key whose value is null', isset($cfg->pkg->nullval) && isset($cfg['pkg']['nullval']));
check('B11', 'isset() on an existing node: true', isset($cfg->pkg->map) && isset($cfg->pkg->map->a));
[$v, $e, $n] = $g(fn() => empty($cfg->pkg->deep->missing->x));
check('B12', 'empty() on a missing path: true, no exception', $v === true && $e === null && $n === []);
check('B12', 'empty() is true for false, 0 and null values', empty($cfg->pkg->falseval) && empty($cfg->pkg->zero) && empty($cfg->pkg->nullval));

// -- B13 Right operand of ?? --------------------------------------------------------
$options = [];
check('B13', '?? does not guard its right operand', caught(fn() => $options['k'] ?? $cfg->pkg->missing) instanceof \OutOfBoundsException);
$options = ['k' => ''];
check('B13', 'a set left operand wins, an empty string included; the right operand is not evaluated', ($options['k'] ?? $cfg->pkg->missing) === '');

// -- B14 ?-> --------------------------------------------------------------------------
check('B14', '?-> does not guard a missing key', caught(fn() => $cfg->pkg?->missing) instanceof \OutOfBoundsException);

// -- B15-B17 Converting ------------------------------------------------------------------
check('B15', 'toArray() returns the raw array, nested maps as arrays', $cfg->pkg->deep->toArray() === ['inner' => ['leaf' => 'v']] && \is_array($cfg->pkg->toArray()['map']));
check('B15', 'toArray() on an empty node returns []', $cfg->pkg->empty->toArray() === []);
$iter = [];
foreach ($cfg->pkg->deep as $k => $val) { $iter[$k] = $val; }
check('B16', 'foreach yields the raw values, nested maps as arrays', $iter === ['inner' => ['leaf' => 'v']]);
check('B16', 'count() is the number of keys at the node', \count($cfg->pkg->map) === 2 && \count($cfg->pkg->empty) === 0);
$cast = (array)$cfg->pkg->empty;
check('B17', '(array) on a Cfg node returns object internals, not the data', $cast !== [] && \count($cast) === 2, \count($cast) . ' keys');
check('B17', 'array functions reject a Cfg node with TypeError', caught(fn() => \in_array('x', $cfg->pkg->empty, true)) instanceof \TypeError && caught(fn() => \array_map('strval', $cfg->pkg->empty)) instanceof \TypeError);

// -- B18-B22 Writes ------------------------------------------------------------------------
$app = (new \ReflectionClass(App::class))->newInstanceWithoutConstructor();
check('B18', 'assigning App::$cfg from outside the class throws Error', caught(function () use ($app, $cfg) { $app->cfg = $cfg; }) instanceof \Error);
$w = caught(function () use ($cfg) { $cfg['pkg'] = 'x'; });
$u = caught(function () use ($cfg) { unset($cfg['pkg']); });
check('B19', 'array-syntax write throws LogicException', $w instanceof \LogicException && $w->getMessage() === 'Cfg is read-only.');
check('B19', 'array-syntax unset throws LogicException', $u instanceof \LogicException && $u->getMessage() === 'Cfg is read-only.');
$notices = [];
caught(function () use ($cfg) { $cfg->pkg->list[] = 'z'; });
check('B20', 'appending to a list read through Cfg has no effect and raises a notice', $cfg->pkg->list === ['x', 'y'] && \str_contains($notices[0] ?? '', 'Indirect modification of overloaded property'), $notices[0] ?? 'no notice');

$c = freshCfg();
$notices = [];
$c->pkg->str = 'changed';
check('B21', 'property-syntax write creates a dynamic property and raises E_DEPRECATED', \str_contains($notices[0] ?? '', 'Creation of dynamic property'), $notices[0] ?? 'no notice');
check('B21', 'afterwards a property read returns the written value', $c->pkg->str === 'changed');
check('B21', 'afterwards an array-syntax read returns the original value', $c['pkg']['str'] === 'text');
$c = freshCfg();
$throwOnDeprecated = true;
$aborted = caught(function () use ($c) { $c->pkg->str = 'changed'; });
$throwOnDeprecated = false;
check('B21', 'when the error handler throws on E_DEPRECATED, the exception propagates and the write still takes effect', $aborted instanceof \ErrorException && $c->pkg->str === 'changed');
$c = freshCfg();
$notices = [];
unset($c->pkg->str);
check('B22', 'property-syntax unset of a key has no effect and raises no notice', $c->pkg->str === 'text' && $notices === []);

// -- Rule idioms ------------------------------------------------------------------------------
$options = [];
check('R2', 'option over own cfg: option missing -> own cfg value', ($options['a'] ?? $cfg->pkg->map->a) === 1);
$profiles = $cfg->pkg->profiles;
check('R5', 'runtime-chosen key: lookup with ?? null gives null for an unknown key', ($profiles->{'missing'} ?? null) === null);
check('R5', 'runtime-chosen key: lookup gives the node for a known key', ($profiles->{'main'} ?? null) instanceof Cfg);
check('R5', 'runtime-chosen key: the direct map read still throws when the map is missing', caught(fn() => $cfg->pkg->missing_profiles) instanceof \OutOfBoundsException);
$asList = static fn(mixed $v): mixed => $v instanceof Cfg ? $v->toArray() : $v;
check('R6', 'list idiom gives [] for an empty list key and the list otherwise', $asList($cfg->pkg->empty) === [] && $asList($cfg->pkg->list) === ['x', 'y']);

echo \PHP_EOL . ($fail === 0 ? 'ALL ' . $pass . ' CHECKS PASS' : $fail . ' FAILED, ' . $pass . ' passed') . ' (PHP ' . \PHP_VERSION . ')' . \PHP_EOL;
exit($fail === 0 ? 0 : 1);
