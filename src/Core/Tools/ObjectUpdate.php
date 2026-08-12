<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;
use RestUtils;

/**
 * Update attributes on an existing iTop object.
 *
 * Only the provided fields are updated; omitted attributes are left untouched.
 */
class ObjectUpdate extends AbstractMCPTool
{

    public function getTitle(): ?string
    {
        return 'Update Object';
    }

    public function getDescription(): ?string
    {
        return 'Update one or more attributes of an existing iTop object. Only provided fields are modified; omitted attributes are left untouched.';
    }

    public function getAnnotations(): ?ToolAnnotations
{
    return new ToolAnnotations(
        $this->getTitle() ?? 'Update iTop object',
        false,  // readOnlyHint
        false,  // destructiveHint — modifies, but not “delete/overwrite world”
        true,   // idempotentHint
        false,  // openWorldHint — closed CMDB domain
    );
}

    public function getInputSchema(): ?array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'class'   => [
                    'type'        => 'string',
                    'description' => 'iTop class name (e.g. UserRequest, Server). Use the itop://core/classes resource to list available classes.',
                ],
                'id'  => [
                    'type'        => 'integer',
                    'description' => 'The ID of the object to update.',
                ],
                'fields' => [
                    'type'                 => 'object',
                    'description'          => 'Key/value pairs to update. Keys are attribute codes. Use itop://core/class/{class} to discover valid attribute codes.',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['class', 'id', 'fields'],
        ];
    }

    /**
     * @param string $class The class of the object to update, e.g. 'UserRequest'
     * @param int $id The ID of the object to update, e.g. 123
     * @param array $fields An array of attribute => value pairs to update
     * @return array An array containing the class and ID of the updated object
     * @throws ToolCallException if the class is unknown, if access is denied, or if the object is not found.
     */
    public static function execute(
        string $class,
        int    $id,
        array  $fields,
    ): mixed {
        if ($id < 1) {
            throw new ToolCallException("Invalid ID. Please specify a valid object ID.");
        }
        if (empty($fields)) {
            throw new ToolCallException('No fields provided for update.');
        }

        if (!MetaModel::IsValidClass($class)) {
            throw new ToolCallException("Unknown class '{$class}'.");
        }
        if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
            throw new ToolCallException("Unknown class '{$class}'."); // hide that the class exists
        }

        if (MetaModel::DBIsReadOnly()) {
            throw new ToolCallException("The database is in read-only mode, cannot update objects.");
        }

        if (MetaModel::IsAbstract($class)) {
            throw new ToolCallException("Class '{$class}' is abstract, cannot update.");
        }

        if (!UserRights::IsActionAllowed($class, UR_ACTION_MODIFY)) {
            throw new ToolCallException("Access denied: cannot modify objects of class '{$class}'.");
        }


        // Check access rights on the specific object before retrieving it, to avoid information leaks about the existence of the object
        $sKey = MetaModel::DBGetKey($class);
        $oSearch = DBObjectSearch::FromOQL("SELECT {$class} WHERE {$sKey} = {$id}");
        $oSet = new DBObjectSet($oSearch);
        // Based on GetRelated - object search manages read access
        if ($oSet->Count() === 0) { 
            throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
        }

        // Check the final class of the object
        $sFinalClass = MetaModel::GetFinalClassName($class, $id);
        if ($sFinalClass != $class) {
            if (!UserRights::IsActionAllowed($sFinalClass, UR_ACTION_READ)) {
                throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
            }
            $sKeyFinal = MetaModel::DBGetKey($class);
            $oSearchFinal = DBObjectSearch::FromOQL("SELECT {$sFinalClass} WHERE {$sKeyFinal} = {$id}");
            $oSetFinal = new DBObjectSet($oSearchFinal);
            // Based on GetRelated - object search manages read access
            if ($oSetFinal->Count() === 0) { 
                throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
            }
            // Even if the rights are allowed, this is not the correct class to use.
            throw new ToolCallException("Object {$class}::{$id} is of class '{$sFinalClass}'. Rerun the update with the correct final class."); // hide that the object exists
        }
        if (!UserRights::IsActionAllowed($class,  UR_ACTION_MODIFY, $oSet)) {
            throw new ToolCallException("Access denied: cannot update objects of class '{$class}'.");
        }

        // Get the object
        $oObject = $oSet->Fetch();
        if ($oObject->IsReadOnly()) {
            throw new ToolCallException("Object {$class}::{$id} is in read-only mode, cannot update object.");
        }

        // Validate fields before applying any changes
        $aIssues = [];
        $aValidatedValues = [];
        foreach ($fields as $sAttCode => $value) {
            if (!MetaModel::IsValidAttCode($class, $sAttCode)) {
                $aIssues[$sAttCode] = "Unknown attribute '{$sAttCode}' on class '{$class}'.";
                continue;
            }
            if (!UserRights::IsActionAllowedOnAttribute($class, $sAttCode, UR_ACTION_MODIFY)) {
                $aIssues[$sAttCode] = "Write access denied on attribute '{$sAttCode}'.";
                continue;
            }
			$oAttDef = MetaModel::GetAttributeDef($class, $sAttCode);
            if (!$oAttDef->IsWritable()) {
                $aIssues[$sAttCode] = "Attribute '{$sAttCode}' is not writable.";
                continue;
            }
            try {
                $aValidatedValues[$sAttCode] = RestUtils::MakeValue($class, $sAttCode, $value);
            } catch (\Exception $e) {
                $aIssues[$sAttCode] = "Invalid value for attribute '{$sAttCode}': " . $e->getMessage();
            }
        }
        if (!empty($aIssues)) {
            throw new ToolCallExeception("Failed to update due to fields : ".implode(', ', $aIssues));
        }

        // Most validations passed, apply the changes
        foreach ($aValidatedValues as $sAttCode => $realValue) {
			try
			{
				$oObject->Set($sAttCode, $realValue);
			}
			catch (Exception $e)
			{
                $aIssues[$sAttCode] = "Failed to set  attribute '{$sAttCode}': " . $e->getMessage();
			}
        }
        if (!empty($aIssues)) {
            throw new ToolCallExeception("Failed to update due to setting fields : " .implode(', ', $aIssues));
        }

        try {
            $oObject->DBUpdate();

            return [
                'class' => $class,
                MetaModel::DBGetKey($class)    => $id,
            ];
        } catch (\Exception $e) {
            throw new ToolCallException("Failed to update object: " . $e->getMessage());
        }
    }
}