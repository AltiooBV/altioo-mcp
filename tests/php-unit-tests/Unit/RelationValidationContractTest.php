<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGetRelated;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * core_object_get_related could never have answered anything.
 *
 * MetaModel::EnumRelationsEx($sClass) takes a required class and returns a map
 * keyed by relation code - ['impacts' => ['down' => 'Impacts', 'up' => ...]].
 * It was called with no argument, which is an ArgumentCountError - an Error, so
 * no \Exception handler would have caught it even if the tool had one, and it
 * had none. Every call failed, whatever the arguments, and reached the caller
 * as the SDK's fixed "Error while executing tool".
 *
 * The check underneath was wrong in the same breath: in_array() against that
 * map tests the inner arrays, never the codes, so it could not have matched a
 * relation even with the class passed.
 *
 * Nothing found this because the tool needs a class the credential can read,
 * and the credential could not reach one until the profile was widened.
 */
class RelationValidationContractTest extends TestCase
{
	/** The class is what makes a relation a relation. */
	public function testTheClassIsPassedToEnumRelationsEx(): void
	{
		$this->assertMatchesRegularExpression(
			'/EnumRelationsEx\(\s*\$class\s*\)/',
			$this->executeBody(),
			'EnumRelationsEx($sClass) has a required parameter; calling it bare is an ArgumentCountError.'
		);
	}

	/** Keys, not values: the values are per-direction label arrays. */
	public function testTheRelationIsLookedUpByKey(): void
	{
		$sBody = $this->executeBody();

		$this->assertStringNotContainsString(
			'in_array($relation, $aValidRelations',
			$sBody,
			'The map is keyed by relation code; in_array tests the inner arrays and never matches.'
		);
		$this->assertMatchesRegularExpression(
			'/isset\(\$aValidRelations\[\$relation\]\[\$direction\]\)/',
			$sBody,
			'A relation exists per class *and* per direction.'
		);
	}

	/**
	 * A refusal that names what is available is the difference between a model
	 * correcting its call and a model guessing again.
	 */
	public function testTheRefusalNamesWhatIsAvailable(): void
	{
		$this->assertMatchesRegularExpression(
			'/Available: %s|Available: .*implode/',
			$this->executeBody(),
			'The caller cannot guess a relation code from a datamodel it has not read.'
		);
	}

	/**
	 * The ORM call had no handler, so anything it raised arrived as a fixed
	 * string with no reference - which is what made a tool that never worked
	 * indistinguishable from one that broke today.
	 */
	public function testTheWalkCarriesAReferenceWhenItFails(): void
	{
		$sBody = $this->executeBody();

		$this->assertStringContainsString('catch (\Throwable', $sBody);
		$this->assertStringContainsString('MCPHelper::OpaqueFailure', $sBody);
	}

	/** This module's own refusals keep their wording rather than being wrapped. */
	public function testTheModulesOwnRefusalIsNotSwallowed(): void
	{
		$this->assertMatchesRegularExpression(
			'/catch \(ToolCallException \$e\)\s*\{[^}]*throw \$e;/s',
			$this->executeBody(),
			'A withheld-class refusal is an answer, not an ORM failure.'
		);
	}

	private function executeBody(): string
	{
		$oMethod = new ReflectionMethod(ObjectGetRelated::class, 'execute');
		$aLines = file((new ReflectionClass(ObjectGetRelated::class))->getFileName());

		return implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));
	}
}
