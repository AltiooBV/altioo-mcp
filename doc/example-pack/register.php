<?php
/**
 * Declares the provider to the base extension.
 *
 * Listed in the `datamodel` array of the module declaration, which is what
 * makes iTop include it. Auto-discovery would usually find the provider
 * without this - it implements iMCPServiceProvider and lives in src/ - but
 * discovery depends on a classmap being dumped, and being explicit costs one
 * line and never depends on anything. A provider that is both declared and
 * discovered still runs exactly once.
 *
 * @copyright Copyright (C) 2026 Acme
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

use Acme\iTop\Extension\ServiceDesk\AcmeServiceDeskExtensions;
use Altioo\iTop\Extension\MCP\Registry\MCPExtensionCollector;

MCPExtensionCollector::RegisterServiceProvider(AcmeServiceDeskExtensions::class);
