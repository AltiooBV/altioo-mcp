# Altioo MCP — Model Context Protocol server for iTop

Turns an iTop instance into an MCP server, so assistants (Claude, and any other Model
Context Protocol client) can search, read and update CMDB and ticketing objects.

This is the **base extension**. It is free, it works on its own, and it is designed to be
depended upon: other extensions declare it as a dependency and register their own tools,
resources and prompts into it. Nothing here is specific to a business domain — the core
surface is a thin, safe mapping of iTop's own object model.

Everything runs **inside iTop**, against `MetaModel` and `UserRights` directly. There is no
separate service to deploy, no copy of the datamodel to keep in sync, and no REST hop: a
client can never see or change more than the authenticated user could through the console,
down to individual attributes and lifecycle stimuli.

## Requirements

| | |
|---|---|
| iTop | **3.2 (current LTS)** or **3.3** |
| PHP | **8.2 – 8.4** |
| iTop modules | `authent-token` 2.2.1 or later (ships with iTop) |

The PHP range is the intersection of what those iTop branches support — 3.3 requires 8.2 as a
floor, and 8.4 is the newest PHP any of them validates. Note that iTop enforces its own ceiling
too: **3.2.0–3.2.2 do not support PHP 8.4** (known issues), so on those you need 8.2 or 8.3.
PHP 8.4 becomes available from iTop 3.2.3-1 onwards.

## What it exposes

The core surface stays close to iTop's own primitives, in the same spirit as its REST/JSON
API: generic operations over any class, rather than one tool per business object. Task-shaped
tools ("open an incident", "add a work note", "find the caller") are deliberately **not** here
— they belong in extensions built on top of this one (see [Extending](#extending)).

**Tools**

| Tool | Purpose |
|---|---|
| `core_ObjectSearchByOQL` | Search objects with an OQL query |
| `core_ObjectSearchByClass` | Search objects of a class by attribute criteria |
| `core_ObjectGet` | Retrieve a single object by class and ID |
| `core_ObjectGetRelated` | Walk a named relation (impacts, depends on…) for impact analysis |
| `core_ObjectCreate` | Create an object |
| `core_ObjectUpdate` | Update an object's attributes |
| `core_ObjectApplyStimulus` | Apply a lifecycle stimulus (state transition) |
| `core_ObjectDelete` | Delete an object, reporting its deletion plan. Dry run by default (`simulate: true`) |

Names are qualified by the namespace that owns them, the way resource URIs already
are — `core` belongs to this module, an extension uses its own. Two packs from two
vendors therefore cannot claim one identifier by both calling a class `TicketAddLogEntry`.

**Resources**

| URI | Content |
|---|---|
| `itop://core/version` | iTop version and edition |
| `itop://core/current-user` | The authenticated user and their profiles |
| `itop://core/classes` | The list of classes in the datamodel |
| `itop://core/class/{class}` | One class in detail: attributes, relations, lifecycle |

**Prompts**

| Prompt | Purpose |
|---|---|
| `core_MyOpenTickets` | Summarise the current user's open tickets |

Because the schema is read live from `MetaModel`, whatever your datamodel customisations add
— your classes, your attributes, your states — shows up without any extra configuration.

### Transport and protocol support

Streamable HTTP, over a single endpoint, stateless: each request is authenticated on its own
and no server-side session is carried between requests. SSE streaming and resumability are not
supported. Authentication is delegated to iTop itself — see [Granting access](#granting-access).

## Installation

1. Unzip the extension into your iTop `extensions/` directory, so that you get
   `<itop>/extensions/altioo-mcp/`.
2. Run the iTop setup (`<itop-url>/setup/`) and tick **Altioo MCP iTop Extension** in the
   list of extensions.
3. Complete the setup so the datamodel is compiled.

The archive ships its own `vendor/` directory — do **not** run `composer install` on a
production instance.

## Granting access

Access is gated by a profile, by a credential, and by iTop's own permissions. Every gate that
applies must pass.

**1. A profile.** By default only users holding **Administrator** or **MCP Services User**
may reach the endpoint. `MCP Services User` is created by this extension; like iTop's own
`REST Services User`, it grants no data rights of its own — it only marks a user as allowed
through. What the client can actually read or write still comes from the user's other
profiles, so grant it alongside a functional profile, never on its own.

Change the allowed list with the `mcp_allowed_profiles` module parameter (below), or set
`secure_mcp_services` to `false` to drop the profile check entirely.

**2. A credential iTop accepts.** Authentication is delegated to iTop's login stack
(`LoginWebPage::DoLogin()`), so any login mode iTop can perform **non-interactively** works
here, subject to your `allowed_login_types`:

- **A Personal or User token** (recommended). Create one under My Account → Personal Tokens,
  tick the **MCP** scope, and give it to the client. This extension adds that scope to iTop's
  token classes, so a token minted for REST/JSON or for Export cannot be replayed against the
  MCP endpoint, and vice versa.
- **Basic authentication**, exactly as for iTop's REST/JSON API. Scopes are a property of token
  objects, so none applies here — the profile gate and iTop's permissions are what protect the
  endpoint.
- **`external` mode**, where a reverse proxy authenticates the caller and passes `REMOTE_USER`.
  This is where OAuth/OIDC belongs: terminate it in the web server (`mod_auth_openidc`,
  `oauth2-proxy`) and let iTop consume the result. The extension carries no OAuth code of its
  own, and needs none.

Browser-redirect modes (CAS, `combodo-hybridauth`) cannot serve this endpoint — a headless MCP
client has no way to follow a redirect to an identity provider — and neither can a session
cookie, since the endpoint resets the session on every request.

**3. iTop's own permissions.** Every operation goes through `UserRights` — class rights,
object-level rights, per-attribute read and write rights, and stimulus rights. Sensitive
attributes (those whose type implements `iAttributeNoGroupBy`) are masked in output.

On top of those three, `mcp_disabled_tools` lets you turn individual tools, prompts and
resources off outright, by name or by class, whichever extension registered them.

## Endpoint

```
https://<your-itop>/extensions/altioo-mcp/index.php
```

Point your MCP client at that URL and authenticate with the token above:

```json
{
  "mcpServers": {
    "itop": {
      "type": "http",
      "url": "https://<your-itop>/extensions/altioo-mcp/index.php",
      "headers": { "Authorization": "Bearer <your-itop-token>" }
    }
  }
}
```

`Auth-Token: <your-itop-token>` works too, and is the header iTop's own `authent-token` module
reads natively. Prefer it if `Authorization` never reaches PHP in your deployment: under
FastCGI, Apache drops that header unless `CGIPassAuth On` (or an equivalent
`SetEnvIf Authorization` rewrite) is in effect.

MCP clients that offer only a "Connect" button, with no field for a credential, expect the
server to advertise OAuth discovery (RFC 9728). This extension does not, by design — put an
OAuth-terminating proxy in front of it if you need to serve such a client.

## Configuration

All settings live under the `altioo-mcp` module in `conf/<env>/config-itop.php`:

```php
'altioo-mcp' => array(
    'secure_mcp_services' => true,
    'mcp_allowed_profiles' => array('Administrator', 'MCP Services User'),
    'mcp_allowed_origins' => array(),
    'mcp_disabled_tools' => array(),
    'log_mcp_service' => true,
    'log_mcp_method' => array('resources/read', 'tools/call', 'prompts/get', 'exceptions'),
    'log_mcp_level' => 'error',
),
```

| Setting | Default | Effect |
|---|---|---|
| `secure_mcp_services` | `true` | When true, callers must hold one of `mcp_allowed_profiles`. Setting it to `false` opens the endpoint to every authenticated user |
| `mcp_allowed_profiles` | `Administrator`, `MCP Services User` | Profiles allowed through the endpoint |
| `mcp_allowed_origins` | *(empty)* | Browser origins allowed to read MCP responses. Empty sends no `Access-Control-Allow-Origin` header at all, which is what a token-authenticated endpoint called from a backend wants. Add entries only for browser-based clients you control, and never use `*` |
| `mcp_disabled_tools` | *(empty)* | Kill switch. List qualified tool or prompt names, resource URIs, or **class names** — e.g. `array('core_ObjectDelete', 'itop://core/current-user', 'Acme\\Tools\\TicketAddLogEntry')`. Anything listed is neither advertised nor callable, whichever extension registered it. The class form is what resolves a name clash between two packs, where the name no longer tells them apart |
| `log_mcp_service` | `true` | Write an `EventMCPService` audit entry per call |
| `log_mcp_method` | see above | Which MCP methods are audited |
| `log_mcp_level` | `error` | `error` logs failures only; `info` logs everything; `debug` additionally records the raw request parameters |

### Audit trail

Calls are recorded as **MCP Service Call** (`EventMCPService`) objects, visible in the
console. Each entry holds the MCP method, the tool or resource invoked, the outcome, and the
calling user.

> `log_mcp_level => 'debug'` stores the **raw JSON request parameters**, which may contain
> data your users would not expect to find in an audit log. Use it for troubleshooting, not
> as a standing setting.

## Extending

An extension adds its own tools, resources, resource templates and prompts by implementing a
**service provider** and registering objects into the registry. It never has to touch this
module's code.

### 1. Depend on the base module

In your own `module.<your-extension>.php`:

```php
'dependencies' => array(
    'altioo-mcp/1.0.0',
),
```

Do not bundle your own copy of `mcp/sdk`: this module ships it and loads it, and two copies
in one PHP process will collide. The types you may reference from it (`Mcp\Schema\ToolAnnotations`,
`Mcp\Schema\Annotations`, `Mcp\Exception\ToolCallException`, `Mcp\Exception\ResourceReadException`)
are part of the contract described here, pinned to the SDK version this module vendors.

**Versioning.** The extension follows semver, and the surface it applies to is what you touch
from a pack: the four abstracts, `MCPRegistry`, `MCPExtensionCollector`, `iMCPServiceProvider`
and the helpers under `Helper/`. A breaking change there is a major bump; a new optional hook
with a default implementation is a minor one. The running version is `MCPHelper::VERSION` —
the same string the server sends to clients in `serverInfo`.

### 2. Write a tool

Extend `AbstractMCPTool`. `getNamespace()` is yours to pick — a vendor or module name,
`[a-zA-Z0-9-]`, and not `core`, which belongs to this module. `getName()` defaults to the
class short name. What the client sees and calls is the two joined by `_`, so
`acme` + `TicketAddLogEntry` is advertised as `acme_TicketAddLogEntry`. That composition is
`final`: an extension cannot put itself back into the shared space by accident.

The parameter names of `execute()` **must match the properties of the input schema** you
declare: the server binds arguments by name, so a property with no matching parameter is
dropped and a mandatory parameter absent from `required` makes every call fail. Registration
checks this for you (see [What registration checks](#what-registration-checks)). The core
tools declare `execute()` static; an instance method works equally well.

```php
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
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
    }
}
```

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

### 3. Write a resource or a prompt

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

### 4. Register everything from a service provider

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

### What registration checks

`MCPRegistry` validates each object as it is registered, and throws `MCPRegistrationException`
naming your class and the defect. This is the boot-time half of the contract the abstracts
cannot express: an abstract method forces a method to *exist*, not to agree with the schema
declared next to it. Every one of these otherwise stays invisible until a client calls the
element, and then surfaces as an empty listing or an unrelated error from inside the SDK.

| Kind | Checked |
|---|---|
| all | `getNamespace()` matches `[a-zA-Z0-9-]{1,64}` and is not the reserved `core` unless you *are* core; the composed identifier matches `[a-zA-Z0-9_-]{1,128}`; `overrides()` returns null or another element's identifier, never its own; `requiredProfiles()` returns a list of non-empty strings; the handler (`execute()` / `read()` / `get()`) exists and is public |
| tools | non-empty `getDescription()`; input schema is a JSON Schema of type `object`; every `required` entry is declared under `properties`; every property maps to a parameter of `execute()`; every parameter of `execute()` without a default is listed under `required` |
| resources | the URI carries no `{variable}` |
| resource templates | the URI template carries at least one `{variable}`, and each one matches a parameter of `read()` |

### Who owns an identifier

Namespacing makes a collision between two unrelated packs unlikely, not impossible — two
vendors can still pick the same namespace. When one identifier is claimed by two different
classes, the registry does not pick a winner:

- **Same class registered twice** — nothing happens. That is what a provider both declared in
  `register.php` and found by discovery looks like.
- **One of them declares `overrides()`** — it takes the identifier, whichever order the two
  registered in, and the replacement is written to the log. This is how you deliberately
  replace a core tool: return `'core_ObjectDelete'` from `overrides()` and keep the name the
  clients already use. It is the only way to claim an identifier that is not yours.
- **Neither declares anything** — an accident, and it is treated as one. The identifier is
  **withdrawn**: it is served to nobody, and every class that claimed it is logged. The
  alternative, awarding it to whoever loaded last, would have a client call the tool whose
  description it was shown and run a different vendor's code. The rest of both packs is
  unaffected; only the disputed name goes.

The operator resolves a withdrawal with `mcp_disabled_tools`, which accepts a class name for
exactly this reason — the identifier no longer tells the two apart, and the class does.

### What the framework guarantees you

- Authentication, the profile gate, token scope checking and CORS are handled before your code
  runs. Inside `execute()` / `read()` / `get()` you have an authenticated iTop session.
- Exceptions are caught at the entry point. `ToolCallException` and `ResourceReadException`
  messages reach the client; anything else is answered generically and correlated to
  `log/error.log` by a reference, so internal detail (SQL, class names) does not leak.
- Your call is audited as an `EventMCPService` under the same rules as the core tools.
- Your element is registered only if it honours the contract above, and only if the operator
  has not disabled it through `mcp_disabled_tools`.

What it does **not** do for you: enforce your business rules. Check `UserRights` for anything
you read or write, and mask what should not be shown.

## Custom work

The core is free and AGPL, and it stays generic on purpose. Domain-specific tool packs — ITSM
workflows, your own datamodel extensions, integrations with the rest of your estate — sit on
top of it. Build your own with the extension points above, or have
[Altioo](https://github.com/altioo) build and maintain one for you.

## Security

The endpoint is a public HTTP entry point. Beyond the gates above, follow
[iTop's security guidance](https://www.itophub.io/wiki/page?id=latest:install:security) —
in particular serve iTop over HTTPS with HSTS, and set `session.cookie_secure`,
`session.cookie_httponly` and `zend.exception_ignore_args` in PHP.

Grant `MCP Services User` deliberately. An MCP client is driven by a language model acting
on instructions that may come from outside your organisation, so treat the profiles you pair
it with as the real blast radius — start read-only and widen only as needed. The same applies
to the tool packs you install on top: a tool can only do what the calling user could, which is
exactly why the pairing of profile and tool set is the thing to review.

## Development

```bash
composer install
composer test:unit
```

The unit suite needs neither iTop nor a database. The integration suite additionally needs a
live iTop with the module installed, and skips itself otherwise:

```bash
ITOP_ROOT=/path/to/itop/web composer test:integration
```

Build a release archive with `composer install --no-dev`; `exclude.txt` lists what is kept
out of the package.

## License

[AGPL-3.0-or-later](LICENSE), matching iTop itself.
