<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

use Altioo\iTop\Extension\MCP\Registry\MCPExtensionCollector;
use Altioo\iTop\Extension\MCP\Core\CoreExtensions;

MCPExtensionCollector::RegisterServiceProvider(CoreExtensions::class);
