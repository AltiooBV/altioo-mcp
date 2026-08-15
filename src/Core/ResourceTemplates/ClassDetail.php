<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\ResourceTemplates;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResourceTemplate;
use Mcp\Exception\ResourceReadException;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;
use MetaModel;
use UserRights;
use iAttributeNoGroupBy;

class ClassDetail extends AbstractMCPResourceTemplate
{

	public function getTitle(): ?string
	{
		return 'iTop Class Detail';
	}

	public function getDescription(): ?string
	{
		return 'Get details of a specific iTop class: attributes, relations and lifecycle. URI: itop://core/class/{class}';
	}

	protected function getResourceNamespace(): string
	{
		return 'core';
	}

	protected function getResourcePath(): string
	{
		return 'class/{class}';
	}

	public function getAnnotations(): ?Annotations
	{
		return new Annotations(
			[Role::User, Role::Assistant],
			0.7,
		);
	}

	/**
	 * @param string $uri The full URI that was called, e.g. 'itop://core/class/UserRequest'
	 * @param string $class The {class} variable extracted from the URI, e.g. 'UserRequest'
	 * @return string A JSON-encoded array containing class details
	 * @throws ResourceReadException if the class is missing, unknown, or access is denied.
	 */
	public function read(string $uri, string $class): mixed
	{
		$sClass = $class;

		if ($sClass === '') {
			throw new ResourceReadException("Missing class argument.");
		}

		if (!MetaModel::IsValidClass($sClass)) {
			throw new ResourceReadException("Unknown class '{$sClass}'.");
		}

		if (!UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
			throw new ToolCallException("Unknown class '{$class}'."); // hide that the class exists
		}

		return json_encode([
			'class'       => $sClass,
			'label'             => MetaModel::GetName($sClass),
			'description'       => MetaModel::GetClassDescription($sClass),
			'isAbstract'          => MetaModel::IsAbstract($sClass),
			'isRoot'            => MetaModel::IsRootClass($sClass),
			'isHierarchical'    => MetaModel::IsHierarchicalClass($sClass),
			'rootClass'         => MetaModel::GetRootClass($sClass),
			'parentClass'       => MetaModel::GetParentClass($sClass),
			'attributes'  => self::getAttributes($sClass),
			'relations'   => self::getRelations($sClass),
			'lifecycle'   => self::getLifecycle($sClass),
		]);
	}

	/**
	 * @param string $sClass The class for which to retrieve attribute details
	 * @return array An array of attribute details
	 */
	private static function getAttributes(string $sClass): array
	{
		$aAttributes = [];

		foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef) {
			$aAttributes[$sAttCode] = [
				'label'       => MetaModel::GetLabel($sClass, $sAttCode),
				'description' => $oAttDef->GetDescription(),
				'type'        => get_class($oAttDef),
				'required'    => $oAttDef->IsNullAllowed() === false,
				'readOnly'    => $oAttDef->IsWritable() === false,
				'nullable'   => $oAttDef->IsNullAllowed(),
				'isExternalKey' => $oAttDef->IsExternalKey(),
				'isScalar'    => $oAttDef->IsScalar(),
				'isSensible'   => $oAttDef instanceof iAttributeNoGroupBy, // iAttributeNoGroupBy is equivalent to sensitive attribute
				'values'      => $oAttDef->GetAllowedValues(),
			];
		}

		return $aAttributes;
	}

	/**
	 * @param string $sClass The class for which to retrieve relation details
	 * @return array An array of relation details
	 */
	private static function getRelations(string $sClass): array
	{
		$aRelations = [];

		foreach (MetaModel::EnumRelations() as $sRelation) {
			$aRelated = [];
			foreach (MetaModel::EnumRelationQueries($sClass, $sRelation) as $sNeighbour => $aQueryInfo) {
				$aRelated[] = $sNeighbour;
			}
			if (!empty($aRelated)) {
				$aRelations[$sRelation] = $aRelated;
			}
		}

		return $aRelations;
	}

	/**
	 * @param string $sClass The class for which to retrieve lifecycle details
	 * @return array An array of lifecycle details
	 */
	private static function getLifecycle(string $sClass): array
	{
		if (!MetaModel::HasLifecycle($sClass)) {
			return [];
		}
		$sStateAttCode = MetaModel::GetStateAttributeCode($sClass);
		if ($sStateAttCode === '') {
			return [];
		}

		$aStates = [];
		foreach (MetaModel::EnumStates($sClass) as $sState => $aStateDef) {
			$aTransitions = [];
			foreach (MetaModel::EnumTransitions($sClass, $sState) as $sStimulusCode => $aTransitionDef) {
				$aTransitions[$sStimulusCode] = $aTransitionDef['target_state'];
			}
			$aStates[$sState] = [
				'label'       => MetaModel::GetStateLabel($sClass, $sState),
				'transitions' => $aTransitions,
			];
		}

		return [
			'stateAttribute' => $sStateAttCode,
			'states'         => $aStates,
		];
	}
}
