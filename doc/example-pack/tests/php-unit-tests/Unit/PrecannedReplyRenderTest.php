<?php
/**
 * @copyright Copyright (C) 2026 Acme
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Acme\iTop\Extension\ServiceDesk\Test\Unit;

use Acme\iTop\Extension\ServiceDesk\Tools\PrecannedReplyRender;
use PHPUnit\Framework\TestCase;

/**
 * What can be asked of the predefined reply tool without iTop. What it does with an excluded or unreadable
 * placeholder needs a datamodel, and is the integration test beside this suite.
 */
class PrecannedReplyRenderTest extends TestCase
{
	public function testItIsAReadToolThatWritesNothing(): void
	{
		$oTool = new PrecannedReplyRender();
		$oAnnotations = $oTool->getAnnotations();

		$this->assertNotNull($oAnnotations, 'a tool with no annotations is graded "delete" and hidden from every scoped token');
		$this->assertTrue($oAnnotations->readOnlyHint, 'it fills a reply in; posting it is core_object_update');
		// Served as acme_precanned_reply_render: the registry puts the namespace in front.
		$this->assertSame('precanned_reply_render', $oTool->getName());
		$this->assertSame('acme', $oTool->getNamespace());
		$this->assertSame('acme-servicedesk', $oTool->getToolset());
	}

	/**
	 * Absent where there is nothing to read the extension from, which is how it is absent on an instance without
	 * Combodo's module: isAvailable() asks for the class before anything else.
	 */
	public function testItIsAbsentWithoutITop(): void
	{
		$this->assertFalse((new PrecannedReplyRender())->isAvailable());
	}

	public function testOnlyTheTicketAndTheReplyAreRequired(): void
	{
		$aSchema = (new PrecannedReplyRender())->getInputSchema();

		$this->assertSame(['ticket_class', 'ticket_id', 'reply_id'], $aSchema['required']);
		$this->assertSame(['string', 'null'], $aSchema['properties']['log_attcode']['type']);
	}

	/**
	 * The description is what makes a model post the result the right way: through core_object_update, after the
	 * user has read it, rather than through a tool this pack does not have.
	 */
	public function testTheDescriptionSaysHowTheReplyIsPosted(): void
	{
		$sDescription = (string)(new PrecannedReplyRender())->getDescription();

		$this->assertStringContainsString('core_object_update', $sDescription);
		$this->assertStringContainsString('writes nothing', $sDescription);
	}
}
