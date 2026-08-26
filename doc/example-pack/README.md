# Example tool pack — `acme-servicedesk`

A complete, minimal iTop extension that adds two tools and a prompt to the MCP
endpoint provided by `altioo-mcp`. It is here to be copied, not installed.

Everything in the base extension's [Extending](../extending.md) guide is applied here once,
in the order you would meet it.

## What is in it

| File | Why it exists |
|---|---|
| `module.acme-servicedesk.php.tpl` | The iTop module declaration. **Ships with a `.tpl` suffix on purpose** — see below |
| `composer.json` | The autoloader settings that make discovery work, and the `mcp/sdk` question answered in a comment |
| `register.php` | One line, declaring the provider. Listed in the module's `datamodel` array |
| `datamodel.acme-servicedesk.xml` | The `MCP-toolset-acme-servicedesk` token scope, and the title dictionary |
| `src/AcmeServiceDeskExtensions.php` | The provider: the version guard, the registrations, the server instructions |
| `src/Tools/TicketAddLogEntry.php` | A write tool: annotations, dry run, `RestValue`, per-object `UserRights` |
| `src/Tools/TicketSearchMine.php` | A read tool extending `AbstractObjectSearch`, so paging comes for free |
| `src/Prompts/TriageMyQueue.php` | A prompt, and `isAvailable()` keeping it off instances where it would fail |
| `tests/php-unit-tests/Unit/ContractTest.php` | The whole contract suite, needing neither iTop nor a database |
| `phpunit.xml.dist` | PHPUnit 9, matching what iTop pins. Points the suite at `tests/php-unit-tests/`, which is where iTop's own Extensions testsuite looks |
| `tests/php-unit-tests/bootstrap.php` | The test bootstrap `phpunit.xml.dist` names — the autoloader, without iTop |

## Using it

```bash
cp -r doc/example-pack /path/to/itop/web/extensions/acme-servicedesk
cd /path/to/itop/web/extensions/acme-servicedesk
mv module.acme-servicedesk.php.tpl module.acme-servicedesk.php
composer install
```

Then rename `acme` to your own vendor name throughout — the namespace, the
toolset, the dictionary keys and the scope value — and run the iTop setup.

## The four things that are easy to get wrong

**1. The module file ships as `.tpl`.** iTop's setup walks every directory under
`extensions/` looking for `module.*.php` and `eval()`s each one it finds
(`setup/modulediscovery.class.inc.php`, `ListModuleFiles`); it does not skip
`doc/`. A live module file inside the base extension's documentation would put
this example in the module list of every instance that installs the base. Rename
it when you copy it out.

**2. The autoloader must produce a classmap.** iTop's `InterfaceDiscovery` — the
mechanism that finds your provider without you declaring it — enumerates
candidates by reading `env-<env>/<module>/vendor/composer/autoload_classmap.php`.
A PSR-4-only dump leaves that file essentially empty. `optimize-autoloader` and
`classmap-authoritative` in `composer.json` are what fill it, and
`vendor/autoload.php` must be the first entry of the module's `datamodel` array
so it is in place before `register.php` names a class.

**3. A toolset scope does not exist until your datamodel declares it.**
`getToolset()` gets you `mcp_enabled_toolsets`. It does *not* get you an
`MCP-toolset-acme-servicedesk` token scope: a scope is a value of the `scope`
enum on `PersonalToken` and `UserToken`, and one that is not declared there
cannot be selected on a token — and, because iTop honours a scope only when a
context tag of the same name was pushed before login, a token carrying an
undeclared scope cannot log in at all. `datamodel.acme-servicedesk.xml` is that
declaration. The base extension reads the enumeration rather than a list of its
own, precisely so that it does not need to know your name.

**4. Do not ship `mcp/sdk`.** It is a real runtime dependency of your code and it
is nevertheless in `require-dev`, because the base extension is what satisfies it
at runtime: it vendors the SDK and loads it from its own `datamodel` array, and
iTop includes a module's datamodel files after those of the modules it depends
on. Two copies in one PHP process resolve to whichever autoloader answered
first. Installing it and deleting `vendor/mcp` before packaging does **not** work
— `classmap-authoritative` has already written those classes into
`autoload_classmap.php`, so the entries outlive the files and the first call
fatals on a missing include. Pin the same constraint the base vendors,
`MCPHelper::SDK_CONSTRAINT`.

## Testing it

```bash
composer test
```

`ElementContract` runs the same checks the registry runs at boot, and returns
them instead of throwing, so the pack's whole contract suite is one assertion per
element. It pulls in no dev dependency of the base extension — that is why it
ships in the production autoload rather than under `tests/`.
