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
| `ObjectSearchByOQL` | Search objects with an OQL query |
| `ObjectSearchByClass` | Search objects of a class by attribute criteria |
| `ObjectGet` | Retrieve a single object by class and ID |
| `ObjectGetRelated` | Walk a named relation (impacts, depends on…) for impact analysis |
| `ObjectCreate` | Create an object |
| `ObjectUpdate` | Update an object's attributes |
| `ObjectApplyStimulus` | Apply a lifecycle stimulus (state transition) |
| `ObjectDelete` | Delete an object, reporting its deletion plan |

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
| `MyOpenTickets` | Summarise the current user's open tickets |

Because the schema is read live from `MetaModel`, whatever your datamodel customisations add
— your classes, your attributes, your states — shows up without any extra configuration.

### Transport and protocol support

Streamable HTTP, over a single endpoint, stateless: each request is authenticated on its own
and no server-side session is carried between requests. SSE streaming and resumability are not
supported. Authentication is by iTop token; OAuth is not wired up.

## Installation

1. Unzip the extension into your iTop `extensions/` directory, so that you get
   `<itop>/extensions/altioo-mcp/`.
2. Run the iTop setup (`<itop-url>/setup/`) and tick **Altioo MCP iTop Extension** in the
   list of extensions.
3. Complete the setup so the datamodel is compiled.

The archive ships its own `vendor/` directory — do **not** run `composer install` on a
production instance.

## Granting access

Access is gated three times, and every gate must pass.

**1. A profile.** By default only users holding **Administrator** or **MCP Services User**
may reach the endpoint. `MCP Services User` is created by this extension; like iTop's own
`REST Services User`, it grants no data rights of its own — it only marks a user as allowed
through. What the client can actually read or write still comes from the user's other
profiles, so grant it alongside a functional profile, never on its own.

Change the allowed list with the `mcp_allowed_profiles` module parameter (below), or set
`secure_mcp_services` to `false` to drop the profile check entirely.

**2. A token with the MCP scope.** Create a Personal Token (My Account → Personal Tokens) or
a User Token, tick the **MCP** scope, and give it to the client. This extension adds that
scope to iTop's token classes, so a token minted for REST/JSON or for Export cannot be
replayed against the MCP endpoint, and vice versa.

**3. iTop's own permissions.** Every operation goes through `UserRights` — class rights,
object-level rights, per-attribute read and write rights, and stimulus rights. Sensitive
attributes are masked in output.

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

## Configuration

All settings live under the `altioo-mcp` module in `conf/<env>/config-itop.php`:

```php
'altioo-mcp' => array(
    'secure_mcp_services' => true,
    'mcp_allowed_profiles' => array('Administrator', 'MCP Services User'),
    'mcp_allowed_origins' => array(),
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

### 2. Write a tool

Extend `AbstractMCPTool`. `getName()` defaults to the class short name, and that is what the
client sees — pick something unlikely to collide with another extension's tool.

`execute()` **must be static**, and its parameter names must match the properties of the
input schema you declare: the server binds arguments by name.

```php
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

class TicketAddLogEntry extends AbstractMCPTool
{
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

Two optional hooks on every tool:

- `isAvailable()` — return `false` to hide the tool entirely, e.g. when a module it depends on
  is not installed on this instance.
- `requiredProfiles()` — return a list of profiles the caller must hold for the tool to be
  advertised and callable. An empty list (the default) means the tool is offered to everyone
  who got through the endpoint gates. This is a *visibility* filter on top of `UserRights`,
  not a replacement for it.

### 3. Write a resource or a prompt

Resources extend `AbstractMCPResource` and expose `read()`; resource templates extend
`AbstractMCPResourceTemplate` and take the URI variables as arguments; prompts extend
`AbstractMCPPrompt` and expose `get()`.

The URI is assembled from `getResourceNamespace()` and `getResourcePath()` as
`itop://<namespace>/<path>`. **Use your own namespace** — `core` belongs to this module.

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

Providers are collected at the start of every MCP request. For yours to be found, its class
has to be loaded — the simplest way is to list the file in the `datamodel` array of your
module declaration, next to your own autoloader:

```php
'datamodel' => array(
    'vendor/autoload.php',
    'src/AcmeMCPExtensions.php',
),
```

Alternatively, call `MCPExtensionCollector::RegisterServiceProvider(AcmeMCPExtensions::class)`
from code that already runs at startup.

### What the framework guarantees you

- Authentication, the profile gate, token scope checking and CORS are handled before your code
  runs. Inside `execute()` / `read()` / `get()` you have an authenticated iTop session.
- Exceptions are caught at the entry point. `ToolCallException` and `ResourceReadException`
  messages reach the client; anything else is answered generically and correlated to
  `log/error.log` by a reference, so internal detail (SQL, class names) does not leak.
- Your call is audited as an `EventMCPService` under the same rules as the core tools.

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
