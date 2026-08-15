<?php

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
 */
final class MCPRegistry
{
	/** MCP name syntax, as enforced by the SDK's schema objects. */
	private const NAME_PATTERN = '/^[a-zA-Z0-9_-]{1,128}$/';

	/** @var array<string, AbstractMCPTool> */
	private static array $aTools = [];

	/** @var array<string, AbstractMCPResource> */
	private static array $aResources = [];

	/** @var array<string, AbstractMCPResourceTemplate> */
	private static array $aResourceTemplates = [];

	/** @var array<string, AbstractMCPPrompt> */
	private static array $aPrompts = [];

	/**
	 * Collisions reported so far, as "kind: identifier" => [replaced, by].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private static array $aOverrides = [];

	/**
	 * @throws MCPRegistrationException When the tool does not honour the contract.
	 */
	public static function RegisterTool(AbstractMCPTool $oTool): void
	{
		$sName = self::validateName($oTool, $oTool->getName(), 'tool');
		self::validateProfiles($oTool, $oTool->requiredProfiles());
		$oExecute = self::validateHandler($oTool, 'execute');
		self::validateToolDeclaration($oTool, $sName, $oExecute);

		self::noteOverride('tool', $sName, self::$aTools[$sName] ?? null, $oTool);
		self::$aTools[$sName] = $oTool;
	}

	/**
	 * @throws MCPRegistrationException When the resource does not honour the contract.
	 */
	public static function RegisterResource(AbstractMCPResource $oResource): void
	{
		self::validateName($oResource, $oResource->getName(), 'resource');
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

		self::noteOverride('resource', $sUri, self::$aResources[$sUri] ?? null, $oResource);
		self::$aResources[$sUri] = $oResource;
	}

	/**
	 * @throws MCPRegistrationException When the resource template does not honour the contract.
	 */
	public static function RegisterResourceTemplate(AbstractMCPResourceTemplate $oResourceTemplate): void
	{
		self::validateName($oResourceTemplate, $oResourceTemplate->getName(), 'resource template');
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

		self::noteOverride('resource template', $sUriTemplate, self::$aResourceTemplates[$sUriTemplate] ?? null, $oResourceTemplate);
		self::$aResourceTemplates[$sUriTemplate] = $oResourceTemplate;
	}

	/**
	 * @throws MCPRegistrationException When the prompt does not honour the contract.
	 */
	public static function RegisterPrompt(AbstractMCPPrompt $oPrompt): void
	{
		$sName = self::validateName($oPrompt, $oPrompt->getName(), 'prompt');
		self::validateProfiles($oPrompt, $oPrompt->requiredProfiles());
		self::validateHandler($oPrompt, 'get');

		self::noteOverride('prompt', $sName, self::$aPrompts[$sName] ?? null, $oPrompt);
		self::$aPrompts[$sName] = $oPrompt;
	}

	/** @return AbstractMCPTool[] */
	public static function GetTools(): array
	{
		return self::$aTools;
	}

	/** @return AbstractMCPResource[] */
	public static function GetResources(): array
	{
		return self::$aResources;
	}

	/** @return AbstractMCPResourceTemplate[] */
	public static function GetResourceTemplates(): array
	{
		return self::$aResourceTemplates;
	}

	/** @return AbstractMCPPrompt[] */
	public static function GetPrompts(): array
	{
		return self::$aPrompts;
	}

	/**
	 * Registrations that replaced an earlier one, keyed by "kind: identifier".
	 *
	 * Last-wins is a supported way for a pack to override a core tool, so it is
	 * not an error - but it is never accidental either, hence the record.
	 * MCPExtensionCollector writes them to the log once collection is done.
	 *
	 * @return array<string, array{0: string, 1: string}> identifier => [replaced class, replacing class]
	 */
	public static function GetOverrides(): array
	{
		return self::$aOverrides;
	}

	public static function Clear(): void
	{
		self::$aTools = [];
		self::$aResources = [];
		self::$aResourceTemplates = [];
		self::$aPrompts = [];
		self::$aOverrides = [];
	}

	private static function noteOverride(string $sKind, string $sIdentifier, ?object $oExisting, object $oNew): void
	{
		if ($oExisting === null || get_class($oExisting) === get_class($oNew)) {
			// Re-registering the same class is how CollectAll() behaves when a
			// provider is both discovered and explicitly registered: not an
			// override, nothing to report.
			return;
		}

		self::$aOverrides[$sKind.': '.$sIdentifier] = [get_class($oExisting), get_class($oNew)];
	}

	/**
	 * @throws MCPRegistrationException
	 */
	private static function validateName(object $oElement, ?string $sName, string $sKind): string
	{
		if ($sName === null || !preg_match(self::NAME_PATTERN, $sName)) {
			throw new MCPRegistrationException(sprintf(
				'%s: "%s" is not a usable %s name. Expected 1 to 128 characters matching [a-zA-Z0-9_-].',
				get_class($oElement),
				$sName ?? 'null',
				$sKind
			));
		}

		return $sName;
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
