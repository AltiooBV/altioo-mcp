<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

use Altioo\iTop\Extension\MCP\Controller\MCPController;

require_once dirname(__DIR__, 2).'/approot.inc.php';

require_once(APPROOT.'/application/application.inc.php');
require_once(APPROOT.'/application/loginwebpage.class.inc.php');

// The require below is what loads this module's autoloader. It is the first
// entry in the 'datamodel' array of module.altioo-mcp.php - the same entry
// that gives register.php the classes it names - so MCPController on the last
// line resolves out of the compiled tree. Nothing above this line needs a
// class from this module, which is why there is no earlier one.
//
// Requiring __DIR__.'/vendor/autoload.php' before the boot, which this file
// used to do, is not a harmless head start. This file ships in two places,
// extensions/altioo-mcp/ and the compiled env-<env>/altioo-mcp/, and the two
// requires land on the same absolute path in only the second of them. Served
// from extensions/, they are different files, require_once has nothing to
// dedupe, and the second declaration of ComposerAutoloaderInitAltiooMcpExtension
// is a fatal error - composer.json pins that suffix, so the collision is exact
// rather than a coincidence of names. EntryPointAutoloaderTest holds this.
//
// One consequence is worth naming, because a comment here used to claim the
// opposite. A Composer loader prepends itself when it registers, so of two
// loaders in one process the one registered *last* answers first. This
// module's now registers during startup, after iTop's, so for the packages
// both trees carry - psr/*, webmozart/assert - this module's copies are the
// ones that answer. That is not new behaviour being introduced here; it is the
// resolution every other entry point in the application already had, since
// that datamodel entry is loaded on every request to the environment and not
// only on this one. What changed is that this endpoint stopped being the
// exception. See VendoredDependencyResolutionTest.
require_once(APPROOT.'/application/startup.inc.php');

MCPController::handleRequest();
