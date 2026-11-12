<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use ReflectionProperty;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Static state is classified, or it does not merge (ADR 0015, rule 5).
 *
 * The module keeps per-request state in static properties, as iTop's own
 * helpers do, and one place forgets it: RequestScope::Close(). A static
 * property added anywhere in src/ - a property, or a `static $x` inside a
 * method - fails this test until it is either cleared by Close() or listed in
 * PROCESS_LIFETIME with the reason it may outlive a request. The fail-open bug
 * of 9c2e25f came from two such slots disagreeing about the request; this is
 * what stops a third being added without anyone saying where it is forgotten.
 *
 * The statics are read from the PHP tokens, like LayerDependencyTest reads the
 * imports, so a mention in a comment or a string counts for nothing.
 */
class RequestStateTest extends TestCase
{
	private const ROOT = 'Altioo\\iTop\\Extension\\MCP\\';

	/**
	 * `Class::$property` => why it may outlive a request. Registration and
	 * constants of the process, never something a request decided. Shrink it;
	 * never add a request's state to it to make this test pass: clear it in
	 * RequestScope::Close() instead.
	 *
	 * @var array<string, string>
	 */
	private const PROCESS_LIFETIME = [
		'Api\\MCPRegistry::$aTools'             => 'registration: filled from the service providers, as iTop fills its own extension registries; MCPRegistry::Clear() is the tests\'',
		'Api\\MCPRegistry::$aResources'         => 'registration: as $aTools',
		'Api\\MCPRegistry::$aResourceTemplates' => 'registration: as $aTools',
		'Api\\MCPRegistry::$aPrompts'           => 'registration: as $aTools',
		'Api\\MCPRegistry::$aInstructions'      => 'registration: as $aTools',
		'Api\\MCPRegistry::$aOverrides'         => 'registration: as $aTools',
		'Api\\MCPRegistry::$aClashes'           => 'registration: the clashes found while registering, as $aTools',
		'Api\\MCPExtensionCollector::$aExtensionClasses' => 'registration: the provider classes iTop reports, collected once',
		'Helper\\MCPLog::$m_oFileLog'           => 'the log handle: LogAPI opens one file log per channel and keeps it, as every iTop log does',
		'Core\\Tools\\ObjectCount::$aResolved'  => 'a constant list of class names resolved by reflection; it cannot differ between requests',
	];

	public function testTheListNamesOnlyStaticsThatExistAndGivesAReasonForEach(): void
	{
		$aStatics = self::Statics();
		foreach (self::PROCESS_LIFETIME as $sKey => $sReason) {
			$this->assertArrayHasKey($sKey, $aStatics, "$sKey is listed but no longer a static property of src/: delete the line.");
			$this->assertNotSame('', trim($sReason), "$sKey is listed without a reason.");
		}
	}

	/**
	 * Every static property of src/ the list above does not name is request state,
	 * and Close() must clear it: each is set to a value that is not its default,
	 * the request is closed, and it must be its default again. So a static field
	 * added anywhere fails here until it is cleared or classified.
	 */
	public function testCloseBringsEveryOtherStaticPropertyBackToItsDefault(): void
	{
		$aCleared = [];
		foreach (self::Closed() as $sKey) {
			[$sClass, $sName] = explode('::$', $sKey);
			try {
				$oProperty = new ReflectionProperty(self::ROOT.$sClass, $sName);
			} catch (\ReflectionException) {
				$this->fail("$sKey is a `static` variable inside a method, which Close() cannot reach: list it in PROCESS_LIFETIME with its reason, or keep the value in a property Close() clears.");
			}
			$mDefault = $oProperty->getDefaultValue();
			$oProperty->setValue(null, self::NotTheDefault($oProperty, $mDefault));
			$this->assertNotSame($mDefault, $oProperty->getValue(), "$sKey could not be moved off its default; the test cannot tell whether Close() clears it.");
			$aCleared[$sKey] = [$oProperty, $mDefault];
		}

		RequestScope::Close();

		foreach ($aCleared as $sKey => [$oProperty, $mDefault]) {
			$this->assertSame($mDefault, $oProperty->getValue(), "RequestScope::Close() left $sKey as the request set it. Request state: call its Forget() from RequestScope::Close(). "
				.'Registration that lives as long as the process: list it in PROCESS_LIFETIME, saying why.');
		}
		$this->assertNotSame([], $aCleared, 'No static property was checked: the scan found nothing, so it is broken.');
	}

	/** Close() is safe on a request that opened nothing, and when called again. */
	public function testCloseIsIdempotent(): void
	{
		RequestScope::Close();
		RequestScope::Close();
		$this->addToAssertionCount(1);
	}

	/**
	 * What is not process-lifetime: every static of src/ the list does not name.
	 *
	 * @return array<int, string>
	 */
	private static function Closed(): array
	{
		return array_values(array_diff(array_keys(self::Statics()), array_keys(self::PROCESS_LIFETIME)));
	}

	private static function NotTheDefault(ReflectionProperty $oProperty, mixed $mDefault): mixed
	{
		$oType = $oProperty->getType();
		$sType = $oType instanceof ReflectionNamedType ? $oType->getName() : 'mixed';
		if ($sType === 'self') {
			$sType = $oProperty->getDeclaringClass()->getName();
		}

		return match (true) {
			$sType === 'string' => 'not the default',
			$sType === 'array' => ['not the default' => true],
			$sType === 'int' => 12345,
			$sType === 'bool' => $mDefault !== true,
			$sType === \Closure::class => static fn (): array => [],
			class_exists($sType) => (new \ReflectionClass($sType))->newInstanceWithoutConstructor(),
			default => 'not the default',
		};
	}

	/**
	 * Every static property of src/, `Class::$name` relative to the root namespace.
	 * A `static $x` inside a method is one too, keyed by the class it is in.
	 *
	 * @return array<string, true>
	 */
	private static function Statics(): array
	{
		static $aStatics = null;
		if ($aStatics !== null) {
			return $aStatics;
		}

		$aStatics = [];
		$sSrc = dirname(__DIR__, 3).'/src/';
		$oIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sSrc, \FilesystemIterator::SKIP_DOTS));
		foreach ($oIterator as $oFile) {
			if ($oFile->getExtension() !== 'php') {
				continue;
			}
			$sClass = str_replace('/', '\\', substr($oFile->getPathname(), strlen($sSrc), -4));
			$aTokens = token_get_all((string)file_get_contents($oFile->getPathname()), TOKEN_PARSE);
			foreach (self::VariablesDeclaredStatic($aTokens) as $sVariable) {
				$aStatics[$sClass.'::'.$sVariable] = true;
			}
		}
		ksort($aStatics);

		return $aStatics;
	}

	/**
	 * The variables a `static` keyword declares: `static` followed, through
	 * modifiers and a type, by a variable. `static fn`, `static function`,
	 * `static::`, `new static` and a `: static` return type are not.
	 *
	 * @param array<int, mixed> $aTokens
	 *
	 * @return array<int, string>
	 */
	private static function VariablesDeclaredStatic(array $aTokens): array
	{
		// What may sit between `static` and the variable it declares: modifiers and a type.
		$aSkippable = [
			T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STATIC, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY,
			T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR, T_ARRAY,
		];
		$aFound = [];
		$iCount = count($aTokens);
		for ($i = 0; $i < $iCount; $i++) {
			if (!is_array($aTokens[$i]) || $aTokens[$i][0] !== T_STATIC) {
				continue;
			}
			for ($j = $i + 1; $j < $iCount; $j++) {
				$mToken = $aTokens[$j];
				if (is_array($mToken) && $mToken[0] === T_VARIABLE) {
					$aFound[] = $mToken[1];
					break;
				}
				if (is_array($mToken) && in_array($mToken[0], $aSkippable, true)) {
					continue;
				}
				if ($mToken === '?' || $mToken === '|') {
					continue;
				}
				break;
			}
		}

		return $aFound;
	}
}
