<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Core\Tools\AbstractObjectSearch;
use Mcp\Exception\ToolCallException;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use UserRights;

/**
 * Search iTop objects by class name with optional field filters.
 *
 * Simpler alternative to itop_object_search when you don't need full OQL.
 * Filters are combined with AND.
 *
 * Example:
 *   class: "UserRequest"
 *   filters: { "status": "open", "agent_id": 12 }
 */
class ObjectSearchByClass extends AbstractObjectSearch
{

    public function getTitle(): ?string
    {
        return 'Search Objects by Class';
    }

    public function getDescription(): ?string
    {
        return 'Search iTop objects by class name with optional attribute filters (combined with AND). Simpler alternative to itop_object_search when you do not need full OQL. Use the itop://iTop/class/{classname} resource to discover available attributes.';
    }
 
    public function getInputSchema(): ?array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'class'   => [
                    'type'        => 'string',
                    'description' => 'iTop class name (e.g. UserRequest, Server). Use the itop://iTop/classes resource to list available classes.',
                ],
                'filters' => [
                    'type'                 => 'object',
                    'description'          => 'Key/value pairs to filter results (combined with AND). Keys are attribute codes. Use itop://iTop/class/{classname} to discover valid attribute codes.',
                    'additionalProperties' => true,
                ],
                'limit'   => [
                    'type'        => 'integer',
                    'description' => 'Maximum number of objects to return.',
                    'default'     => self::DEFAULT_LIMIT,
                    'minimum'     => self::MIN_LIMIT,
                    'maximum'     => self::MAX_LIMIT,
                ],
                'offset'  => [
                    'type'        => 'integer',
                    'description' => 'Number of objects to skip (for pagination).',
                    'default'     => self::DEFAULT_OFFSET,
                    'minimum'     => self::MIN_OFFSET,
                ],
            ],
            'required' => ['class'],
        ];
    }

    /**
     * @param string $class The iTop class name to search for
     * @param array  $filters Optional key/value pairs to filter results (combined with AND)
     * @param int    $limit Maximum number of results to return (default: 50, max: 1000)
     * @param int    $offset Number of results to skip for pagination (default: 0)
     * @return array An array containing the class, filters, total count, limit, offset, and list of matching objects with their attributes
     * @throws ToolCallException if the class is unknown or access is denied.
     */
    public static function execute(
        string $class,
        array  $filters = [],
        int    $limit = self::DEFAULT_LIMIT,
        int    $offset = self::DEFAULT_OFFSET,
    ): mixed {
        if ($limit < self::MIN_LIMIT || $limit > self::MAX_LIMIT) {
            throw new ToolCallException("Invalid limit. Please specify a limit between " . self::MIN_LIMIT . " and " . self::MAX_LIMIT . ".");
        }

        if ($offset < self::MIN_OFFSET) {
            throw new ToolCallException("Invalid offset. Please specify a non-negative offset.");
        }

        if (!MetaModel::IsValidClass($class)) {
            throw new ToolCallException("Unknown class '{$class}'.");
        }
        if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
            throw new ToolCallException("Unknown class '{$class}'."); // hide that the class exists
        }

        if (!UserRights::IsActionAllowed($class, UR_ACTION_BULK_READ)) {
            throw new ToolCallException("Bulk read access denied to class '{$class}'.");
        }

        $oSearch = DBObjectSearch::FromOQL("SELECT {$class}");

        foreach ($filters as $sAttCode => $value) {
            if (!MetaModel::IsValidAttCode($class, $sAttCode)) {
                throw new ToolCallException("Unknown attribute '{$sAttCode}' on class '{$class}'.");
            }
            $oSearch->AddCondition($sAttCode, $value, '=');
        }

        // Test the query before executing it to provide a more helpful error message in case of invalid filters
        try {
            new DBObjectSet($oSearch, [], [], null, $limit, $offset);
        } catch (\OQLException $e) {
            throw new ToolCallException("Invalid filter condition. " . $e->getMessage());
        }

        $aResults = [];
        $oSet = new DBObjectSet($oSearch, [], [], null, $limit, $offset);
        if (!UserRights::IsActionAllowed($class,  UR_ACTION_READ, $oSet)) {
            return [
                'requested_class'  => $class,
                'class' => $class,
                'filters' => $filters,
                'total'   => 0,
                'limit'   => $limit,
                'offset'  => $offset,
                'objects' => $aResults, // hides objects that the user shouldn't see
            ];
        }
        $sSetClass = $oSet->GetClass();
        if ($sSetClass != $class) {
            // Hides that the objects might exist, as not allowed to access it
            if (!UserRights::IsActionAllowed($sSetClass, UR_ACTION_READ, $oSet)) {
                return [
                    'requested_class'  => $class,
                    'class' => $class,
                    'filters' => $filters,
                    'total'   => 0,
                    'limit'   => $limit,
                    'offset'  => $offset,
                    'objects' => $aResults, // hides objects that the user shouldn't see
                ];
            }

            if (!UserRights::IsActionAllowed($sSetClass, UR_ACTION_BULK_READ, $oSet)) {
                return [
                    'requested_class'  => $class,
                    'class' => $sSetClass,
                    'filters' => $filters,
                    'total'   => 0,
                    'limit'   => $limit,
                    'offset'  => $offset,
                    'objects' => $aResults, // hides objects that the user shouldn't see
                ];
            }
        }

        // Execute the search and fetch results
        try {
            while ($oObject = $oSet->Fetch()) {
                $sObjectFinalClass = MetaModel::GetFinalClassName($class, $oObject->GetKey());
                if ($sObjectFinalClass != $sSetClass) {
                    // skip if not allowed
                    if (!UserRights::IsActionAllowed($sObjectFinalClass, UR_ACTION_READ)) {
                        continue;
                    }
                    $oObjectFinal = MetaModel::GetObject($sObjectFinalClass, $oObject->GetKey());
                    $sKeyFinal = MetaModel::DBGetKey($sObjectFinalClass);
                    $oSearchFinal = DBObjectSearch::FromOQL("SELECT {$sObjectFinalClass} WHERE {$sKeyFinal} = {$oObjectFinal->GetKey()}");
                    $oSetFinal = new DBObjectSet($oSearchFinal);
                    if (!UserRights::IsActionAllowed($sObjectFinalClass,  UR_ACTION_READ, $oSetFinal)) {
                        continue; // hide that the object exists
                    }
                    // serialize the "real object"
                    $oObject = $oObjectFinal;
                }
                $aResults[] = self::serializeObject($oObject, $sObjectFinalClass);
            }

            return [
                'requested_class'  => $class,
                'class' => $sSetClass,
                'filters' => $filters,
                'total'   => $oSet->Count(),
                'limit'   => $limit,
                'offset'  => $offset,
                'objects' => $aResults,
            ];
        } catch (\Exception $e) {
            throw new ToolCallException("Failed to execute search: " . $e->getMessage());
        }
    }
}