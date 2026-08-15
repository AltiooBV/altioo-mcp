<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Resources;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResource;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;
use MetaModel;
use UserRights;

class ClassList extends AbstractMCPResource
{

	public function getTitle(): ?string
	{
		return 'iTop Classes';
	}

	public function getDescription(): ?string
	{
		return 'List all available iTop classes. Use the core://core/class/{class} resource template to read details about a specific class.';
	}

	protected function getResourceNamespace(): string
	{
		return 'core';
	}

	protected function getResourcePath(): string
	{
		return 'classes';
	}

	public function getAnnotations(): ?Annotations
	{
		return new Annotations(
			[Role::Assistant],
			1,
		);
	}

	public function read(): mixed
	{
		$aClasses = [];

		foreach (MetaModel::GetClasses() as $sClass) {
			// Skip classes the current user has no read access to
			if (!UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
				continue;
			}

			$aClasses[] = [
				'class'             => $sClass,
				'label'             => MetaModel::GetName($sClass),
				'description'       => MetaModel::GetClassDescription($sClass),
				'isAbstract'          => MetaModel::IsAbstract($sClass),
				'isRoot'            => MetaModel::IsRootClass($sClass),
				'isHierarchical'    => MetaModel::IsHierarchicalClass($sClass),
				'rootClass'         => MetaModel::GetRootClass($sClass),
				'parentClass'       => MetaModel::GetParentClass($sClass),
			];
		}

		usort($aClasses, static fn($a, $b) => strcmp($a['class'], $b['class']));

		return json_encode($aClasses);
	}
}
