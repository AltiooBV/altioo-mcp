<?php
//
// iTop module definition file — RENAME THIS to module.acme-servicedesk.php
//
// It ships with a .tpl suffix on purpose. iTop's setup walks every directory
// under extensions/ looking for files matching module.*.php and eval()s each
// one it finds (setup/modulediscovery.class.inc.php, ListModuleFiles), and it
// does not skip doc/. A live module file here would put "Acme Service Desk"
// in the module list of every instance that installs the base extension, as
// something they could tick. The suffix is what keeps this an example.
//
SetupWebPage::AddModule(
	__FILE__,
	'acme-servicedesk/1.0.0',
	array(
		'label'    => 'Acme Service Desk MCP tools',
		'category' => 'Application management',

		'dependencies' => array(
			// The base extension. This is the version check that matters: the
			// setup refuses the install and says why, which is a better place
			// to find out than a log entry on the first MCP request.
			//
			// A dependency with no operator means ">=", so this reads
			// "altioo-mcp 1.0.0 or later".
			'altioo-mcp/1.0.0',

			// Whatever your own tools need. This pack reads tickets, so it
			// declares the ticketing module rather than discovering at runtime
			// that UserRequest does not exist on this instance.
			'itop-request-mgmt/3.2.0',
		),
		'mandatory' => false,
		'visible'   => true,

		'datamodel' => array(
			// Order matters. The autoloader has to be in place before
			// register.php names a class, and both have to be listed here or
			// neither runs.
			//
			// This entry is also what makes auto-discovery work at all:
			// iTop's InterfaceDiscovery enumerates candidate classes from
			// env-*/<module>/vendor/composer/autoload_classmap.php, so a pack
			// whose autoloader is PSR-4 only has an empty classmap and is
			// never discovered. See composer.json in this directory for the
			// two settings that produce a classmap.
			'vendor/autoload.php',
			'register.php',
		),
		'webservice' => array(),
		'data.struct' => array(),
		'data.sample' => array(),

		'doc.manual_setup'     => '',
		'doc.more_information' => '',

		'settings' => array(),
	)
);
