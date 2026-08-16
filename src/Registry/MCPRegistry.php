<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Registry;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPPrompt;
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResource;
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResourceTemplate;
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Exception\MCPRegistrationException;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Where everything a provider registers lands, and the only place the contract
 * behind the abstracts is actually enforced.
 *
 * The abstracts can only require that a method *exists* with a given
 * signature. Everything else the framework relies on - a name the MCP schema
 * accepts, an input schema whose properties match the parameters of
 * execute(), a resource template whose {variables} match the parameters of
 * read() - is read reflectively at build time by MCPService and by the SDK.
 * Checking it here means a downstream pack fails at boot, naming the class and
 * the defect, instead of shipping a tool the client can list but never call.
 *
 * It is also where identifiers are handed out. Every element is addressed by
 * its namespace and its name, so two extensions from two vendors that have
 * never heard of each other cannot claim the same one by both calling a class
 * TicketAddLogEntry. When they nevertheless do - same namespace, same name,
 * different classes, neither declaring an override - the identifier is
 * withdrawn rather than awarded to whichever provider happened to load last:
 * a client that asks for it gets nothing, instead of silently getting the
 * other vendor's implementation behind the description it was shown.
 *
 * @since 1.0.0
 */
final class MCPRegistry
{
	/** MCP name syntax, as enforced by the SDK's schema objects. */
	private const NAME_PATTERN = '/^[a-zA-Z0-9_-]{1,128}$/';

	/** No '_', so the separator in a qualified name stays unambiguous. */
	private const NAMESPACE_PATTERN = '/^[a-zA-Z0-9-]{1,64}$/';

	/**
	 * Same shape as a namespace: a toolset name is written by an operator into
	 * config, and read out of a token scope, so it has to survive both.
	 */
	private const TOOLSET_PATTERN = '/^[a-zA-Z0-9-]{1,64}$/';

	/** Reserved for this module, like the core resource URI namespace. */
	private const RESERVED_NAMESPACE = 'core';

	/** Classes allowed to use the reserved namespace. */
	private const RESERVED_NAMESPACE_OWNER = 'Altioo\\iTop\\Extension\\MCP\\Core\\';

	/** @var array<string, AbstractMCPTool> */
	private static array $aTools = [];

	/** @var array<string, AbstractMCPResource> */
	private static array $aResources = [];

	/** @var array<string, AbstractMCPResourceTemplate> */
	private static array $aResourceTemplates = [];

	/** @var array<string, AbstractMCPPrompt> */
	private static array $aPrompts = [];

	/**
	 * Session-level guidance appended to the server instructions.
	 *
	 * @var array<int, string>
	 */
	private static array $aInstructions = [];

	/**
	 * Collisions reported so far, as "kind: identifier" => [replaced, by].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private static array $aOverrides = [];

	/**
	 * Identifiers withdrawn because two unrelated classes claimed them, as
	 * "kind: identifier" => list of the classes that did.
	 *
	 * @var array<string, array<int, string>>
	 */
	private static array $aClashes = [];

	/**
	 * @throws MCPRegistrationException When the tool does not honour the contract.
	 * @since 1.0.0
	 */
	public static function RegisterTool(AbstractMCPTool $oTool): void
	{
		$sIdentifier = self::checkTool($oTool);

		self::claim('tool', self::$aTools, $oTool->overrides() ?? $sIdentifier, $oTool);
	}

	/**
	 * @throws MCPRegistrationException When the resource does not honour the contract.
	 * @since 1.0.0
	 */
	public static function RegisterResource(AbstractMCPResource $oResource): void
	{
		$sUri = self::checkResource($oResource);

		self::claim('resource', self::$aResources, $oResource->overrides() ?? $sUri, $oResource);
	}

	/**
	 * @throws MCPRegistrationException When the resource template does not honour the contract.
	 * @since 1.0.0
	 */
	public static function RegisterResourceTemplate(AbstractMCPResourceTemplate $oResourceTemplate): void
	{
		$sUriTemplate = self::checkResourceTemplate($oResourceTemplate);

		self::claim('resource template', self::$aResourceTemplates, $oResourceTemplate->overrides() ?? $sUriTemplate, $oResourceTemplate);
	}

	/**
	 * @throws MCPRegistrationException When the prompt does not honour the contract.
	 * @since 1.0.0
	 */
	public static function RegisterPrompt(AbstractMCPPrompt $oPrompt): void
	{
		$sIdentifier = self::checkPrompt($oPrompt);

		self::claim('prompt', self::$aPrompts, $oPrompt->overrides() ?? $sIdentifier, $oPrompt);
	}

	/**
	 * Runs the contract checks against an element without registering it.
	 *
	 * Everything Register*() would refuse, refused here too and in the same
	 * words - but with nothing claimed, nothing stored, and no effect on a
	 * server being built. That is what lets a pack assert its own elements in
	 * its own suite against the rules that will actually be applied at boot,
	 * rather than against a copy of them that drifts from them.
	 *
	 * @see \Altioo\iTop\Extension\MCP\Testing\ElementContract, which collects
	 *      the failures instead of stopping at the first one.
	 *
	 * @return string The identifier the element would claim.
	 *
	 * @throws MCPRegistrationException  When the element does not honour the contract.
	 * @throws \InvalidArgumentException When it is not an MCP element at all.
	 *
	 * @since 1.0.0
	 */
	public static function Check(object $oElement): string
	{
		if ($oElement instanceof AbstractMCPTool) {
			return self::checkTool($oElement);
		}
		if ($oElement instanceof AbstractMCPResource) {
			return self::checkResource($oElement);
		}
		if ($oElement instanceof AbstractMCPResourceTemplate) {
			return self::checkResourceTemplate($oElement);
		}
		if ($oElement instanceof AbstractMCPPrompt) {
			return self::checkPrompt($oElement);
		}

		throw new \InvalidArgumentException(sprintf(
			'%s extends none of AbstractMCPTool, AbstractMCPResource, AbstractMCPResourceTemplate or AbstractMCPPrompt.',
			get_class($oElement)
		));
	}

	/**
	 * @return string The qualified name it would claim.
	 *
	 * @throws MCPRegistrationException
	 */
	private static function checkTool(AbstractMCPTool $oTool): string
	{
		$sIdentifier = self::validateIdentity($oTool, $oTool->getQualifiedName(), 'tool');
		self::validateProfiles($oTool, $oTool->requiredProfiles());
		$oExecute = self::validateHandler($oTool, 'execute');
		self::validateToolDeclaration($oTool, $sIdentifier, $oExecute);

		return $sIdentifier;
	}

	/**
	 * @return string The URI it would claim.
	 *
	 * @throws MCPRegistrationException
	 */
	private static function checkResource(AbstractMCPResource $oResource): string
	{
		self::validateIdentity($oResource, $oResource->getQualifiedName(), 'resource');
		self::validateProfiles($oResource, $oResource->requiredProfiles());
		self::validateHandler($oResource, 'read');

		$sUri = $oResource->getUri();
		if (str_contains($sUri, '{')) {
			throw new MCPRegistrationException(sprintf(
				'%s: "%s" contains a {variable}; a fixed resource must have a literal URI - extend AbstractMCPResourceTemplate instead.',
				get_class($oResource),
				$sUri
			));
		}

		return $sUri;
	}

	/**
	 * @return string The qualified name it would claim.
	 *
	 * @throws MCPRegistrationException
	 */
	private static function checkPrompt(AbstractMCPPrompt $oPrompt): string
	{
		$sIdentifier = self::validateIdentity($oPrompt, $oPrompt->getQualifiedName(), 'prompt');
		self::validateProfiles($oPrompt, $oPrompt->requiredProfiles());
		self::validateHandler($oPrompt, 'get');

		return $sIdentifier;
	}

	/**
	 * @return string The URI template it would claim.
	 *
	 * @throws MCPRegistrationException
	 */
	private static function checkResourceTemplate(AbstractMCPResourceTemplate $oResourceTemplate): string
	{
		self::validateIdentity($oResourceTemplate, $oResourceTemplate->getQualifiedName(), 'resource template');
		self::validateProfiles($oResourceTemplate, $oResourceTemplate->requiredProfiles());
		$oRead = self::validateHandler($oResourceTemplate, 'read');

		$sUriTemplate = $oResourceTemplate->getUriTemplate();
		if (!preg_match_all('/{([^{}]+)}/', $sUriTemplate, $aMatches)) {
			throw new MCPRegistrationException(sprintf(
				'%s: "%s" has no {variable}; a template without one is a fixed resource - extend AbstractMCPResource instead.',
				get_class($oResourceTemplate),
				$sUriTemplate
			));
		}

		// The SDK binds URI variables to read() by parameter name.
		$aParameters = self::parameterNames($oRead);
		$aMissing = array_diff($aMatches[1], $aParameters);
		if (!empty($aMissing)) {
			throw new MCPRegistrationException(sprintf(
				'%s: "%s" declares {%s}, but read() has no parameter of that name (parameters: %s). The SDK binds URI variables by name.',
				get_class($oResourceTemplate),
				$sUriTemplate,
				implode('}, {', $aMissing),
				empty($aParameters) ? 'none' : implode(', ', $aParameters)
			));
		}

		return $sUriTemplate;
	}

	/** @return AbstractMCPTool[] */
	/**
	 * @since 1.0.0
	 */
	public static function GetTools(): array
	{
		return self::$aTools;
	}

	/** @return AbstractMCPResource[] */
	/**
	 * @since 1.0.0
	 */
	public static function GetResources(): array
	{
		return self::$aResources;
	}

	/** @return AbstractMCPResourceTemplate[] */
	/**
	 * @since 1.0.0
	 */
	public static function GetResourceTemplates(): array
	{
		return self::$aResourceTemplates;
	}

	/** @return AbstractMCPPrompt[] */
	/**
	 * @since 1.0.0
	 */
	public static function GetPrompts(): array
	{
		return self::$aPrompts;
	}

	/**
	 * Adds a paragraph to what the server tells a client at initialize.
	 *
	 * For what a model has to know before it calls anything - a convention
	 * your datamodel follows, a tool it should reach for first. Not for
	 * describing a tool: that belongs in the tool's own description, which is
	 * read when the tool is being considered rather than in every session.
	 *
	 * Kept short, for the same reason: this text costs every session, whether
	 * or not any of your tools is ever called.
	 *
	 * @since 1.0.0
	 */
	public static function AddInstructions(string $sInstructions): void
	{
		$sInstructions = trim($sInstructions);
		if ($sInstructions === '' || in_array($sInstructions, self::$aInstructions, true)) {
			return;
		}

		self::$aInstructions[] = $sInstructions;
	}

	/** @return array<int, string> */
	/**
	 * @since 1.0.0
	 */
	public static function GetInstructions(): array
	{
		return self::$aInstructions;
	}

	/**
	 * Declared overrides that took effect, keyed by "kind: identifier".
	 *
	 * Replacing another element is supported, so this is not an error - but it
	 * is never accidental either, hence the record. MCPExtensionCollector
	 * writes these to the log once collection is done.
	 *
	 * @return array<string, array{0: string, 1: string}> identifier => [replaced class, replacing class]
	 * @since 1.0.0
	 */
	public static function GetOverrides(): array
	{
		return self::$aOverrides;
	}

	/**
	 * Identifiers withdrawn because unrelated classes claimed them.
	 *
	 * Nothing is served under these; the operator resolves the clash by
	 * disabling one of the classes through mcp_disabled_tools, which accepts a
	 * class name precisely because the name is the thing in dispute.
	 *
	 * @return array<string, array<int, string>> identifier => claiming classes
	 * @since 1.0.0
	 */
	public static function GetClashes(): array
	{
		return self::$aClashes;
	}

	/**
	 * @since 1.0.0
	 */
	public static function Clear(): void
	{
		self::$aTools = [];
		self::$aResources = [];
		self::$aResourceTemplates = [];
		self::$aPrompts = [];
		self::$aOverrides = [];
		self::$aClashes = [];
		self::$aInstructions = [];
	}

	/**
	 * Awards an identifier, or withdraws it when two unrelated classes want it.
	 *
	 * Load order decides nothing here. Re-registering the same class is a
	 * no-op (a provider that is both declared and discovered does exactly
	 * that). A declared override wins whichever side registers first, so the
	 * outcome does not depend on which module the setup happened to load
	 * earlier. Anything else is two vendors colliding by accident: the
	 * identifier is dropped and every class that claimed it is recorded, so
	 * the operator gets a name that resolves to nothing and a log entry
	 * naming both, rather than a tool that quietly does something other than
	 * what its description says.
	 *
	 * @param array<string, object> $aStore
	 */
	private static function claim(string $sKind, array &$aStore, string $sIdentifier, object $oElement): void
	{
		$sKey = $sKind.': '.$sIdentifier;

		if (isset(self::$aClashes[$sKey])) {
			// Already withdrawn; a third claimant changes nothing but is worth recording.
			self::$aClashes[$sKey][] = get_class($oElement);

			return;
		}

		$oExisting = $aStore[$sIdentifier] ?? null;
		if ($oExisting === null || get_class($oExisting) === get_class($oElement)) {
			$aStore[$sIdentifier] = $oElement;

			return;
		}

		if ($oElement->overrides() === $sIdentifier) {
			self::$aOverrides[$sKey] = [get_class($oExisting), get_class($oElement)];
			$aStore[$sIdentifier] = $oElement;

			return;
		}

		if ($oExisting->overrides() === $sIdentifier) {
			// The overriding element registered first; it keeps the identifier.
			self::$aOverrides[$sKey] = [get_class($oElement), get_class($oExisting)];

			return;
		}

		unset($aStore[$sIdentifier]);
		self::$aClashes[$sKey] = [get_class($oExisting), get_class($oElement)];
	}

	/**
	 * Validates the namespace, the name, and the identifier they compose.
	 *
	 * @throws MCPRegistrationException
	 */
	private static function validateIdentity(object $oElement, string $sIdentifier, string $sKind): string
	{
		$sClass = get_class($oElement);
		$sNamespace = $oElement->getNamespace();

		if (!preg_match(self::NAMESPACE_PATTERN, $sNamespace)) {
			throw new MCPRegistrationException(sprintf(
				'%s: "%s" is not a usable namespace. Expected 1 to 64 characters matching [a-zA-Z0-9-]; it qualifies the identifier clients address, so keep it yours - a vendor or module name.',
				$sClass,
				$sNamespace
			));
		}

		if ($sNamespace === self::RESERVED_NAMESPACE && !str_starts_with($sClass, self::RESERVED_NAMESPACE_OWNER)) {
			throw new MCPRegistrationException(sprintf(
				'%s: the "%s" namespace belongs to the base extension. Use your own vendor or module name; to deliberately replace a core element, declare it through overrides().',
				$sClass,
				self::RESERVED_NAMESPACE
			));
		}

		$sToolset = $oElement->getToolset();
		if (!preg_match(self::TOOLSET_PATTERN, $sToolset)) {
			throw new MCPRegistrationException(sprintf(
				'%s: "%s" is not a usable toolset. Expected 1 to 64 characters matching [a-zA-Z0-9-]; an operator writes it into mcp_enabled_toolsets and into token scopes.',
				$sClass,
				$sToolset
			));
		}

		if (!preg_match(self::NAME_PATTERN, $sIdentifier)) {
			throw new MCPRegistrationException(sprintf(
				'%s: "%s" is not a usable %s identifier. Namespace and name compose it, and the result must be 1 to 128 characters matching [a-zA-Z0-9_-].',
				$sClass,
				$sIdentifier,
				$sKind
			));
		}

		$sOverrides = $oElement->overrides();
		if ($sOverrides !== null && $sOverrides === $sIdentifier) {
			throw new MCPRegistrationException(sprintf(
				'%s: overrides() names this element itself. It must name the element being replaced, or return null.',
				$sClass
			));
		}

		return $sIdentifier;
	}

	/**
	 * @param mixed $aProfiles
	 *
	 * @throws MCPRegistrationException
	 */
	private static function validateProfiles(object $oElement, $aProfiles): void
	{
		if (!is_array($aProfiles)) {
			throw new MCPRegistrationException(sprintf('%s: requiredProfiles() must return an array.', get_class($oElement)));
		}

		foreach ($aProfiles as $sProfile) {
			if (!is_string($sProfile) || $sProfile === '') {
				throw new MCPRegistrationException(sprintf(
					'%s: requiredProfiles() must return a list of non-empty profile names.',
					get_class($oElement)
				));
			}
		}
	}

	/**
	 * The framework hands `[$oElement, $sMethod]` to the SDK, which reflects on
	 * it to bind arguments. A missing or non-public method only shows up when a
	 * client calls the element.
	 *
	 * @throws MCPRegistrationException
	 */
	private static function validateHandler(object $oElement, string $sMethod): ReflectionMethod
	{
		if (!method_exists($oElement, $sMethod)) {
			throw new MCPRegistrationException(sprintf(
				'%s: no %s() method. The framework invokes it to serve this element.',
				get_class($oElement),
				$sMethod
			));
		}

		$oMethod = new ReflectionMethod($oElement, $sMethod);
		if (!$oMethod->isPublic()) {
			throw new MCPRegistrationException(sprintf('%s: %s() must be public.', get_class($oElement), $sMethod));
		}

		return $oMethod;
	}

	/**
	 * @throws MCPRegistrationException
	 */
	private static function validateToolDeclaration(AbstractMCPTool $oTool, string $sName, ReflectionMethod $oExecute): void
	{
		$sClass = get_class($oTool);

		$sDescription = $oTool->getDescription();
		if ($sDescription === null || trim($sDescription) === '') {
			throw new MCPRegistrationException(sprintf(
				'%s: getDescription() is empty. Clients show it to the model to decide whether to call "%s".',
				$sClass,
				$sName
			));
		}

		$aSchema = $oTool->getInputSchema();
		if (!is_array($aSchema) || ($aSchema['type'] ?? null) !== 'object') {
			throw new MCPRegistrationException(sprintf(
				'%s: getInputSchema() must return a JSON Schema array of type "object".',
				$sClass
			));
		}

		$aProperties = $aSchema['properties'] ?? [];
		if (!is_array($aProperties)) {
			throw new MCPRegistrationException(sprintf('%s: input schema "properties" must be an array.', $sClass));
		}

		$aRequired = $aSchema['required'] ?? [];
		if (!is_array($aRequired)) {
			throw new MCPRegistrationException(sprintf('%s: input schema "required" must be an array.', $sClass));
		}

		$aUndeclared = array_diff($aRequired, array_keys($aProperties));
		if (!empty($aUndeclared)) {
			throw new MCPRegistrationException(sprintf(
				'%s: input schema requires %s, which %s not declared under "properties".',
				$sClass,
				implode(', ', $aUndeclared),
				count($aUndeclared) > 1 ? 'are' : 'is'
			));
		}

		if ($oExecute->isVariadic()) {
			return;
		}

		// The SDK binds tool arguments to execute() by parameter name: a
		// property with no matching parameter is silently dropped, and a
		// mandatory parameter with no matching property makes every call fail.
		$aParameters = self::parameterNames($oExecute);

		$aUnbound = array_diff(array_keys($aProperties), $aParameters);
		if (!empty($aUnbound)) {
			throw new MCPRegistrationException(sprintf(
				'%s: input schema declares %s, but execute() has no parameter of that name (parameters: %s). Arguments are bound by name.',
				$sClass,
				implode(', ', $aUnbound),
				empty($aParameters) ? 'none' : implode(', ', $aParameters)
			));
		}

		$aUnsatisfiable = [];
		foreach ($oExecute->getParameters() as $oParameter) {
			if ($oParameter->isOptional() || $oParameter->isDefaultValueAvailable()) {
				continue;
			}
			if (self::isInjectedByTheSdk($oParameter->getType())) {
				continue;
			}
			if (!in_array($oParameter->getName(), $aRequired, true)) {
				$aUnsatisfiable[] = $oParameter->getName();
			}
		}
		if (!empty($aUnsatisfiable)) {
			throw new MCPRegistrationException(sprintf(
				'%s: execute() takes %s without a default, so the input schema must list %s under "required".',
				$sClass,
				implode(', ', $aUnsatisfiable),
				count($aUnsatisfiable) > 1 ? 'them' : 'it'
			));
		}
	}

	/**
	 * The SDK fills parameters typed against its own request-scoped services
	 * itself; they are not client arguments.
	 */
	private static function isInjectedByTheSdk(?\ReflectionType $oType): bool
	{
		if (!$oType instanceof ReflectionNamedType || $oType->isBuiltin()) {
			return false;
		}

		return str_starts_with($oType->getName(), 'Mcp\\');
	}

	/** @return array<int, string> */
	private static function parameterNames(ReflectionMethod $oMethod): array
	{
		return array_map(
			static fn (\ReflectionParameter $oParameter): string => $oParameter->getName(),
			$oMethod->getParameters()
		);
	}
}
