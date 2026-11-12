<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Service\Portal\PortalAware;

/** A pack's prompt declaring PortalAware: a different kind from the tool of the same identifier. */
class PortalAwarePackPrompt extends FixturePrompt implements PortalAware
{
	public function getName(): ?string
	{
		return 'portal_aware_pack_tool';
	}
}
