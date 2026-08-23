# Extending — writing a tool pack

Audience: someone writing an iTop extension that registers its own MCP tools, resources or
prompts into this one. If you are installing or operating the base extension, everything you
need is in the [README](../README.md) and you do not need this file.

[README](../README.md) ·
[Example pack](example-pack/) ·
[Changelog](../CHANGELOG.md) ·
[Client setup](clients.md)

---

An extension adds its own tools, resources, resource templates and prompts by implementing a
**service provider** and registering objects into the registry. It never has to touch this
module's code.

> **A working pack ships in this archive: [`doc/example-pack/`](example-pack/).** Two tools,
> a prompt, the module declaration, the composer settings, the datamodel delta and a contract
> test — everything below, applied once, in a directory you can copy into `extensions/`. If you
> are starting a pack, start by copying that and renaming `acme`.

## 1. Depend on the base module

In your own `module.<your-extension>.php`:

```php
'dependencies' => array(
    'altioo-mcp/1.0.0',
),
```

This is the version check that matters: the setup refuses the install and tells the
administrator why, which is a better place to find out than a log entry on the first request.
For the case that gets past it — a base extension downgraded under a pack already installed —
call `MCPHelper::RequireVersion('1.0.0', 'your-pack')` at the top of your provider. It throws,
and a provider that throws is logged and skipped on its own, so the failure mode is "this pack
is missing and the log says why" rather than a fatal error taking the endpoint down for every
other pack. `MCPHelper::AtLeast()` is the same question without the exception, for a pack that
would rather hide one element through `isAvailable()` and serve the rest.

**Your autoloader has to produce a classmap.** Not the composer default, and the failure is
silent. iTop's `InterfaceDiscovery` — the mechanism that finds a provider you did not declare —
enumerates candidate classes by reading `env-<env>/<module>/vendor/composer/autoload_classmap.php`.
A PSR-4-only dump leaves that file essentially empty, so nothing is discovered, and any class
the pack fails to autoload fails as "not found". Set both, and list the autoloader first in the
module's `datamodel` array so it is in place before `register.php` names a class:

```json
"config": { "optimize-autoloader": true, "classmap-authoritative": true }
```

**Do not ship `mcp/sdk` — put it in `require-dev`.** It is a genuine *runtime* dependency of
your code: your tools type-hint `Mcp\Schema\ToolAnnotations` and throw `Mcp\Exception\ToolCallException`,
and both have to resolve when a client calls them. `require-dev` is not a mislabelling of that,
it is where the dependency is satisfied from — this module vendors the SDK and loads it from
its own `datamodel` array, and iTop includes a module's datamodel files after those of the
modules it depends on, so `Mcp\*` is already registered by the time anything of yours is
autoloaded. Two copies in one process resolve to whichever autoloader answered first.

Installing it and deleting `vendor/mcp` before packaging does **not** work:
`classmap-authoritative` has already written those classes into `autoload_classmap.php`, so the
entries outlive the files and the first call fatals on a missing include. Pin the same
constraint this module vendors, published as `MCPHelper::SDK_CONSTRAINT`, so that what you
compile against is what will be loaded. The types that are part of the contract described here
are `Mcp\Schema\ToolAnnotations`, `Mcp\Schema\Annotations`, `Mcp\Exception\ToolCallException`
and `Mcp\Exception\ResourceReadException`.

**Versioning, and what it applies to.** The extension follows semver over the surface a pack
touches — and that surface is defined in the source rather than in this paragraph. **A class
you may depend on carries `@api` on its class docblock**, with an `@since` saying which version
it first appeared in. Everything else under `src/` is internal and may change in a patch.

```bash
grep -rl '@api' src/          # the definitive list, in the copy you have
```

In shape it is the element base classes you extend, the registry and the collector, the
provider interface, the helpers, and the contract checker — but read the tags rather than that
sentence, because the tags are what a test enforces and the sentence is not.

A breaking change to a tagged class is a major bump. A new optional hook with a default
implementation is a minor one. Removal comes at least one minor after a documented
`@deprecated` naming the replacement, and it appears in [the changelog](../CHANGELOG.md).
**Tool, resource and prompt identifiers are part of the same surface**: a client
configuration that allow-lists a tool by name, and any `mcp_disabled_tools` entry, breaks when
a name changes, so a rename is a major bump too.

The running version is `MCPHelper::VERSION` — the same string the server sends to clients in
`serverInfo`, and the same string in `extension.xml` and the module declaration. Guard against
an older base with `MCPHelper::RequireVersion()` or `MCPHelper::AtLeast()`, above.

## 2. Write a tool

Extend `AbstractMCPTool`. `getNamespace()` is yours to pick — a vendor or module name,
`[a-zA-Z0-9-]`, and not `core`, which belongs to this module. `getName()` defaults to the
class short name in `snake_case`, the shape every MCP server in the ecosystem uses and the
one a model has seen thousands of examples of. What the client sees and calls is the two
joined by `_`, so `acme` + `TicketAddLogEntry` is advertised as
`acme_ticket_add_log_entry`. That composition is `final`: an extension cannot put itself
back into the shared space by accident.

The parameter names of `execute()` **must match the properties of the input schema** you
declare: the server binds arguments by name, so a property with no matching parameter is
dropped and a mandatory parameter absent from `required` makes every call fail. Registration
checks this for you (see [What registration checks](#what-registration-checks)). The core
tools declare `execute()` static; an instance method works equally well.

```php
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

class TicketAddLogEntry extends AbstractMCPTool
{
    public function getNamespace(): string
    {
        return 'acme';
    }

    public function getTitle(): ?string
    {
        return 'Add a log entry to a ticket';
    }

    public function getDescription(): ?string
    {
        return 'Append an entry to the public or private log of a ticket.';
    }

    public function getInputSchema(): ?array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id'      => ['type' => 'integer', 'description' => 'Ticket ID.'],
                'message' => ['type' => 'string',  'description' => 'Text to append.'],
            ],
            'required' => ['id', 'message'],
        ];
    }

    public function getAnnotations(): ?ToolAnnotations
    {
        return new ToolAnnotations($this->getTitle(), false, false, false, false);
    }

    public static function execute(int $id, string $message): mixed
    {
        // Always go through UserRights / MetaModel — never raw SQL.
        // Throw ToolCallException for anything the caller should be told.

        return ToolOutput::Json(['id' => $id, 'added' => true]);
    }
}
```

**Annotate your tools.** `getAnnotations()` is optional to PHP and not optional in practice:
`readOnlyHint` and `destructiveHint` are what grade a tool as read, write or delete, and a
tool that declares nothing is graded `delete` — withheld from every token scoped with
`MCP-read` or `MCP-write`, and from any instance running with `mcp_capabilities`. The
symptom is a tool that works for an administrator and is invisible to everyone else.

**A tool that writes should take a `comment`.** Attribution itself is not yours to remember —
every change made during a request already carries the tool that made it, yours included, set
before any tool runs. What a pack has to offer is the *why*, and there is one spelling of it
so that a model does not meet three:

```php
use Altioo\iTop\Extension\MCP\Helper\ChangeTracking;

// In getInputSchema(), beside 'simulate':
'comment' => ChangeTracking::CommentSchemaProperty('the entry is being added'),

// In execute(), immediately before the DBUpdate()/DBInsert()/DBDelete():
ChangeTracking::Explain($comment);
```

Call it before the write and not after — iTop builds the change record from what was last
said, at the moment the write happens. Call it once per batch rather than per object: one
call is one decision, and the objects it touches share one record. On a dry run it costs
nothing, since nothing is written for it to describe.

**Return `ToolOutput::Json()` rather than an array.** Both work, but an array is JSON-encoded
into the text content *and* copied into `structuredContent`, pretty-printed — the whole result
twice, in a response a model pays for by the token. Returning a `TextContent` takes both
branches away. If you do want structured content, declare `getOutputSchema()` so that the
duplicate is at least validated against something.

**Declare a `getToolset()`** if your pack has more than one kind of tool in it. It defaults to
your namespace, which lets an operator turn the pack on or off as a whole; naming groups lets
them turn on the half they use, through `mcp_enabled_toolsets`.

**A toolset token scope needs a line of datamodel as well.** `getToolset()` alone gets you the
configuration setting and nothing else — it does *not* give you an `MCP-toolset-<name>` token
scope. A scope is a value of the `scope` enum on `PersonalToken` and `UserToken`: one that is
not declared there cannot be selected when a token is created, and because iTop honours a scope
only when a context tag of the same name was pushed before login, a token carrying an
undeclared scope **cannot log in at all**. So a pack that wants its toolset grantable per
credential ships the delta itself:

```xml
<class id="PersonalToken" _delta="if_exists">
  <fields>
    <field id="scope" _delta="if_exists">
      <values>
        <value id="MCP-toolset-acme-servicedesk" _delta="define">
          <code>MCP-toolset-acme-servicedesk</code>
        </value>
      </values>
    </field>
  </fields>
</class>
```

Both token classes, since either kind can carry it. This module reads the declared enumeration
rather than a list of its own — `TokenScopes::DeclaredContextTags()` — precisely so that it
needs to know nothing about your name. [`doc/example-pack/datamodel.acme-servicedesk.xml`](example-pack/datamodel.acme-servicedesk.xml)
is the whole file, dictionary entries included.

Two optional hooks, available on **all four kinds** — tools, resources, resource templates
and prompts — and applied to all four when the server is built:

- `isAvailable()` — return `false` to hide the element entirely, e.g. when a module it depends
  on is not installed on this instance.
- `requiredProfiles()` — return a list of profiles the caller must hold for the element to be
  advertised and served. An empty list (the default) means it is offered to everyone who got
  through the endpoint gates. This is a *visibility* filter on top of `UserRights`, not a
  replacement for it.

`requiredProfiles()` is an **AND**: the caller must hold *every* profile listed. That is the
opposite of the endpoint gate `mcp_allowed_profiles`, which is an OR, and the difference is in
the words — *allowed* means any of these lets you in, *required* means all of these are needed.
For any-of semantics on a single element, override `isAvailable()` and test the profiles
yourself.

Writing values back to iTop, from a tool of your own:

```php
use Altioo\iTop\Extension\MCP\Helper\RestValue;

$realValue = RestUtils::MakeValue($sClass, $sAttCode, RestValue::FromDecodedJson($value));
```

The MCP SDK decodes inbound JSON with `json_decode($input, true)`, so a nested JSON object
reaches you as a PHP array — while `RestUtils` branches on `stdClass` to tell search criteria
from an id, and a caselog append from a plain string. Without that call the wrong branch is
taken silently. Scalars pass through untouched and JSON lists stay arrays, which is what link
sets and tag sets need.

**Two base classes worth extending instead of `AbstractMCPTool`**, both under `Abstract/` and
both covered by the versioning policy above:

| Extend | When | What you stop having to write |
|---|---|---|
| `AbstractObjectSearch` | Your tool returns a set of objects | Paging, ordering, field selection, and `has_more` / `next_offset` |
| `AbstractBulkTool` | Your tool acts on a list of ids | The bulk right check, the per-object right check, id validation, the dry run |

Neither declares a namespace — that is yours, and the registry refuses `core` from anything
outside this module. The reason to prefer them over copying is not brevity in either case.
`AbstractObjectSearch` knows that object-level rights remove rows from a page *after* the
database counted them, so a short page is not the end of the set and a caller that reads it as
one stops early and silently. `AbstractBulkTool` knows that `UR_ACTION_BULK_MODIFY` is a
separate grant from `UR_ACTION_MODIFY`, and that the class-level answer is never the end of it.
A bulk tool that checks the class once and then loops is an escalation with a progress counter,
and it passes every test written by a developer whose own account holds both rights.

## Augmenting a core tool

`overrides()` replaces an identifier wholesale. The commoner want is smaller — the core tool is
right except for one thing — and the way to get it is to **subclass the core tool and declare
the override**, which works because the registry validates *your* schema against *your*
`execute()`, not against the parent's:

```php
class ObjectGet extends \Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet
{
    public function getNamespace(): string { return 'acme'; }

    /** Take over the identifier clients already use. */
    public function overrides(): ?string { return 'core_object_get'; }

    public function getInputSchema(): ?array
    {
        $aSchema = parent::getInputSchema();
        $aSchema['properties']['include_history'] = [
            'type' => 'boolean', 'default' => false,
            'description' => 'Also return the last ten changes to this object.',
        ];

        return $aSchema;
    }

    public static function execute(
        string $class, int $id, string $output_fields = '', bool $include_history = false
    ): mixed {
        $oResult = parent::execute($class, $id, $output_fields);

        return $include_history ? self::withHistory($oResult, $class, $id) : $oResult;
    }
}
```

Three rules make this hold. New parameters go **last and with defaults**, so the arguments the
model already sends still bind by name. The declared override is what stops the registry
treating your class and the core one as an accidental clash and withdrawing the name from both.
And `parent::execute()` returns a `TextContent`, not an array — post-processing means decoding
it, which `ToolOutput::Decode()` does.

What this does not give you is a change applied across *every* tool — redaction, an extra audit
field, a rate limit. There is no middleware pipeline; see [Limitations](../README.md#limitations).

## Translating what a person reads

`getTitle()` on all four kinds resolves through iTop's dictionary, falling back to what
`defaultTitle()` returns. So override `defaultTitle()` with the English literal, and add
dictionary entries if you want it translated:

```php
protected function defaultTitle(): string { return 'Add a Log Entry to a Ticket'; }
```

```xml
<entry id="MCP:tool:acme_ticket_add_log_entry:title"><![CDATA[Add a Log Entry to a Ticket]]></entry>
```

The key is `MCP:<kind>:<qualified name>:title`, with `<kind>` one of `tool`, `resource`,
`resource_template` or `prompt`; `titleDictionaryKey()` returns it if you would rather not
spell it. A key nobody wrote falls back silently, so a pack that ships no dictionary reads
exactly as it did before.

**Descriptions are deliberately not translated.** A title is read by a person; a description is
read by the model deciding whether to call the tool. English is what those models have seen
thousands of examples of, and a description that changed with the caller's language would change
what the model does — a French-speaking user and an English-speaking one would get different
tool selection from the same instance. Write descriptions in English and leave them there.

## Testing your pack

`ElementContract` runs the same checks the registry runs at boot and returns them instead of
throwing, so a pack's whole contract suite is one assertion per element:

```php
use Altioo\iTop\Extension\MCP\Testing\ElementContract;

self::assertSame([], ElementContract::Violations(new TicketAddLogEntry()));
```

It needs neither iTop nor a database, and it references no dev dependency of this module —
which is why it ships in the production autoload rather than under `tests/`, where
`autoload-dev` and iTop's own path exclusions would both put it out of your reach. Nothing that
serves a request refers to it, and the autoloader is a classmap, so an instance that never calls
it never reads the file.

Alongside the refusals it reports the things that register cleanly and then disappoint — chief
among them a tool with no `getAnnotations()`, which works for an administrator and is invisible
to every scoped token. `MCPRegistry::Check()` is the underlying single-shot version, if you want
the exception rather than the list.

## 3. Write a resource or a prompt

Resources extend `AbstractMCPResource` and expose `read()`; resource templates extend
`AbstractMCPResourceTemplate` and take the URI variables as arguments; prompts extend
`AbstractMCPPrompt` and expose `get()`.

The URI is assembled from `getResourceNamespace()` and `getResourcePath()` as
`itop://<namespace>/<path>`. **Use your own namespace** — `core` belongs to this module.
That is the same namespace tools and prompts declare through `getNamespace()`; resources
had it first, and it is why they never had the collision problem.

```php
class MyThing extends AbstractMCPResource
{
    protected function getResourceNamespace(): string { return 'acme'; }
    protected function getResourcePath(): string      { return 'things'; }
    public function read(): mixed { return json_encode([...]); }
}
```

## 4. Register everything from a service provider

```php
use Altioo\iTop\Extension\MCP\Contract\iMCPServiceProvider;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;

class AcmeMCPExtensions implements iMCPServiceProvider
{
    public static function RegisterServiceProvider(): void
    {
        MCPRegistry::RegisterTool(new TicketAddLogEntry());
        MCPRegistry::RegisterResource(new MyThing());
        // RegisterResourceTemplate() and RegisterPrompt() likewise
    }
}
```

Providers are collected once at the start of every MCP request. The documented way to declare
yours is to call `MCPExtensionCollector::RegisterServiceProvider(AcmeMCPExtensions::class)`
from a file listed in the `datamodel` array of your module declaration — exactly what this
module's own `register.php` does:

```php
'datamodel' => array(
    'vendor/autoload.php',
    'register.php', // calls MCPExtensionCollector::RegisterServiceProvider(...)
),
```

Providers are also discovered automatically through iTop's `InterfaceDiscovery` (3.0+, cached),
so a class implementing `iMCPServiceProvider` is usually found without being declared. That
mechanism ignores anything under `/vendor/`, `/lib/`, `/test/`, `/tests/` and `/node_modules/`,
so put your provider in your module's `src/`. Declaring it explicitly always works and does not
depend on where the file lives; a provider that is both declared and discovered still runs
exactly once.

A provider that throws is logged and skipped — one broken pack does not take the endpoint down
for the others.

## What registration checks

`MCPRegistry` validates each object as it is registered, and throws `MCPRegistrationException`
naming your class and the defect. This is the boot-time half of the contract the abstracts
cannot express: an abstract method forces a method to *exist*, not to agree with the schema
declared next to it. Every one of these otherwise stays invisible until a client calls the
element, and then surfaces as an empty listing or an unrelated error from inside the SDK.

| Kind | Checked |
|---|---|
| all | `getToolset()` matches `[a-zA-Z0-9-]{1,64}`; `getNamespace()` matches `[a-zA-Z0-9-]{1,64}` and is not the reserved `core` unless you *are* core; the composed identifier matches `[a-zA-Z0-9_-]{1,128}`; `overrides()` returns null or another element's identifier, never its own; `requiredProfiles()` returns a list of non-empty strings; the handler (`execute()` / `read()` / `get()`) exists and is public |
| tools | non-empty `getDescription()`; input schema is a JSON Schema of type `object`; every `required` entry is declared under `properties`; every property maps to a parameter of `execute()`; every parameter of `execute()` without a default is listed under `required` |
| resources | the URI carries no `{variable}` |
| resource templates | the URI template carries at least one `{variable}`, and each one matches a parameter of `read()` |

`MCPRegistry::Check()` runs exactly these against one element without registering it, and
[`ElementContract`](#testing-your-pack) wraps it for a test suite — so a pack can meet all of
this in CI rather than on an instance.

## Who owns an identifier

Namespacing makes a collision between two unrelated packs unlikely, not impossible — two
vendors can still pick the same namespace. When one identifier is claimed by two different
classes, the registry does not pick a winner:

- **Same class registered twice** — nothing happens. That is what a provider both declared in
  `register.php` and found by discovery looks like.
- **One of them declares `overrides()`** — it takes the identifier, whichever order the two
  registered in, and the replacement is written to the log. This is how you deliberately
  replace a core tool: return `'core_object_delete'` from `overrides()` and keep the name the
  clients already use. It is the only way to claim an identifier that is not yours.
- **Neither declares anything** — an accident, and it is treated as one. The identifier is
  **withdrawn**: it is served to nobody, and every class that claimed it is logged. The
  alternative, awarding it to whoever loaded last, would have a client call the tool whose
  description it was shown and run a different vendor's code. The rest of both packs is
  unaffected; only the disputed name goes.

The operator resolves a withdrawal with `mcp_disabled_tools`, which accepts a class name for
exactly this reason — the identifier no longer tells the two apart, and the class does.

## What the framework guarantees you

- Authentication, the profile gate, token scope checking and CORS are handled before your code
  runs. Inside `execute()` / `read()` / `get()` you have an authenticated iTop session.
- Exceptions are caught at the entry point. `ToolCallException` and `ResourceReadException`
  messages reach the client; anything else is answered generically and correlated to
  `log/error.log` by a reference, so internal detail (SQL, class names) does not leak.
- Your call is audited as an `AltiooEventMCPService` under the same rules as the core tools.
- Your element is registered only if it honours the contract above, and only if the operator
  has not disabled it through `mcp_disabled_tools`.

What it does **not** do for you: enforce your business rules. Check `UserRights` for anything
you read or write, and mask what should not be shown.
