<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Test\Support\CallsToolsOnTheWire;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use UserRights;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, S2: what the read tools that declare an outputSchema answer, as it leaves the server, against that
 * schema, by the SDK's own validator and without the assoc flag (so `{}` and `[]` stay apart): the same check
 * WriteResultSchemaTest makes of the writing tools, which from mcp/sdk 0.9.0 the server also makes itself.
 *
 * Left without a schema on purpose, and said in the CHANGELOG: `core_my_activity`, `core_object_history` and `core_object_count`, whose answers are the largest of the read tools (a history of up to five hundred entries, a count of up to a hundred groups: a schema obliges structuredContent, which doubles them) and whose schemas would cost about a kilobyte of every connection's context each; `core_object_get`, the searches, `core_object_find_by_name`,
 * `core_object_get_related` and `core_portal_list` answer rows made of the class's own attributes, which no schema can
 * list, so it would say "an object" and cost a few hundred bytes of every connection's context to say it;
 * `core_class_list` and `core_class_schema` answer large and nested shapes whose description already says what is in
 * them, a schema of which would cost more than the client gains; `core_instructions` answers text and
 * `core_object_get_document` a file, neither of them JSON.
 *
 * @group integration
 */
class ReadResultSchemaTest extends ItopDataTestCaseAlias
{
	use CallsToolsOnTheWire;

	/** Every read tool that declares an output schema, by the test that calls it. */
	public const COVERED = [
		'core_version',
		'core_current_user',
		'core_my_open_tickets',
	];

	private int $iOrg;

	protected function setUp(): void
	{
		parent::setUp();
		$this->iOrg = (int)$this->CreateOrganization('ReadResultSchemaTest '.bin2hex(random_bytes(4)))->GetKey();
		$sLogin = 'read-schema-'.bin2hex(random_bytes(3));
		$iProfile = (int)(new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = "Administrator"')))->Fetch()->GetKey();
		$this->CreateUser($sLogin, $iProfile, 'Read-Schema-'.bin2hex(random_bytes(3)).'-1!', (int)$this->CreatePerson(1, $this->iOrg)->GetKey());
		UserRights::Login($sLogin);
	}

	public function testVersionAndCurrentUser(): void
	{
		$this->assertConforms('core_version', []);
		$oUser = $this->assertConforms('core_current_user', []);

		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $oUser->now);
	}

	public function testMyOpenTicketsWhereThePromptIsOffered(): void
	{
		if (!MetaModel::IsValidClass('Ticket')) {
			$this->markTestSkipped('Needs the ticketing module.');
		}

		$this->assertConforms('core_my_open_tickets', []);
	}
}
