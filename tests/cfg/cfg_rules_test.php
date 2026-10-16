<?php
declare(strict_types=1);

// Exercises every rule of the Cfg sections against the real CitOmni\Kernel\Cfg.
// Usage: php cfg_rules_test.php /path/to/kernel/src/Cfg.php

require $argv[1];

use CitOmni\Kernel\Cfg;

$fail = 0;
$deprecations = [];
\set_error_handler(static function (int $no, string $msg) use (&$deprecations): bool {
	$deprecations[] = $msg;
	return true;
});

function check(string $claim, bool $ok, string $detail = ''): void {
	global $fail;
	if (!$ok) {
		$fail++;
	}
	echo ($ok ? 'PASS ' : 'FAIL ') . $claim . ($detail !== '' ? '  [' . $detail . ']' : '') . \PHP_EOL;
}

function throwsOob(callable $fn, ?string &$msg = null): bool {
	try {
		$fn();
	} catch (\OutOfBoundsException $e) {
		$msg = $e->getMessage();
		return true;
	}
	return false;
}

$cfg = new Cfg([
	'pkg' => [
		'assoc'    => ['a' => 1, 'b' => 2],
		'list'     => ['x', 'y'],
		'empty'    => [],
		'nullval'  => null,
		'falseval' => false,
		'deep'     => ['inner' => ['leaf' => 'v']],
	],
]);

// -- Read-only ------------------------------------------------------------
$ro = false;
try { $cfg['new'] = 1; } catch (\LogicException) { $ro = true; }
check('array-syntax write throws LogicException', $ro);
$ro = false;
try { unset($cfg['pkg']); } catch (\LogicException) { $ro = true; }
check('array-syntax unset throws LogicException', $ro);

// -- Node types -------------------------------------------------------------
check('associative array becomes a Cfg node', $cfg->pkg->assoc instanceof Cfg);
check('list stays a plain array', \is_array($cfg->pkg->list) && $cfg->pkg->list === ['x', 'y']);
check('empty array becomes an empty Cfg node (property syntax)', $cfg->pkg->empty instanceof Cfg && \count($cfg->pkg->empty) === 0);
check('empty array becomes an empty Cfg node (array syntax)', $cfg['pkg']['empty'] instanceof Cfg);

$read = static fn(mixed $v): mixed => $v instanceof Cfg ? $v->toArray() : $v;
check('instanceof Cfg ? toArray() : v gives [] for an empty list key', $read($cfg->pkg->empty) === []);
check('instanceof Cfg ? toArray() : v gives the list for a list key', $read($cfg->pkg->list) === ['x', 'y']);

$cast = (array)$cfg->pkg->empty;
check('(array) on an empty Cfg node does NOT give [] (casts private properties)', $cast !== [], 'got ' . \count($cast) . ' keys');

$typeError = false;
try { \in_array('x', $cfg->pkg->empty, true); } catch (\TypeError) { $typeError = true; }
check('\in_array() on an empty list key throws TypeError', $typeError);

// -- Direct reads -------------------------------------------------------------
$m = null;
check('direct read of a missing top-level key throws OutOfBoundsException', throwsOob(fn() => $cfg->missing, $m), (string)$m);
$m = null;
check('direct read throws at the first missing key, depth 2', throwsOob(fn() => $cfg->pkg->missing->leaf, $m) && \str_contains((string)$m, "'missing'"), (string)$m);
$m = null;
check('direct read throws at the first missing key, depth 3', throwsOob(fn() => $cfg->pkg->deep->missing->x, $m) && \str_contains((string)$m, "'missing'"), (string)$m);
$m = null;
check('direct read throws with array syntax too', throwsOob(fn() => $cfg['pkg']['missing'], $m), (string)$m);

// -- Existence guards ---------------------------------------------------------
$v = null;
$ok = !throwsOob(function () use ($cfg, &$v) { $v = $cfg->missing->a->b ?? 'D'; });
check('?? does not throw on a missing path (top level, depth 3)', $ok && $v === 'D');
$ok = !throwsOob(function () use ($cfg, &$v) { $v = $cfg->pkg->deep->missing->x ?? 'D'; });
check('?? does not throw on a missing node deep in the path', $ok && $v === 'D');
$ok = !throwsOob(function () use ($cfg, &$v) { $v = $cfg['pkg']['missing']['x'] ?? 'D'; });
check('?? does not throw with array syntax', $ok && $v === 'D');
$ok = !throwsOob(function () use ($cfg, &$v) { $v = isset($cfg->pkg->missing->x); });
check('isset() does not throw on a missing path and is false', $ok && $v === false);

check('?? falls back on null', ($cfg->pkg->nullval ?? 'D') === 'D');
check('?? falls back on null (array syntax)', ($cfg['pkg']['nullval'] ?? 'D') === 'D');
check('isset() is true for a key whose value is null', isset($cfg->pkg->nullval) === true);
check('isset() is true for a key whose value is null (array syntax)', isset($cfg['pkg']['nullval']) === true);
check('?? does not fall back on false', ($cfg->pkg->falseval ?? 'D') === false);
check('?? reads through a null intermediate without throwing', ($cfg->pkg->nullval->x ?? 'D') === 'D');

// -- Right operand of ?? --------------------------------------------------------
$options = [];
check('?? does not guard its right operand (option missing, cfg key missing -> throws)', throwsOob(fn() => $options['k'] ?? $cfg->pkg->missing));
$options = ['k' => 'opt'];
check('option present: the right operand is not evaluated', ($options['k'] ?? $cfg->pkg->missing) === 'opt');
$options = [];
check('option missing: the own cfg key is read', ($options['k'] ?? $cfg->pkg->assoc->a) === 1);

// -- Runtime-chosen key in an own map -------------------------------------------
$profile = 'nope';
check('runtime-chosen key: map read directly, lookup with ?? null gives null', ($cfg->pkg->assoc->{$profile} ?? null) === null);

// -- ?-> is no guard for missing keys --------------------------------------------
check('?-> does not guard a missing key (it only guards null)', throwsOob(fn() => $cfg->pkg?->missing));

// -- Property-syntax write (not covered by offsetSet) ----------------------------
$deprecations = [];
$wrote = false;
try {
	$cfg->injected = 'x';
	$wrote = ($cfg->injected === 'x');
} catch (\Throwable $e) {
	$wrote = false;
}
echo 'INFO property-syntax write: ' . ($wrote ? 'succeeds' : 'is refused')
	. ($deprecations !== [] ? ' with: ' . $deprecations[0] : ' with no notice') . \PHP_EOL;

echo \PHP_EOL . ($fail === 0 ? 'ALL RULES HOLD' : $fail . ' FAILED') . ' (PHP ' . \PHP_VERSION . ')' . \PHP_EOL;
exit($fail === 0 ? 0 : 1);
