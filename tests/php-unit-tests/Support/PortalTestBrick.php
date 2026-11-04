<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Combodo\iTop\Portal\Brick\ManageBrick;

/**
 * An extension's own brick class, extending a stock type. Loadable only where
 * the portal module is, so only the integration suite names it.
 */
class PortalTestBrick extends ManageBrick
{
}
