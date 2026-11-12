<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

/**
 * A second pack's tool that takes another pack's identifier with overrides().
 * Listing that identifier was the first pack's operator's decision, not a
 * decision about this class (ADR 0017, amended 2026-11-12).
 */
class PortalAwarePackToolOverride extends PortalAwarePackTool
{
	public function overrides(): ?string
	{
		return 'test_portal_aware_pack_tool';
	}
}
